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

**Phase 1 — Foundation & MTR Read-Only Sync** ✅ Complete

---

## Current Status

### Built and working
- Full Laravel 11 + Filament v3 project scaffold
- Docker Compose: PostgreSQL 16 + Redis
- All Phase 1 migrations: `users`, `branches`, `offers`, `people`, `trading_accounts`, `transactions`, `activities`, `notes`, `tasks`
- All Eloquent models with relationships, scopes, and casts
- `PhoneNormalizer` (E.164, ZA-aware), `EmailNormalizer`, `Pipeline\Classifier`
- `App\Services\MatchTrader\Client` — full API client with rate-limiting (500 req/min), exponential retry on 429/5xx, generator-based pagination; flat array response support
- `SyncBranchesJob`, `SyncOffersJob` (incl. prop challenge phase offers), `SyncAccountsJob`, `SyncDepositsJob`, `SyncWithdrawalsJob`
- `php artisan mtr:sync` command with `--full`, `--incremental`, `--dry-run`, `--offers-only`, `--accounts-only`, `--deposits-only`, `--withdrawals-only`
- `CategoryClassifier` — brand-aware transaction classification; rules are final (see BRAIN.md §10)
- `backfill:full-history` — one-time API backfill from configurable start date; populates `offer_name` on existing rows
- `backfill:transaction-categories` — re-classifies all rows from DB state; idempotent
- `import:historical-challenges` — CSV-based reclassification with audit log and rollback (awaiting Werner's complete CSV)
- Filament resources: `PersonResource` (list + view), `TransactionResource`, `TradingAccountResource`
- Dashboard widgets: `StatsOverviewWidget` (6 stats), `RecentActivityWidget` (last 20 events)
- Admin seeder: werner@market-funded.com / changeme123! (role=ADMIN)
- **75 Pest tests passing**

### Live data (as of 2026-04-26)
- 29,284 people (28,028 leads, 1,256 clients)
- 5,786 transactions: 3,905 deposits, 1,881 withdrawals
- **254 offers** (121 standard trading + 133 prop challenge phase offers)
- 26 branches (2 included: Market Funded + QuickTrade)

### Transaction classification (as of 2026-04-26)
| Category | Count | Value |
|---|---|---|
| EXTERNAL_DEPOSIT | 3,548 | $596,779 |
| EXTERNAL_WITHDRAWAL | 1,112 | $233,217 |
| CHALLENGE_PURCHASE | **522** | **$65,193** |
| CHALLENGE_REFUND | 9 | $559 |
| INTERNAL_TRANSFER | 595 | $96,083 |
| UNCLASSIFIED | 0 | — |

Expected ground truth: ~880 CP / ~$180,500. Two known gaps — see BRAIN.md §10 for detail.

### Known limitations / next up (Phase 2)
- No rich Person detail page yet (only list + basic view)
- No advanced saved filters / views
- No saved reports
- `people.country` stores raw MTR value — can be full name ("South Africa") or ISO-2 ("ZA") — not normalised to ISO-2
- **Open question deferred:** Does `/v1/prop/accounts` return historical challenge accounts (including retired ones) or only active ones? If historical, it may let us recover offer names for the 254 deposit-side CP gap. Diagnose next session before acting.

---

## How to Resume

1. Read this file (done).
2. Read `BRAIN.md` — business rules that govern all sync and classification logic.
3. Read `CHANGELOG.md` — what changed recently.
4. Run `php artisan test` — confirm all tests still pass before touching anything.
5. Check DB state: `php artisan tinker --execute="echo \App\Models\Person::count();"` to confirm data is present.

---

## Key File Map

```
app/
  Console/Commands/
    MtrSync.php                         — mtr:sync artisan command
    BackfillFullHistory.php             — backfill:full-history (API → DB, populates offer_name)
    BackfillTransactionCategories.php   — backfill:transaction-categories (re-classify from DB)
    ImportHistoricalChallenges.php      — import:historical-challenges (CSV reclassification)
  Filament/Resources/                   — PersonResource, TransactionResource, TradingAccountResource
  Filament/Widgets/                     — StatsOverviewWidget, RecentActivityWidget
  Jobs/Sync/                            — SyncBranchesJob, SyncOffersJob (+ prop challenge phases),
                                          SyncAccountsJob, SyncDepositsJob, SyncWithdrawalsJob
  Models/                               — Person, TradingAccount, Transaction, Branch, Offer,
                                          Activity, Note, Task, User
  Providers/Filament/AdminPanelProvider.php
  Services/
    MatchTrader/Client.php              — MTR API client (rate-limited, retries, flat array pagination)
    Normalizer/PhoneNormalizer.php
    Normalizer/EmailNormalizer.php
    Pipeline/Classifier.php
    Transaction/CategoryClassifier.php  — RULES ARE FINAL — do not modify without Werner's approval
config/
  matchtrader.php                       — ALL MTR config; our_brand_codes + challenge_keywords here
database/
  factories/
  migrations/
  seeders/AdminUserSeeder.php
docker-compose.dev.yml
tests/
  Feature/FilamentResourcesTest.php
  Feature/MtrSyncCommandTest.php
  Feature/ImportHistoricalChallengesTest.php
  Unit/CategoryClassifierTest.php
  Unit/EmailNormalizerTest.php
  Unit/PhoneNormalizerTest.php
  Unit/PipelineClassifierTest.php
```
