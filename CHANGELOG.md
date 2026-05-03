# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] — 2026-05-03

### Fixed

- **People filters silently no-op (root cause):** All filter callbacks used `$q` as the
  parameter name. Filament's `evaluate()` resolves closure parameters by name; the Builder
  is injected under the key `'query'`. Name mismatch caused fallback to
  `app()->make(Builder::class)` — a blank disconnected Builder that absorbed filter
  constraints without error. Renamed `$q` → `$query` throughout. (`1a499e0`, `5ce1c10`)

- **People list filter pipeline bypassed:** `ListPeople::getTableQuery()` override is a
  Filament v2 pattern that bypasses the Filament v3 filter application pipeline. Moved
  `->with('metrics')` eager-load to `->modifyQueryUsing()` on the table definition.
  (`0185921`)

- **`whereHas` failures in Filament filter context:** Laravel 11.51 + Filament v3 evaluates
  filter query callbacks via `evaluate()`, which can inject a blank Builder when the parameter
  name doesn't match. Calling `whereHas` on that blank Builder (no model set) threw "Call to
  a member function X() on null". Replaced all `whereHas` calls in filter callbacks with
  `whereIn` subqueries. (`65aea91`, `848b15e`)

- **Trading accounts and transactions not scoped to assigned clients:** Phase C
  `getEloquentQuery()` scoping was missing from `TradingAccountResource` and
  `TransactionResource`. Agents could see all records in list views. Added `person_id`
  subquery scoping to both. (`62b69f4`)

- **AtRiskClientsWidget not scoped to assigned clients:** Widget query had no branch or
  assigned_only scoping — a non-admin user with `can_view_health_scores` would see all
  at-risk clients across all branches. Added same scoping pattern as PersonResource.
  `whereHas` replaced with `whereIn` subquery for consistency.

- **RecentActivityWidget:** `whereHas` calls replaced with `whereIn` subqueries for
  consistency with project-wide pattern (functional behaviour unchanged — scoping logic
  was already correct).

### Notes

All 195 automated tests passed throughout. These bugs were not caught by the test suite
because the tests use super-admin users and assert HTTP 200 responses — they do not exercise
filter query application or agent-scoped widget rendering. The v1.2.0 Phase C smoke test
matrix (A–J) covered permission visibility and branch scoping on the People list, but did
not include filter interaction or dashboard widget rendering under an agent login. Future
smoke tests for permission releases should include: ticking each filter and verifying the
count changes, and loading the dashboard as an assigned-only agent.

### Not deployed

v1.2.1 is pushed to main but not yet deployed to production. No agents are active on
production; deploy when ready after full smoke test (Grace + Derick, all surfaces).

---

## [1.2.0] — 2026-05-03

### Deployed to production — 2026-05-03

Commit `747a4df` deployed via `deploy.sh` as `deployer` user. Both migrations ran on production.

**Bootstrap email bug (manually fixed):** Migration `2026_05_02_000001` hardcodes `where('email', 'werner@market-funded.com')` for the super-admin bootstrap step. Production CRM user was created with `werner.c@me.com` — bootstrap silently skipped. Manually fixed via interactive SSH tinker: `is_super_admin = true`, all 13 permission flags set, branch pivot rows inserted. **Next migration must not hardcode email — use env var or config.**

**Production database:** Empty pending Cloudflare MTR API whitelist. No people, transactions, or sync data on production yet. Phase B + C are live and correct but untestable against real data until the whitelist resolves.

**Phase C browser smoke test:** All 10 checks (A-J) passed — 2026-05-03. Two bugs found and fixed during smoke test (see commits `bc55f85`, `46e1116`). Both fixes deployed to production same session.

---

### Phase B + Phase C: Full permission system (161 → 194 tests)

### Built — Phase C: Permission enforcement (2026-05-02)

Enforces the Phase B permission model across the CRM UI. All views, widgets, and actions now respect user toggles and branch scoping. Not yet deployed — shipping with v1.1.0 alongside Phase B.

#### Database (migration `2026_05_02_000002`)
- `people.branch_id uuid nullable FK → branches.id` — ID-based branch FK (rename-safe). Retroactively populated from `branch` name string. Migration outputs backfill counts (total, % with branch_id, % with account_manager_user_id, orphan count).
- `people.account_manager_user_id uuid nullable FK → users.id` — UUID FK for assigned_only scoping. Retroactively populated by name match against users table.
- **Null `branch_id` fail-safe:** person with no resolved branch is invisible to all scoped users — correct interim state, not a data error. Resolves on next sync or explicit assignment. (See BRAIN.md §17.)

#### New files
- `app/Policies/PersonPolicy.php` — `view()` enforces branch access or assigned_only rule. Registered via `Gate::policy()` in AppServiceProvider. `Gate::before()` still bypasses for super admins.
- `tests/Feature/PhaseCPermissionsTest.php` — 14 tests

