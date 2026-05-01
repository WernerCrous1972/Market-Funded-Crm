# CLAUDE.md — Session Context for Market Funded CRM

Read this file at the start of every session. Then follow the Session Start Protocol below before touching any code.

---

## Session Start Protocol

At the start of every new session, before doing anything else, follow this protocol:

### 1. Always read

- **This file (`CLAUDE.md`)** — for project orientation
- **The last 5 entries of `CHANGELOG.md`** — for recent context

### 2. Read when relevant

- **`BRAIN.md`** — read sections relevant to the current task. Do NOT read end-to-end every session. Section index:
  - §1–2: Pipelines, MTR API basics
  - §3–4: Branch and lead source filtering
  - §5–10: Transaction filters, classification, brand-first rule
  - §11: Brand vs branch identity rule
  - §12: Data integrity rules (money, timestamps, immutability)
  - §13: MTR API verified production behaviour, Cloudflare layer
  - §14: WhatsApp Business integration
  - §15: Market Funded legal entity / Meta Business context
  - §16: Production SSH access (deployer user, key-only)

### 3. Check your own memory

- Recent decisions, preferences, in-flight tasks
- Current external blockers (see list below)

### 4. Confirm orientation

Before starting work, briefly confirm to Werner:
- What you understand the current state to be
- What task you're about to work on
- Any external blockers that might affect it

Do NOT skip this protocol even if Werner asks for a quick task. A 30-second orientation prevents acting on stale assumptions.

---

## Werner's working preferences

- **Direct, concise responses.** No padding, no ceremonial preambles.
- **Flag risk before action.** If a step is irreversible, slow down and verify with Werner before executing.
- **Werner is non-technical.** Explain *why* a command is being run when it matters, not just what to type.
- **Manual control over autonomous execution** for production-touching tasks. Werner often prefers to run commands himself with Claude Code guiding, rather than Claude Code executing. Confirm preference at task start when relevant.
- **One pair of eyes is good, two is better.** Werner often runs decisions through both Claude Code (execution) and a planning assistant in claude.ai (strategic review). Don't be surprised if he pauses to consult.

---

## Current external blockers

> **Maintenance note:** This section is point-in-time. Werner or Claude Code should update it whenever a blocker resolves, a new one appears, or status changes. Stale information here is worse than no information.

- **Match-Trader Cloudflare whitelist** — production droplet (`144.126.225.3`) blocked at Cloudflare layer. Whitelist requested via QuickTrade owner. Awaiting MTR action. Production sync currently non-functional. Diagnostic: `curl -sI` against the API returns `cf-mitigated: challenge`. Last checked: 2026-05-01.
- **Meta developer account device-trust cooldown** — preventing completion of WhatsApp Cloud API setup. Werner is waiting it out. Last checked: 2026-05-01.
- **SARS / tax docs** — needed for Meta Business Verification. Werner is obtaining; multi-day timeline. Last checked: 2026-05-01.

---

## Open follow-ups

> **Maintenance note:** Add items as they're identified. Remove or strike through when complete.

- Port 8080 ufw rule on production — assumed Reverb WebSocket, not yet verified. Confirm or remove.
- ~~System updates + kernel reboot complete (kernel 6.8.0-111, all services up).~~ ✅ Done 2026-05-01
- Retry `libgd3` upgrade when `ppa.launchpadcontent.net` is reachable from production (deferred — repo unreachable during upgrade, no functional impact).
- Delete pre-update DigitalOcean snapshot (`before-system-updates-2026-05-01`) ~1 week after 2026-05-01 if no issues surface (~$0.18/month while it exists).
- Explicit `WA_FEATURE_ENABLED=false` in production `.env` (currently relies on config default).
- ~~`deploy.sh` added to git (8bfa975) — `core.fileMode false` fix included.~~ ✅ Done 2026-05-01
- Permission drift — `core.fileMode false` in place. If drift returns, investigate root cause before adding `chmod` to deploy.sh (blanket chmod risks breaking legitimately-executable files).
- Sales team onboarding — roles, permissions, first-login flow design.
- Phase 4: health scoring factors 5 & 6 (equity snapshots — gRPC stream vs REST polling decision).
- Phase 4: AI agent integration (Claude API into `RouteToAgentListener`).

