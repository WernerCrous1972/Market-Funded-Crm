# CLAUDE.md — Session Context for Market Funded CRM

Read this file at the start of every session. Then read `BRAIN.md` for business rules and `CHANGELOG.md` for recent changes before touching any code.

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
| Match-Trader IP whitelist (production server IP) | ⏳ Ticket submitted, awaiting MTR response | Production cron sync |
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
2. If Meta still blocked: SSH hardening (dedicated task — generate keys, disable password auth, create deployer user)
3. Or: pull `deploy.sh` into git repo + add permission normalisation step
4. Or: Phase 4 prep (health scoring factors 5 & 6, or review `market-funded-crm-phase-0-brief.md`)

---

## Open Follow-ups (non-blocking)

1. **Production `.env`** — add `WA_FEATURE_ENABLED=false` explicitly when populating Meta credentials (currently relies on config default)
2. **`deploy.sh` not in git** — file exists on server at `/var/www/market-funded-crm/deploy.sh` but unversioned
3. **Permission drift** — `core.fileMode` was `true` on server causing phantom diffs; fixed to `false`. Consider `chmod` normalisation in `deploy.sh`
4. **SSH hardening** — password auth as root, no keys. Separate dedicated task. Plan: generate keys, add via DO console, disable password auth, create deployer user, verify ufw, optionally install fail2ban

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