#### Updated files
- `app/Models/Person.php` — `branch_id` + `account_manager_user_id` added to `$fillable`, `branchModel()` + `accountManager()` relationships (named `branchModel` to avoid collision with existing `branch` string column)
- `app/Jobs/Sync/SyncAccountsJob.php` — populates `branch_id` (Branch FK from branchLookup) + `account_manager_user_id` (name→user lookup); logs debug warning when account_manager name has no CRM user match
- `app/Jobs/Sync/SyncOurChallengeBuyersJob.php` — `buildPersonData()` now includes `branch_id` + `account_manager_user_id`; `resolveBranchName()` refactored through new `resolveBranchModel()` helper; ghost records explicitly set both to null
- `app/Filament/Resources/PersonResource.php` — `getEloquentQuery()` applies branch/assigned_only scoping; financial and health table columns gated by `visible()`; Financial Summary + Health Score infolist sections gated by `visible()`; empty state messages differentiate "no branch access" from "no results"
- `app/Filament/Resources/PersonResource/Pages/ViewPerson.php` — `sendWhatsApp` gated on `can_send_whatsapp`; `addNote` gated on `can_make_notes`; `createTask` gated on `can_create_tasks`; new "Edit Contact" action (can_edit_clients = full form, can_assign_clients only = lead_status + account_manager mini form)
- `app/Filament/Widgets/StatsOverviewWidget.php` — stats 1–2 always visible; stats 3–8 (financial) conditionally included based on `can_view_branch_financials`
- `app/Filament/Widgets/GlobalDepositChartWidget.php` — `canView()` gated on `can_view_branch_financials`
- `app/Filament/Widgets/AtRiskClientsWidget.php` — `canView()` gated on `can_view_health_scores`
- `app/Filament/Widgets/RecentActivityWidget.php` — query branch-scoped: non-super-admin users see only activity for people in their branches (or their assigned contacts if `assigned_only`)
- `app/Filament/Widgets/PersonDepositChartWidget.php` — `canView()` gated on `can_view_client_financials`
- `app/Filament/Resources/EmailCampaignResource.php` — `canViewAny()` gated on `can_create_email_campaigns`
- `app/Providers/AppServiceProvider.php` — `Gate::policy(Person::class, PersonPolicy::class)` registered
- `database/factories/PersonFactory.php` — `branch_id` and `account_manager_user_id` added as nullable defaults
- `tests/Feature/Phase2Test.php` — Filament page test user updated to super admin (required for person detail with null branch_id)
- `tests/Feature/EmailCampaignTest.php` — Filament page test user updated to super admin (required for campaign pages with canViewAny gate)
- `tests/Feature/TaskQueueTest.php` — `is_due_today` test uses `today()->setHour(12)` instead of `now()->addHours(2)` to prevent UTC midnight crossing flakiness

#### Tests: 180 → 194 (+14)

#### Not yet built
- TransactionResource / TradingAccountResource person-level scoping
- `can_export` bulk action guard
- Note/task edit+delete ADMIN-only enforcement in UI

---

### Built — Phase B: Permission system foundation (2026-05-02)

Implements the full permission data model, user management UI, audit logging, and Gate infrastructure. Enforcement of permissions in the CRM views is Phase C (not yet built).

#### Database (single atomic migration `2026_05_02_000001`)
- 14 boolean permission columns added to `users` (all `NOT NULL DEFAULT false` — see BRAIN.md §17 for full list)
- `user_branch_access` pivot table (users ↔ branches, many-to-many, with `granted_at` + `granted_by`)
- `permission_audit_logs` table (immutable, no `updated_at`, index on `target_user_id, created_at`)
- `permission_templates` table + 7 starter templates seeded inline (Super Admin, Admin, Broker Partner, Master IB / IB / Sales Manager, Sales Agent assigned-only, Sales Agent full-branch, Viewer)
- Bootstrap data: Werner's `is_super_admin = true`, branch pivot rows for Market Funded + QuickTrade, one bootstrap audit log entry

#### New files
- `app/Models/PermissionTemplate.php` — 7-template catalog, `safeToggles()` helper strips `is_super_admin` for non-super-admin actors
- `app/Models/PermissionAuditLog.php` — immutable log model, `record()` factory helper, 6 `TYPE_*` constants
- `app/Observers/UserPermissionObserver.php` — watches `User::updated`, writes `TOGGLE_CHANGED` / `SUPER_ADMIN_GRANTED` / `SUPER_ADMIN_REVOKED` per changed column
- `app/Filament/Resources/UserResource.php` — Users & Permissions page, template picker (strips `is_super_admin` for non-super-admins), grouped toggle sections, branch CheckboxList, promote/revoke super admin table actions
- `app/Filament/Resources/UserResource/Pages/CreateUser.php` / `EditUser.php` / `ListUsers.php`
- `app/Filament/Resources/UserResource/RelationManagers/PermissionAuditLogRelationManager.php` — Permission History tab on user edit page
- `database/factories/BranchFactory.php`
- `tests/Feature/PhaseBPermissionsTest.php` — 19 tests

#### Updated files
- `app/Models/User.php` — 14 fillable + cast booleans, `branches()` BelongsToMany, `permissionAuditLogs()` HasMany, `hasBranchAccess()` helper
- `app/Models/Branch.php` — `usersWithAccess()` BelongsToMany
- `app/Providers/AppServiceProvider.php` — `Gate::before()` super admin bypass, `User::observe(UserPermissionObserver::class)`
- `database/factories/UserFactory.php` — 14 boolean defaults added
- `app/Console/Commands/MtrSync.php` — `ini_set('memory_limit', '1G')` at command start (fix for memory exhaustion on full sync)
- `app/Jobs/Sync/SyncOurChallengeBuyersJob.php` — removed 29k-account in-memory `$crmMap` pre-load; replaced with DB lookup + lazy `accountByEmail()` per ghost record (fix for 1GB memory exhaustion)
- `app/Services/MatchTrader/Client.php` — added `accountByEmail(string $email): ?array`

#### Tests: 161 → 180 (+19)