---

## Project

**Market Funded CRM** — a standalone brokerage CRM for Market Funded, a Master IB on Quicktrade.world. Imports contacts and transactions directly from the Match-Trader Brokers API. Single-tenant. Built from scratch — no legacy code inherited.

**Owner:** Werner Crous, Johannesburg ZA
**Spec:** `market-funded-crm-phase-0-brief.md` is the only external reference this project acknowledges.

---

## Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.3 |
| Framework | Laravel 11 |
| Admin UI | Filament v3 |
| Database | PostgreSQL 16 |
| Cache / Queues | Redis |
| Queue monitor | Laravel Horizon |
| Real-time | Laravel Reverb (WebSockets) |
| Testing | Pest v3 |
| HTTP client | Guzzle |

**Local dev:**
- PostgreSQL + Redis run via Docker Compose (`docker compose -f docker-compose.dev.yml up -d`)
- Container names: `mfu-postgres`, `mfu-redis`
- Dev server: `php artisan serve` → http://localhost:8000/admin
- Run full sync: `php -d memory_limit=1G artisan mtr:sync --full`

---

## Build Conventions

- **PSR-12** coding standard, `declare(strict_types=1)` on every file
- **Conventional commits:** `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`
- **No hardcoded secrets — ever.** All credentials in `.env`, read via `config/`
- **UUID primary keys** on all tables (Laravel `HasUuids` trait — PHP generates UUIDs before insert)
- **Money as bigint cents** — never floats. `amount_cents bigint`, computed to USD on read
- **Timestamps as `timestamptz`** — always timezone-aware
- **Queued jobs** must implement `ShouldQueue` and explicitly set `$tries`, `$backoff`, `$timeout`
- **Upgrade-only `contact_type`** — a LEAD can become a CLIENT, never the reverse
- `down()` method required on every migration

---

## Current Phase

**Phases 1–3 + WhatsApp scaffold** ✅ Complete and deployed. Awaiting external dependencies before Phase 4.

---

## Production SSH Access

**Root login is DISABLED. Password auth is DISABLED. Key-only access enforced.**

| Field | Value |
|---|---|
| Server | `144.126.225.3` / `crm.market-funded.com` |
| User | `deployer` (NOT root — root SSH disabled) |
| Key | `~/.ssh/mfu_production` on Werner's Mac (Ed25519, passphrase-protected) |
| Sudo | `deployer` has full sudo access |
| Connection | `ssh -i ~/.ssh/mfu_production deployer@144.126.225.3` |
| Backup config | `/etc/ssh/sshd_config.backup` on server |

**Ubuntu 24.04 note:** `/etc/ssh/sshd_config.d/50-cloud-init.conf` overrides the main sshd_config. This file must have `PasswordAuthentication no` — the main file alone is not sufficient on DigitalOcean droplets.

**Security context:** fail2ban active, 1,212+ IPs banned (server was under active brute-force attack before hardening). Public key also stored in DigitalOcean account-level SSH key store.

---

## Current Status (as of 2026-04-29)

### Built and working
- Full Laravel 11 + Filament v3 scaffold, Docker Compose (PostgreSQL 16 + Redis)
- All migrations through `2026_04_29_000001_create_whatsapp_tables`
- MTR sync: branches, offers (incl. prop challenge phases), accounts, deposits, withdrawals, challenge buyers
- `php artisan mtr:sync` with `--full`, `--incremental`, `--dry-run`, and resource-specific flags
- `CategoryClassifier` — brand-aware classification, rules are final (see BRAIN.md §10)
- Filament resources: People, Transactions, TradingAccounts, EmailTemplates, EmailCampaigns, WhatsAppTemplates, WhatsAppMessages, Agents
- Dashboard: StatsOverview, GlobalDepositChart, RecentActivity, AtRiskWidget
- Health scoring (HealthScorer), person metrics cache (PersonMetric), at-risk widget
- Email campaigns: templates, sending, open/click tracking, unsubscribe
- Task queue: auto-assignment (Option C), My Tasks page
- Real-time alerts via Reverb (deposit, withdrawal, lead converted)
- WhatsApp scaffold: MetaCloudClient, ServiceWindowTracker, MessageSender, webhook controller, jobs, event/listener stub, Filament UI, Person detail tab + Send action
- **161 Pest tests passing**

