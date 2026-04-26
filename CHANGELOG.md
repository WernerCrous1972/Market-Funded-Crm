# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