#### Bugs fixed during Phase B build
- `getRelationManagers()` → `getRelations()` (Filament v3 correct method name — wrong name silently ignored)
- `formatStateUsing(array $state)` → `(mixed $state)` in relation manager — Filament passes raw JSON string, not decoded array
- Memory exhaustion on `mtr:sync --full` — two fixes: `ini_set` in command + `$crmMap` pre-load removal in challenge buyers job

#### Not yet built (Phase C)
- Branch + `assigned_only` query scoping in PersonResource
- 403 on direct URL to inaccessible person
- `can_edit_clients` / `can_assign_clients` form enforcement
- `can_view_client_financials` section hiding on person detail
- `can_view_branch_financials` widget hiding on dashboard
- `can_view_health_scores` widget/column hiding
- `can_make_notes` / `can_send_whatsapp` / `can_send_email` action guards
- `can_export` bulk action guard
- `can_create_email_campaigns` nav hiding
- Note/task edit+delete ADMIN-only enforcement
- TransactionResource / TradingAccountResource scoping

---

### Fixed — deploy.sh permission walls (2026-05-02)

Resolved supervisorctl permission issue blocking non-root deploys. Added narrow sudoers exception for `deployer` user (`/etc/sudoers.d/deployer-supervisor`), modified deploy.sh to use `sudo -n supervisorctl restart all`. Full deploy.sh now runs cleanly end-to-end as deployer. One transient retry needed on first post-fix run (suspected interaction with prior view:cache step) — non-recurring.

Diagnostic: yesterday's "missing today's transactions" investigation traced to back-dated MTR records — gateways report deposits 3-6 days after actual transaction time. Sync is working correctly; the appearance of "no new data" was MTR records arriving with old timestamps. See BRAIN.md §13 for detail.

Commit: `a7a90cd`

---

### Deployed — Phase A: Branch column + account_manager filter (2026-05-01)

Phase A UI changes shipped to production. People list now shows Branch column by default (sortable). Account Manager promoted to top-level searchable filter. Transactions list gained Branch column (toggleable, hidden by default) with branch SelectFilter.

Code commits: `b3d05ae` (PersonResource + TransactionResource), `01b3aac` (docs).

**Deploy issues surfaced (not blockers, but need addressing):**
- `deploy.sh` doesn't currently handle non-root deploys cleanly. Four permission walls hit during first run as `deployer` user: git safe.directory, root-owned .git files (legacy from April 29 root deploy), untracked deploy.sh conflict, and supervisorctl socket permissions.
- Repo ownership reset to `www-data:www-data` with `775/664` permissions. `deployer` added to `www-data` group.
- `supervisorctl restart all` step in deploy.sh requires sudo. Currently deploy.sh's final step fails silently; workers must be restarted manually with `sudo supervisorctl restart all` after each deploy.

---

### Maintenance — System updates + kernel reboot (2026-05-01)

Applied 22 pending Ubuntu updates including kernel jump from 6.8.0-71 to 6.8.0-111. DigitalOcean snapshot taken before changes. Server rebooted cleanly. All services (Nginx, Postgres, Redis, Supervisor) auto-restarted. All CRM workers (Horizon, Reverb, Scheduler) verified running. Site confirmed 200 OK and Filament admin functional.

One package (`libgd3` from `ppa.launchpadcontent.net`) deferred — repo was unreachable from production. No functional impact; retry when repo is back online.

---

### Added — `deploy.sh` to git (2026-05-01)

The deploy script that lives at `/var/www/market-funded-crm/deploy.sh` on production was previously not version-controlled. Pulled into the repo with one enhancement: added `git config core.fileMode false` before `git pull origin main` to prevent the phantom permission diffs that blocked the 2026-04-29 deploy.

Script content (25 lines): `cd` into repo, set `core.fileMode false`, `git pull`, `composer install`, `npm ci && npm run build`, `php artisan migrate --force`, cache config/routes/views, `supervisorctl restart all`.

Committed (8bfa975) and pushed. Future deploys run as `bash deploy.sh` on the server. The server's copy will sync automatically on next real deploy.

---

### Hardened — Production SSH (2026-04-30)

Production server SSH fully hardened. Root login and password authentication both disabled; key-only access enforced via a new `deployer` user.

**What was done:**
- Generated Ed25519 key pair on Werner's Mac (`~/.ssh/mfu_production`, passphrase-protected).
- Installed public key for both `root` and new `deployer` user (UID 1001, full sudo).
- Set `PermitRootLogin no` and `PasswordAuthentication no` in `/etc/ssh/sshd_config`.
- **Ubuntu 24.04 gotcha:** `/etc/ssh/sshd_config.d/50-cloud-init.conf` was overriding the main config with `PasswordAuthentication yes`. Edited that file directly. Verified effective config via `sudo sshd -T`.
- Confirmed ufw active: OpenSSH, Nginx Full, port 8080 (Reverb — verify if needed).
- Public key added to DigitalOcean account-level SSH key store for future droplets.

**Attack data discovered during hardening:**
fail2ban was already installed (2 days). At time of hardening: 6,419 total failed SSH attempts, 1,212 unique IPs banned, 4 currently banned. The server was under sustained brute-force attack with root password auth previously exposed. Now closed.

**New connection:** `ssh -i ~/.ssh/mfu_production deployer@144.126.225.3`

**Open follow-ups from this session:**
- Port 8080 in ufw — assumed Reverb WebSocket, not yet confirmed. Remove if unused.
- 13 system updates pending (5 security), restart required — schedule maintenance window.

See BRAIN.md §16 for full details.

---