### Live data (as of 2026-04-26 — production sync blocked pending MTR IP whitelist)
- 29,332 people (28,055 leads, 1,277 clients)
- 5,849 transactions — 0 UNCLASSIFIED
- 254 offers, 26 branches, 8 agents seeded

### WhatsApp status
- Scaffolded and deployed. `WA_FEATURE_ENABLED=false` — no sends possible.
- `WA_*` credential vars absent from production `.env` — will be added manually when Meta approves.
- Webhook endpoint live at `/webhooks/whatsapp` but POST requests return 401 (no `WA_APP_SECRET` set — correct).

---

## Pending External Dependencies

These are blocking real-world functionality. Do not attempt workarounds — wait for each to resolve.

| Dependency | Status | Blocks |
|---|---|---|
| Match-Trader IP whitelist — **Cloudflare layer** | ⏳ Escalated to MTR via QuickTrade owner | Production cron sync |
| ↳ _Technical detail_ | _`144.126.225.3` gets `cf-mitigated: challenge` 403 in 68ms. Must be a Cloudflare IP Access Rule "Allow", not an origin firewall rule. Local Mac sync works fine (residential IP). See BRAIN.md §13._ | |
| Meta developer account (device-trust cooldown) | ⏳ Security cooldown clearing | WhatsApp number registration |
| Tax docs / SARS letter for Werner | ⏳ Being obtained (days) | Meta Business Verification upload |
| WhatsApp Business number registration | ⏳ Waits on developer account | `WA_PHONE_NUMBER_ID` credential |
| Meta template approval (first template) | ⏳ Waits on number | First real WA send |

**Without Business Verification:** Meta limits outbound to 250 unique recipients/24h — sufficient for initial testing.

---

## Meta Business Setup Context

- **Legal entity:** Werner Crous (sole prop) — Market Funded is a brand/trading name, NOT a CIPC-registered company
- **Portfolio:** Market Funded Business Manager portfolio created (separate from Stock Market Dynamics)
- **Admin:** Werner Crous added as full-control admin
- **Number:** New SIM reserved for Cloud API use (separate from any consumer WhatsApp)
- **Stock Market Dynamics pages:** Deliberately NOT added to Market Funded portfolio — different brand

---

## End of session 2026-04-29

**What was done:**
- Local incremental sync run (`mtr:sync --incremental`) — clean, no issues
- WhatsApp architecture decisions locked in (see BRAIN.md §14)
- WhatsApp scaffolding built: 11 commits, 28 new tests (161 total)
- Deployed to production: 1 migration, 8 agents seeded, all caches rebuilt, workers restarted
- Meta Business Manager setup started — blocked on device-trust security cooldown

**Tomorrow's likely starting points (in priority order):**
1. If Meta cooldown cleared: `developers.facebook.com` → create app → add WhatsApp product → register SIM number → generate System User token → capture 5 `WA_*` credentials → add to production `.env` → first test send → tag `v1.1.0`
2. Pull `deploy.sh` into git repo + add permission normalisation step
3. Verify ufw port 8080 is intentional (assumed Reverb WebSocket — remove if not needed)
4. Apply pending system updates on server (13 available, 5 security; requires restart — schedule a maintenance window)
5. Phase 4 prep (health scoring factors 5 & 6, or review `market-funded-crm-phase-0-brief.md`)

---

## Open Follow-ups (non-blocking)

1. **Production `.env`** — add `WA_FEATURE_ENABLED=false` explicitly when populating Meta credentials (currently relies on config default)
2. **`deploy.sh` not in git** — file exists on server at `/var/www/market-funded-crm/deploy.sh` but unversioned
3. **Permission drift** — `core.fileMode` was `true` on server causing phantom diffs; fixed to `false`. Consider `chmod` normalisation in `deploy.sh`
4. **SSH hardening** — ✅ COMPLETE (2026-04-30). See Production SSH Access section above.

