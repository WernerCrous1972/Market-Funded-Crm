# Docs/mcp-shim/

Reference copy of the MCP shim that connects Henry (OpenClaw ops AI) to
this CRM. The actual deployed shim lives at:

```
/Users/wernercrous/openclaw/mcp-servers/market-funded-crm/
```

This folder is documentation only — it is not run from here. If you
update the live shim, copy the changes back here so the CRM repo stays
the source of truth for what's deployed.

## Why a copy lives here

The shim is small (one file + package.json), it's specific to this CRM,
and a future maintainer reading the repo's git history shouldn't have
to dig into `~/openclaw/` to understand how Henry talks to the CRM.

## How it's wired up

1. The CRM exposes `/api/henry/*` HTTP endpoints (see
   `app/Http/Controllers/Api/HenryController.php` and `routes/api.php`).
2. The shim — `index.js` here — is a tiny Node.js process that registers
   four MCP tools (`health`, `search_people`, `get_person`,
   `book_metrics`) and forwards each call to the matching CRM endpoint.
3. OpenClaw spawns the shim via stdio. Configuration block in
   `~/.openclaw/openclaw.json` under `mcp.servers.market-funded-crm`.
4. Henry calls the tools natively, gets data back, replies to Werner
   on Telegram.

## Editing `~/.openclaw/openclaw.json`

**NEVER edit that file directly while the gateway is running.** It will
be overwritten within seconds by the gateway's own config save (this
caused 60+ "clobber" backups in late April 2026). Always use:

```bash
openclaw mcp set <name> '<json-object>'
openclaw mcp list
openclaw mcp show <name>
openclaw mcp unset <name>
```

The CLI goes through the gateway's own write logic, so there's no race.

## Not yet built

Henry's earlier review listed `post_event` and `pause_autonomous` as
tools he expects. They're NOT in this shim — the corresponding CRM
endpoints don't exist yet. They land later in Phase 4a (milestone 3 +
milestone 4). Add the tools to `index.js` then, alongside the new
endpoints.

## Local testing

From `~/openclaw/mcp-servers/market-funded-crm/`:

```bash
MFU_CRM_BASE_URL=http://localhost:8000 \
MFU_CRM_API_TOKEN=<the token from .env> \
npx @modelcontextprotocol/inspector --cli node index.js --method tools/list
```