### Diagnosed — Production MTR API blocked at Cloudflare layer (2026-04-30)

The production droplet (`144.126.225.3`) reaches Match-Trader's API endpoint in ~68ms — connectivity is fine. However, HTTP 403 responses carry `server: cloudflare` and `cf-mitigated: challenge` headers, confirming the block is at Cloudflare's WAF/bot-protection layer, not Match-Trader's origin server.

Cloudflare challenges datacentre IPs (DigitalOcean ranges) by default. The same sync requests succeed from Werner's residential Mac IP. The whitelist must be applied as a Cloudflare IP Access Rule "Allow" for `144.126.225.3` — a standard origin firewall rule will not resolve it.

Follow-up sent to Match-Trader via the QuickTrade owner (who has the MTR technical contact). No code changes — external infrastructure dependency. Production sync remains blocked until Match-Trader applies the rule at the correct layer.

**Documented in BRAIN.md §13** — diagnostic command, headers to look for, and escalation path.

---

### Session summary — 29 April 2026

Full session covering local sync diagnosis, WhatsApp architecture decisions, scaffolding, production deployment, and Meta Business setup start.

#### Local sync (resolved, no code changes)
Production cron lives on the droplet — no scheduled sync on the Mac is expected behaviour. Werner ran `php -d memory_limit=1G artisan mtr:sync --incremental` locally and it completed cleanly.

#### WhatsApp architecture decisions (locked in — do not revisit)
- Direct Meta Cloud API (not BSP, not Twilio)
- One shared number for the brand
- Eight internal agent departments (client always sees "Market Funded")
- Manual send always available alongside autonomous
- Template required outside 24h service window; free-form within
- Unknown inbound numbers → warning log only, no auto-create
- `WA_FEATURE_ENABLED=false` default — production safe
- AI routing deferred to Phase 4 (`RouteToAgentListener` is a TODO stub)

#### Meta Business setup (in progress, partially blocked)
- Market Funded Business Manager portfolio created under Werner Crous (sole prop, Market Funded as brand — no CIPC registration)
- Developer registration blocked on Meta device-trust security cooldown (Market Funded email opens on a different device than the Facebook session)
- WhatsApp number, Business Verification, and template submission all await developer account clearance
- Tax docs / SARS letter being obtained for Business Verification
- New SIM reserved for Cloud API number

---

### Deployed — WhatsApp Business scaffolding to production (2026-04-29)

**Commit range:** `46a142e` → `cfdd0fd` (11 commits) — deployed to `crm.market-funded.com` (DigitalOcean Droplet, `root@144.126.225.3`).

#### What landed in production

- **3 new DB tables:** `whatsapp_templates`, `whatsapp_messages`, `agents` — migration `2026_04_29_000001_create_whatsapp_tables` ran cleanly.
- **8 agents seeded** via `AgentSeeder` (EDUCATION, DEPOSITS, CHALLENGES, SUPPORT, ONBOARDING, RETENTION, NURTURING, GENERAL). System prompts empty — Werner fills these when AI routing is configured.
- **Meta Cloud API integration scaffolded:** `MetaCloudClient`, `ServiceWindowTracker`, `MessageSender`, `SendWhatsAppMessageJob`, `ProcessWhatsAppWebhookJob`, `WhatsAppWebhookController`.
- **Feature flag OFF:** `WA_FEATURE_ENABLED` is absent from production `.env` — `config/whatsapp.php` defaults it to `false`. No messages can be sent. No risk of accidental sends.
- **Filament UI added:** WA Templates (CRUD), WA Messages (read-only log), Agents (edit prompts/toggle) — all under "WhatsApp" nav group.
- **Person detail page:** WhatsApp thread tab + Send WhatsApp header action (shows "Feature not yet active" while flag is off).
- **Webhook endpoint live:** `GET/POST /webhooks/whatsapp` — Meta verification challenge will work once `WA_WEBHOOK_VERIFY_TOKEN` is set in `.env`.
- **161 tests passing locally** before deploy (up from 133).

#### What does NOT work yet (by design)

- No messages can be sent — `WA_FEATURE_ENABLED=false`.
- Webhook POST events are rejected — `WA_APP_SECRET` not set, all POSTs return 401. Correct until Meta credentials are populated.
- AI agent routing — `RouteToAgentListener` is a TODO stub.

#### Activation checklist (do this when Meta approval arrives)

1. SSH to server, edit `.env`: populate `WA_PHONE_NUMBER_ID`, `WA_BUSINESS_ACCOUNT_ID`, `WA_ACCESS_TOKEN`, `WA_WEBHOOK_VERIFY_TOKEN`, `WA_APP_SECRET`.
2. Also explicitly add `WA_FEATURE_ENABLED=false` at this point (currently relying on config default — explicit is safer).
3. Register webhook URL `https://crm.market-funded.com/webhooks/whatsapp` in Meta Business Manager using `WA_WEBHOOK_VERIFY_TOKEN`.
4. Create first approved template in CRM (Admin → WhatsApp → WA Templates).
5. Set `WA_FEATURE_ENABLED=true` when first send is ready — bump version to `v1.1.0` at that point.

#### Deploy notes

- **`core.fileMode` drift fixed:** Server had `core.fileMode=true` causing 200+ phantom permission-changed diffs. Set to `false` during deploy — no code changes, cosmetic only.
- **`deploy.sh` on server but not in git:** Script exists at `/var/www/market-funded-crm/deploy.sh` but is unversioned. Worth pulling into the repo so deploys are repeatable and auditable.
- **No version bump:** Feature flag is off — no user-visible change. Version stays at `v1.0.0`. Tag `v1.1.0` on first real WhatsApp send.