---

## How to Resume

1. Read this file (done).
2. Read `BRAIN.md` — business rules and WhatsApp architecture decisions.
3. Read `CHANGELOG.md` — what changed recently.
4. Run `php artisan test` — confirm 161 tests pass before touching anything.
5. Check DB: `php artisan tinker --execute="echo \App\Models\Person::count();"` to confirm data present.

---

## Key File Map

```
app/
  Console/Commands/
    MtrSync.php                         — mtr:sync artisan command
    BackfillFullHistory.php             — backfill:full-history
    BackfillTransactionCategories.php   — backfill:transaction-categories
    ImportHistoricalChallenges.php      — import:historical-challenges (CSV reclassification)
  Events/
    DepositReceived.php, LargeWithdrawalReceived.php, LeadConverted.php
    WhatsApp/WhatsAppMessageReceived.php — AI routing entry point (Phase 4)
  Exceptions/
    WhatsAppSendException.php, TemplateRequiredException.php
  Filament/Resources/
    PersonResource, TransactionResource, TradingAccountResource
    EmailTemplateResource, EmailCampaignResource
    WhatsAppTemplateResource, WhatsAppMessageResource, AgentResource
  Filament/Widgets/                     — StatsOverviewWidget, RecentActivityWidget,
                                          GlobalDepositChartWidget, AtRiskWidget
  Http/Controllers/
    EmailTrackingController.php
    Webhooks/WhatsAppWebhookController.php
  Jobs/
    Sync/                               — SyncBranchesJob, SyncOffersJob, SyncAccountsJob,
                                          SyncDepositsJob, SyncWithdrawalsJob, SyncOurChallengeBuyersJob
    Metrics/RefreshPersonMetricsJob.php, CalculateHealthScoresJob.php
    Email/SendCampaignJob.php
    WhatsApp/SendWhatsAppMessageJob.php, ProcessWhatsAppWebhookJob.php
  Listeners/WhatsApp/RouteToAgentListener.php — TODO stub (Phase 4 AI entry point)
  Models/                               — Person, TradingAccount, Transaction, Branch, Offer,
                                          Activity, Note, Task, User, PersonMetric,
                                          Agent, WhatsAppTemplate, WhatsAppMessage,
                                          EmailTemplate, EmailCampaign, EmailCampaignRecipient,
                                          EmailEvent, EmailUnsubscribe
  Services/
    MatchTrader/Client.php
    Normalizer/PhoneNormalizer.php, EmailNormalizer.php
    Pipeline/Classifier.php
    Transaction/CategoryClassifier.php  — RULES ARE FINAL
    Health/HealthScorer.php
    Email/CampaignMailer.php
    WhatsApp/MetaCloudClient.php        — Graph API wrapper
    WhatsApp/ServiceWindowTracker.php   — 24h window rule
    WhatsApp/MessageSender.php          — single send entry point
    WhatsApp/SendResult.php
config/
  matchtrader.php                       — MTR config; brand codes + challenge keywords
  whatsapp.php                          — WA_FEATURE_ENABLED (false), Meta API keys
database/
  migrations/                           — all migrations through 2026_04_29
  seeders/AdminUserSeeder.php, AgentSeeder.php
docker-compose.dev.yml
routes/web.php                          — email tracking + /webhooks/whatsapp (CSRF exempt)
tests/
  Feature/                              — FilamentResourcesTest, MtrSyncCommandTest,
                                          ImportHistoricalChallengesTest, Phase2Test,
                                          HealthScoringTest, EmailCampaignTest,
                                          TaskQueueTest, WhatsAppWebhookTest
  Unit/                                 — CategoryClassifierTest, EmailNormalizerTest,
                                          PhoneNormalizerTest, PipelineClassifierTest,
                                          MetaCloudClientTest, ServiceWindowTrackerTest,
                                          MessageSenderTest
```
