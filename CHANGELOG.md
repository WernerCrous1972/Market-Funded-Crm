# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