---

### Fixed — Incremental sync date filter was silently ignored (2026-04-26)

`Client.php` was passing `dateFrom` as the query parameter for `/v1/deposits` and `/v1/withdrawals` incremental filtering. The MTR API silently ignores unknown parameters and returned the full dataset (37,196 deposits) on every incremental run — identical to a full pull.

**Verified via tinker:**
- `dateFrom=2026-04-25T00:00:00Z` → 37,196 rows (full dataset, filter ignored)
- `from=2026-04-25T00:00:00Z` → 86 rows (correct)

Fixed by renaming `dateFrom` → `from` in all four affected methods (`deposits()`, `allDeposits()`, `withdrawals()`, `allWithdrawals()`). Incremental sync (`mtr:sync --incremental`) now correctly fetches only transactions since the cutoff timestamp.

No data was lost — all transactions were imported on every run. Only bandwidth and runtime were wasted.

### Fixed — Deposit-side CP gap confirmed illusory (2026-04-26)

Werner exported all DONE deposits for April 2026 from MTR and applied classifier rules directly. Result: 24 qualifying rows in export, 24 in DB — exact match. The "~329 expected, ~254 missing" deposit-side gap was an estimation error, not a real gap.

Additional verification: a card-paid TTR challenge (`ZAR Card/Online EFT Payments PayGate`, `$200k TTR 1-Phase Challenge - Consistency`, 14 Apr 2026, $149.90) is correctly stored as `CHALLENGE_PURCHASE` in DB, confirming the offer-name-wins-over-gateway rule works regardless of payment method.

The "retired/archived challenges" theory from the earlier diagnostic is retracted. There are no retired challenges and no deposit-side classification gap.

**Corrected gap framing:**
- Deposit-side gap: **0** (estimation error)
- Withdrawal-side gap: **~93 rows** (cross-branch buyers not yet imported — partially recoverable via `SyncOurChallengeBuyersJob` on future syncs)
- Total real gap: ~93, not ~346

BRAIN.md §10 updated to reflect verified state.

### Added — Brand-First Challenge Buyer Import (2026-04-26)

#### Root cause resolved
354 our-brand prop/accounts (TTR/QT/MFU challenges) had no corresponding `TradingAccount` in our DB. Root split:
- **Group 1 (258):** Person IS in DB; prop trading account missing because `trading_accounts` only stores wallet accounts (sourced from deposits/withdrawals), not prop challenge accounts directly.
- **Group 2 (96):** Person NOT in DB at all — 77 never paid (no deposit = no import trigger), 19 paid and failed but their CRM account is on an excluded branch (e.g. PulseTrade). Their deposits were never imported because person lookup by email returned nothing.

Confirmed via diagnostic: `/v1/accounts/{uuid}` returns 405 (no per-record endpoint). No retired challenges — all 2,018 prop/accounts reference active challenges. The 282 INTERNAL_TRANSFER deposits are correctly classified (offer names all point to live trading accounts).

**Business rule established:** Brand code (TTR/QT/MFU) in challengeName/offer name is the durable ownership signal. Branch is mutable and unreliable. See BRAIN.md §11.

#### Changes

- **Migration `2026_04_26_000001`** — `people.imported_via_challenge` boolean, default false. Flags records created via the brand-first path.

- **`App\Models\Person`** — `imported_via_challenge` added to `$fillable` and casts.

- **`App\Services\MatchTrader\Client::allPropAccounts()`** — new public generator method for paginated `/v1/prop/accounts`.

- **`App\Jobs\Sync\SyncOurChallengeBuyersJob`** — new job. Streams `/v1/prop/accounts`, filters by whole-word brand code, creates missing `TradingAccount` and `Person` records. Enriches new people from a pre-built `/v1/accounts` map (no branch filter); falls back to prop/accounts name+email for ghost records (no CRM entry). Applies email + lead source filters only. Sets `imported_via_challenge = true` on newly created people.

- **`App\Jobs\Sync\SyncAccountsJob`** — added comment to branch filter documenting intentional cross-branch preservation behaviour. No logic change.

- **`App\Console\Commands\MtrSync`** — added `--challenge-buyers-only` flag; `SyncOurChallengeBuyersJob` added to the full sync sequence after `SyncAccountsJob`.

- **Tests** — updated dry-run mock to cover new generators; added `challenge-buyers-only` flag test. 76 tests passing.

- **BRAIN.md §11** — new section: Brand vs Branch — Customer Identity Rule.

### Added — Prop Challenge Offer Sync + Deposit-Side Classification Fix (2026-04-26)

#### Root cause resolved
Deposit-side `CHALLENGE_PURCHASE` rows were absent because challenge phase offers were not in the `offers` table. The `/v1/offers` endpoint returns only standard trading account offers; challenge phase offers live exclusively in `/v1/prop/challenges` as phase entries with `offerUuid` fields. The `offerLookup` in all sync/backfill paths therefore resolved to `null` for challenge deposits, leaving `offer_name = NULL` and forcing the classifier to fall through to `INTERNAL_TRANSFER`.

#### Changes

- **`App\Services\MatchTrader\Client::paginate()`** — fixed to handle flat array API responses. Previously only handled `{content:[…]}` and `{data:[…]}` wrapper shapes. `/v1/prop/challenges` returns a bare JSON array; `allPropChallenges()` was silently yielding zero items. Now detects `array_is_list($response)` and uses the response directly as the record set.

