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
- `App\Services\MatchTrader\Client` — full API client with rate-limiting (500 req/min), exponential retry on 429/5xx, generator-based pagination
- `SyncBranchesJob`, `SyncOffersJob`, `SyncAccountsJob`, `SyncDepositsJob`, `SyncWithdrawalsJob`
- `php artisan mtr:sync` command with `--full`, `--incremental`, `--dry-run`, `--offers-only`, `--accounts-only`, `--deposits-only`, `--withdrawals-only`
- Filament resources: `PersonResource` (list + view), `TransactionResource`, `TradingAccountResource`
- Dashboard widgets: `StatsOverviewWidget` (6 stats), `RecentActivityWidget` (last 20 events)
- Admin seeder: werner@market-funded.com / changeme123! (role=ADMIN)
- 33 Pest tests passing

### Live data (as of 2026-04-24 first successful full sync)
- 29,284 people (28,028 leads, 1,256 clients)
- 29,284 trading accounts
- 3,889 deposits, 1,877 withdrawals
- 121 offers, 26 branches (2 included: Market Funded + QuickTrade)

### Known limitations / next up (Phase 2)
- No rich Person detail page yet (only list + basic view)
- No activity timeline component in the UI
- No advanced saved filters / views
- No saved reports
- `people.country` stores raw MTR value — can be full name ("South Africa") or ISO-2 ("ZA") — not normalised to ISO-2

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
  Console/Commands/MtrSync.php          — mtr:sync artisan command
  Filament/Resources/                   — PersonResource, TransactionResource, TradingAccountResource
  Filament/Widgets/                     — StatsOverviewWidget, RecentActivityWidget
  Jobs/Sync/                            — SyncBranchesJob, SyncOffersJob, SyncAccountsJob,
                                          SyncDepositsJob, SyncWithdrawalsJob
  Models/                               — Person, TradingAccount, Transaction, Branch, Offer,
                                          Activity, Note, Task, User
  Providers/Filament/AdminPanelProvider.php
  Services/
    MatchTrader/Client.php              — MTR API client (rate-limited, retries)
    Normalizer/PhoneNormalizer.php
    Normalizer/EmailNormalizer.php
    Pipeline/Classifier.php
config/
  matchtrader.php                       — ALL MTR config (base URL, token, branch lists, exclusions)
database/
  factories/
  migrations/
  seeders/AdminUserSeeder.php
docker-compose.dev.yml
tests/
  Feature/FilamentResourcesTest.php
  Feature/MtrSyncCommandTest.php
  Unit/EmailNormalizerTest.php
  Unit/PhoneNormalizerTest.php
  Unit/PipelineClassifierTest.php
```
