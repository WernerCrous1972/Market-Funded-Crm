#!/usr/bin/env bash
#
# sync_to_production.sh
#
# Runs the local MTR sync but writes to the production Postgres via an SSH
# tunnel. Used as a workaround while the production droplet's IP is blocked
# by Cloudflare from reaching the MTR API directly.
#
# Flow:
#   1. Load .env.local-to-prod (production DB creds + SSH tunnel config)
#   2. Open SSH tunnel: localhost:5433 -> prod:127.0.0.1:5432
#   3. Run `php artisan mtr:sync` with DB_CONNECTION=pgsql_prod
#   4. Always close the tunnel (trap on EXIT)
#   5. Exit with the sync's exit code
#
# Usage:
#   scripts/sync_to_production.sh                # full sync (default)
#   scripts/sync_to_production.sh --dry-run      # what would happen, no writes
#   scripts/sync_to_production.sh --incremental  # only changes since last sync
#
# Logs land in storage/logs/sync-to-prod/YYYY-MM-DD.log.
#
# Retire this script when production droplet gets Cloudflare-whitelisted or
# when we move sync to a region-different VPS relay.

set -euo pipefail

# Resolve project root from script location
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

ENV_FILE="$PROJECT_ROOT/.env.local-to-prod"
LOG_DIR="$PROJECT_ROOT/storage/logs/sync-to-prod"
LOG_FILE="$LOG_DIR/$(date +%Y-%m-%d).log"

mkdir -p "$LOG_DIR"

# Tee all output to the log file from here on
exec > >(tee -a "$LOG_FILE") 2>&1

echo ""
echo "================================================================"
echo "MTR sync to production — $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "================================================================"

# ─── 1. Load credentials ─────────────────────────────────────────────────────
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: $ENV_FILE not found. This file holds production DB creds and"
  echo "       must exist before sync can run. See plan or BRAIN wiki for setup."
  exit 1
fi

# shellcheck disable=SC1090
set -a
source "$ENV_FILE"
set +a

# Sanity: required vars present?
for var in PROD_DB_HOST PROD_DB_PORT PROD_DB_DATABASE PROD_DB_USERNAME PROD_DB_PASSWORD \
           PROD_SSH_HOST PROD_SSH_USER PROD_SSH_KEY \
           PROD_SSH_LOCAL_PORT PROD_SSH_REMOTE_HOST PROD_SSH_REMOTE_PORT; do
  if [[ -z "${!var:-}" ]]; then
    echo "ERROR: $var is not set in $ENV_FILE"
    exit 1
  fi
done

# ─── 2. Tunnel lifecycle ─────────────────────────────────────────────────────
TUNNEL_PID=""

cleanup() {
  local exit_code=$?
  if [[ -n "$TUNNEL_PID" ]] && kill -0 "$TUNNEL_PID" 2>/dev/null; then
    echo "[$(date '+%H:%M:%S')] Closing SSH tunnel (pid $TUNNEL_PID)..."
    kill "$TUNNEL_PID" 2>/dev/null || true
    wait "$TUNNEL_PID" 2>/dev/null || true
  fi
  echo "[$(date '+%H:%M:%S')] Exit code: $exit_code"
  exit $exit_code
}
trap cleanup EXIT INT TERM

# Refuse to run if something else is already on the local port (stale tunnel,
# manual ssh, dev Postgres on a non-default port, etc). Better to fail loud
# than silently write to whatever happens to be listening.
if lsof -iTCP:"$PROD_SSH_LOCAL_PORT" -sTCP:LISTEN -P -n >/dev/null 2>&1; then
  echo "ERROR: port $PROD_SSH_LOCAL_PORT is already in use on this machine."
  echo "       Kill the process holding it before re-running."
  lsof -iTCP:"$PROD_SSH_LOCAL_PORT" -sTCP:LISTEN -P -n
  exit 1
fi

echo "[$(date '+%H:%M:%S')] Opening SSH tunnel: localhost:${PROD_SSH_LOCAL_PORT} -> ${PROD_SSH_HOST}:${PROD_SSH_REMOTE_PORT}"

# -N: no remote command, -f: background, ExitOnForwardFailure: bail if forward fails
# ServerAliveInterval: keep tunnel alive across the long sync runs
ssh -i "$PROD_SSH_KEY" \
    -L "${PROD_SSH_LOCAL_PORT}:${PROD_SSH_REMOTE_HOST}:${PROD_SSH_REMOTE_PORT}" \
    -o "ExitOnForwardFailure=yes" \
    -o "ServerAliveInterval=30" \
    -o "ServerAliveCountMax=4" \
    -o "BatchMode=yes" \
    -N -f \
    "${PROD_SSH_USER}@${PROD_SSH_HOST}"

# Grab the PID of the tunnel we just spawned. -f detaches so the parent PID
# isn't ours; find it by the unique -L spec we used.
sleep 1
TUNNEL_PID=$(pgrep -f "ssh.*-L ${PROD_SSH_LOCAL_PORT}:${PROD_SSH_REMOTE_HOST}:${PROD_SSH_REMOTE_PORT}" | head -n1 || true)

if [[ -z "$TUNNEL_PID" ]]; then
  echo "ERROR: tunnel did not establish (no matching ssh process found)"
  exit 1
fi

echo "[$(date '+%H:%M:%S')] Tunnel established (pid $TUNNEL_PID)"

# ─── 3. Pre-flight: can Laravel reach prod through the tunnel? ──────────────
# Quick "are we actually talking to the right DB" check before kicking off
# a long sync run.
echo "[$(date '+%H:%M:%S')] Pre-flight: verifying connectivity..."
PREFLIGHT_OUTPUT=$(
  DB_CONNECTION=pgsql_prod php artisan tinker --execute "
    \$v = DB::connection('pgsql_prod')->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    \$c = DB::connection('pgsql_prod')->table('people')->count();
    echo 'pg_version=' . \$v . PHP_EOL;
    echo 'people_count=' . \$c . PHP_EOL;
  " 2>&1
)
echo "$PREFLIGHT_OUTPUT"
if ! echo "$PREFLIGHT_OUTPUT" | grep -q "pg_version="; then
  echo "ERROR: pre-flight connection check failed"
  exit 1
fi

# ─── 4. Run the sync ─────────────────────────────────────────────────────────
SYNC_ARGS="${*:---full}"
echo "[$(date '+%H:%M:%S')] Running: mtr:sync $SYNC_ARGS"
echo "================================================================"

DB_CONNECTION=pgsql_prod php -d memory_limit=2G artisan mtr:sync $SYNC_ARGS

SYNC_EXIT=$?
echo "================================================================"
echo "[$(date '+%H:%M:%S')] Sync exited with code $SYNC_EXIT"

# trap cleanup will close the tunnel and propagate the exit code
exit $SYNC_EXIT