- **`App\Jobs\Sync\SyncOffersJob`** — added `syncPropChallengeOffers()` private method. After syncing standard `/v1/offers`, iterates all prop challenges via `allPropChallenges()`, filters to included branches only, skips education/course challenges (`Classifier::classify($challengeName) === 'MFU_ACADEMY'`), and upserts one offer row per phase with name format `"{challenge.name} - {phase.phaseName}"` and `is_prop_challenge = true`. Result: 133 new `MFU_CAPITAL` offer rows added.

- **`App\Console\Commands\BackfillFullHistory`** — `processDeposit()` now updates `offer_name` on existing deposit rows when currently `NULL` and the API provides a resolvable offer UUID. Previously hard-skipped all existing rows. Result: 3,890 deposit rows had `offer_name` populated.

#### Result after `backfill:transaction-categories` re-run (2026-04-26)

| Category | Count | Value |
|---|---|---|
| EXTERNAL_DEPOSIT | 3,548 | $596,779 |
| EXTERNAL_WITHDRAWAL | 1,112 | $233,217 |
| CHALLENGE_PURCHASE | **522** | **$65,193** |
| CHALLENGE_REFUND | 9 | $559 |
| INTERNAL_TRANSFER | 595 | $96,083 |
| UNCLASSIFIED | 0 | — |

CHALLENGE_PURCHASE breakdown: 447 withdrawal-side (TurboTrade Challenge, pre-Apr 2026) + 75 deposit-side (Internal Transfer with challenge phase offer name, Dec 2025–Apr 2026).

#### Known gaps (pending Werner's decision)
- **254 deposit-side CP missing:** Offer UUIDs in DB don't map to any currently active challenge in the API (retired/archived challenges). Rows correctly sit as INTERNAL_TRANSFER. No action without new data source.
- **104 withdrawal-side CP missing:** Even monthly distribution Apr 2025–Apr 2026, no date gap. Likely missing persons (email lookup fails). Extending `--since` will not help.

---

### Added — QT Brand + Dual Challenge Classification Path

#### Classifier
- `QT` added to `our_brand_codes` in `config/matchtrader.php` (QuickTrade legacy naming, same broker as TTR).
- New withdrawal-side rule: `TurboTrade Challenge` withdrawal + our brand code (TTR/QT/MFU, case-insensitive, whole-word) → `CHALLENGE_PURCHASE`. This correctly identifies pre-31-March-2026 challenge purchases, which MTR booked as wallet withdrawals. TurboTrade Challenge withdrawals with affiliate or unknown brand remain `CHALLENGE_REFUND`.
- `CategoryClassifier::hasOurBrandCode()` added for the withdrawal side (case-insensitive, separate from the case-sensitive deposit-side `isOurChallenge()`).
- 6 new tests (QT/MFU/TTR withdrawal → CHALLENGE_PURCHASE; ATY/GFB → CHALLENGE_REFUND; null offer → CHALLENGE_REFUND; case-insensitive match). Total: 35 classifier tests.

#### Historical backfill
- `backfill:full-history` command — fetches all deposits and withdrawals from MTR from a configurable start date (default 2025-03-01). Inserts new rows; skips existing. Exception: existing `CHALLENGE_REFUND` rows with no offer linkage (`trading_account_id = NULL`) are promoted to `CHALLENGE_PURCHASE` when the API provides an offer name that identifies them as our brand.
- Run on 2026-04-25 covering 2025-03-20 → 2026-04-25: 20 new rows inserted, 447 reclassified. Final total: 5,786 transactions.

#### Final category breakdown (2026-04-25)
- EXTERNAL_DEPOSIT: 3,592 (62.1%)
- EXTERNAL_WITHDRAWAL: 1,112 (19.2%)
- INTERNAL_TRANSFER: 626 (10.8%)
- CHALLENGE_PURCHASE: 447 (7.7%) — first meaningful all-time figure
- CHALLENGE_REFUND: 9 (0.2%) — affiliate brand challenges only

### Added — Transaction Classification & Reporting Accuracy

#### Schema
- `transactions.category` VARCHAR(25) NOT NULL DEFAULT 'UNCLASSIFIED', indexed. Values: `EXTERNAL_DEPOSIT`, `EXTERNAL_WITHDRAWAL`, `CHALLENGE_PURCHASE`, `CHALLENGE_REFUND`, `INTERNAL_TRANSFER`, `UNCLASSIFIED`.
- `people.mtr_created_at` / `people.mtr_updated_at` — timestamptz nullable, sourced from MTR `/v1/accounts` `created` / `updated` fields. `mtr_created_at` indexed.

#### Services & tests
- `App\Services\Transaction\CategoryClassifier` — classifies transactions by type, status, gateway name, and offer name. 17 Pest unit tests covering all branches.

#### Sync
- `SyncDepositsJob` / `SyncWithdrawalsJob`: `category` set at insert via `CategoryClassifier`. Transactions remain immutable.
- `SyncAccountsJob`: `mtr_created_at` and `mtr_updated_at` populated on every upsert.

#### Backfill commands
- `backfill:transaction-categories` — classifies all existing transactions in chunks of 500. Idempotent. Prints breakdown table + UNCLASSIFIED analysis (highlights any DONE rows needing rule review). Live result: 5,766 rows, 0 UNCLASSIFIED.
  - EXTERNAL_DEPOSIT: 3,578 (62.1%), EXTERNAL_WITHDRAWAL: 1,110 (19.3%), CHALLENGE_REFUND: 456 (7.9%), INTERNAL_TRANSFER: 622 (10.8%), CHALLENGE_PURCHASE: 0 (all pre-31 Mar format → accepted INTERNAL_TRANSFER).
- `backfill:person-mtr-timestamps` — streams all MTR accounts and populates mtr_created_at / mtr_updated_at. Live result: 29,283 of 29,284 people updated (1 email mismatch).

#### Dashboard widgets (category-aware, numbers corrected)
- Deposits / Withdrawals This Month: now use `EXTERNAL_DEPOSIT` / `EXTERNAL_WITHDRAWAL` only. Before/after: deposits $643k → $594k all-time, withdrawals $339k → $230k all-time.
- Net Deposits (Month): EXTERNAL_DEPOSIT − EXTERNAL_WITHDRAWAL only.
- New widget: **Challenge Sales (Month)** — sum of `CHALLENGE_PURCHASE` + all-time footnote.
- New widget: **Internal Transfers (Month)** — count of `INTERNAL_TRANSFER` (informational).

#### Transactions list & infolist
- New `Category` badge column (green/red/blue/orange/gray by category).
- New `Category` filter with all 6 enum values.
- `Pipeline` column moved to hidden-by-default (toggleable).

#### Person detail view
- Financials section: renamed to category-aware labels; new **Challenge Purchases** column.
- New **Activity Timeline** section: MTR Created, MTR Updated, Last Deposit, Last Online, Days Since Last Update / Last Deposit / Last Online (Today / Yesterday / N days ago; orange >14d, red >30d).

#### List columns
- Contacts list: new first column **MTR Created** (sortable, default sort DESC).
- Trading Accounts list: new first column **Opened** (sortable, default sort DESC).

### Fixed
- `SyncAccountsJob`: removed erroneous `TradingAccount` upsert — the `/v1/accounts` endpoint returns CRM contact profiles only; no MT5/Match-Trader login or offer UUID is available, so any TradingAccount created from this data was a placeholder with a wrong UUID type. Trading accounts are now created exclusively by `SyncDepositsJob` and `SyncWithdrawalsJob` from `accountInfo.tradingAccount`.
- `Pipeline\Classifier::classify()`: when `$offerName` is null but `$offerUuid` is present and not in the prop challenge set, now returns `MFU_MARKETS` (default live trading) instead of `UNCLASSIFIED`. `UNCLASSIFIED` is reserved for the case where both name and UUID are absent.
- Deleted 29,284 placeholder `trading_accounts` rows that were created by the old `SyncAccountsJob` logic (CRM UUIDs misused as trading account UUIDs, no login, no offer). Remaining 2,684 rows are real trading accounts sourced from deposit/withdrawal API data.

### Added
- `BackfillTradingAccounts` artisan command (`mtr:backfill-trading-accounts`) — re-fetches all deposits and withdrawals to upsert real trading account records from `accountInfo.tradingAccount`; supports `--dry-run`
- `SyncDepositsJob` and `SyncWithdrawalsJob`: now upsert `TradingAccount` records with real login, offer, and pipeline from `accountInfo.tradingAccount` on each transaction row processed

### Added

#### Infrastructure
- `docker-compose.dev.yml` with PostgreSQL 16 and Redis containers, health checks, named volumes
- `.env` configuration for PostgreSQL, Redis, and MTR API connection
- `config/matchtrader.php` — centralised MTR config: base URL, token, rate limit, retry attempts, branch inclusions/exclusions, lead source exclusions, transaction filters

#### Migrations
- `users` table — UUID PK, added `role` enum (`ADMIN`, `SALES_MANAGER`, `SALES_AGENT`, `VIEWER`)
- `branches` table — UUID PK, `mtr_branch_uuid` UNIQUE, `name`, `is_included` boolean
- `offers` table — UUID PK, `mtr_offer_uuid` UNIQUE, `name`, `pipeline` enum, `is_demo`, `is_prop_challenge`, `branch_uuid`, `raw_data` jsonb
- `people` table — UUID PK, `email` UNIQUE, `contact_type` enum, `phone_e164`, `phone_country_code`, `country` (expanded to varchar(100) — MTR returns full country names), `lead_status`, `lead_source`, `affiliate`, `branch`, `account_manager`, `became_active_client_at`, `last_online_at`, `duplicate_of_person_id` self-referential FK, `mtr_last_synced_at`
- `trading_accounts` table — UUID PK, `person_id` FK, `mtr_account_uuid` UNIQUE, `pipeline` enum
- `transactions` table — UUID PK, `mtr_transaction_uuid` UNIQUE, `amount_cents` bigint, immutable (no `updated_at`)
- `activities` table — UUID PK, `type`, `metadata` jsonb, created_at only
- `notes` table — UUID PK, `source` enum (`MANUAL`, `MTR_IMPORT`, `SYSTEM`)
- `tasks` table — UUID PK, `priority` enum

#### Models
- `User` — implements `FilamentUser`, `canAccessPanel()` allows all four roles, `HasUuids`
- `Person` — scopes: `leads()`, `clients()`, `active()`, `byPipeline()`, `inactiveSince()`; `getFullNameAttribute()`; `setEmailAttribute()` always lowercases; `upgradeToClient()` enforces upgrade-only rule
- `TradingAccount` — scopes: `active()`, `live()`, `byPipeline()`
- `Transaction` — no timestamps (immutable); scopes: `deposits()`, `withdrawals()`, `done()`; `getAmountUsdAttribute()`
- `Activity` — `UPDATED_AT = null`; static `record()` factory helper; `TYPES` constant
- `Branch`, `Offer`, `Note`, `Task` — full relationships and `HasUuids`

#### Services
- `App\Services\MatchTrader\Client` — Bearer token auth, 500 req/min rate limiting (timestamp ring buffer), exponential retry on 429/5xx, generator-based pagination, handles both `{content, totalElements}` and `{data, total}` response shapes; `branches()` and `offers()` handle `{branches:[]}` / `{offers:[]}` wrapper
- `App\Services\Normalizer\PhoneNormalizer` — E.164 normalisation, ZA-aware (`0XX` → `+27XX`), skips test/placeholder numbers, `countryCode()` extraction
- `App\Services\Normalizer\EmailNormalizer` — lowercase + trim, `isValid()` validation
- `App\Services\Pipeline\Classifier` — keyword matching + prop challenge UUID override; `setPropOfferUuids()` seeds UUID set

#### Sync Jobs
- `SyncBranchesJob` — upserts all MTR branches, sets `is_included` flag; tries=3, backoff=30, timeout=120
- `SyncOffersJob` — collects prop challenge offer UUIDs first (via direct `propChallenges()` call — endpoint returns flat array, not paginated), then upserts offers with pipeline classification; tries=3, backoff=30, timeout=180
- `SyncAccountsJob` — branch filter by UUID (pre-loaded branch lookup), email validation, lead source filter, accountManager extracted from nested array, upserts `people` + `trading_accounts`, upgrade-only contact_type, duplicate detection by phone; tries=3, backoff=60, timeout=3600
- `SyncDepositsJob` — branch filter, status filter (DONE only), gateway + remark exclusions, person lookup by email, idempotent insert by `mtr_transaction_uuid`; tries=3, timeout=3600
- `SyncWithdrawalsJob` — same pattern as deposits, plus lead source filter (DISTRIBUTOR/STAFF exclusion)

#### Artisan Command
- `php artisan mtr:sync` — flags: `--full`, `--incremental`, `--dry-run`, `--offers-only`, `--accounts-only`, `--deposits-only`, `--withdrawals-only`; writes JSON summary to `storage/app/mtr-sync-summaries/YYYY-MM-DD.json`

#### Filament UI
- `PersonResource` — list + view (read-only, `canCreate()` false); badge columns for `contact_type` and pipeline; filters: `contact_type`, `pipeline` (via `whereHas`), new clients this month, not contacted; searchable by name/email/phone
- `TransactionResource` — badge columns for `type`, `status`, `pipeline`; filters: `type`, `status`, `pipeline`, this month, large deposits ($5k+); `canCreate()` false
- `TradingAccountResource` — list + view, read-only
- `StatsOverviewWidget` — 6 stats: total contacts, new leads today, new deposits today, deposits this month, withdrawals this month, net deposits
- `RecentActivityWidget` — last 20 activities with badge colors by type, full-width
- `AdminPanelProvider` — brand name "Market Funded CRM", blue colour scheme, both widgets registered

#### Seeders & Factories
- `AdminUserSeeder` — creates `werner@market-funded.com` / `changeme123!` with `role=ADMIN`
- `UserFactory` — defaults `role` to `SALES_AGENT`

#### Tests (33 passing)
- `PhoneNormalizerTest` — 9 unit tests
- `EmailNormalizerTest` — 5 unit tests
- `PipelineClassifierTest` — 10 unit tests
- `MtrSyncCommandTest` — 3 feature tests (mode flag required, dry-run mock, offers-only flag)
- `FilamentResourcesTest` — 4 feature tests (smoke tests + unauthenticated redirect)

#### Documentation
- `CLAUDE.md` — session context for Claude Code
- `BRAIN.md` — business rules source of truth
- `CHANGELOG.md` — this file

### Fixed

- **MTR API field mapping** — all assumed field paths were wrong on first implementation:
  - Account email: `$raw['email']` (top-level), not `$raw['contactDetails']['email']`
  - Account branch: `$raw['accountConfiguration']['branchUuid']` (UUID), not a name string
  - Account manager: `$raw['accountConfiguration']['accountManager']` is a nested array `{uuid, email, name}` — extract `['name']`
  - Lead source: `$raw['leadDetails']['source']`, not `$raw['leadDetails']['leadSource']`
  - `becomeActiveClientTime`: `$raw['leadDetails']['becomeActiveClientTime']`, not top-level
  - Branches API wraps response in `{"branches": [...]}` — not `content`/`data`
  - Offers API wraps response in `{"offers": [...]}` — not `content`/`data`
  - Deposits/withdrawals use `accountInfo.*` and `paymentRequestInfo.financialDetails.*` — not flat
  - Prop challenges endpoint returns a flat array (no pagination wrapper)

- **Self-referential FK on `people` table** — split into `Schema::create()` then `Schema::table()` in same migration to satisfy PostgreSQL constraint ordering

- **`people.country` column** — expanded from `varchar(3)` to `varchar(100)` because MTR returns full country names (e.g. "South Africa") not only ISO-2 codes

- **`writeSummary()` type hint** — changed `int $duration` to `int|float` (`diffInSeconds()` returns float)

- **`str_starts_with()` type error in `PhoneNormalizer`** — PHP constant array keys are integers; cast to `(string)` before comparison

- **Filament `canAccessPanel()` returning 403** — `User` model was missing `FilamentUser` interface implementation

- **Memory limit for full sync** — 91k accounts require `php -d memory_limit=1G` — documented in CLAUDE.md
