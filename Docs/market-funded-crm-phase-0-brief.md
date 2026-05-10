# Market Funded CRM — Phase 0 Project Brief

**Version:** 1.0
**Date:** 23 April 2026
**Owner:** Werner Crous (Market Funded, Johannesburg ZA)
**Purpose:** This document is the project specification. Hand it to Claude Code as the starting point for the build. It replaces the earlier 73-page blueprint and the current Twenty CRM setup.

---

## 1. Context You Need Before Writing Any Code

### 1.1 What this CRM is for

Werner operates **Market Funded (MFU)**, a Johannesburg-based umbrella entity that is a master IB (Introducing Broker) on the **Quicktrade** brokerage, which runs on the **Match-Trader (MTR)** platform. MFU is the pilot brand; three parent companies — QuickTrade (forex/CFD), Stock Market College (trading education), and TurboTrade (prop trading) — will later adopt the same CRM once it's proven.

MFU has three revenue streams, each corresponding to a "pipeline" a contact can belong to:

| Segment | Parent Company | Product |
|---|---|---|
| **MFU Academy** | Stock Market College | Trading courses, IB Academy |
| **MFU Capital** | TurboTrade | Prop trading challenges & evaluations |
| **MFU Markets** | QuickTrade | Live forex/CFD trading accounts |

A single person can belong to one, two, or all three segments simultaneously depending on what they've transacted.

### 1.2 What exists today (and why it's being replaced)

- **Match-Trader Brokers API** is the source of truth for ~91,000 contacts, ~36,000 deposits, ~7,000 withdrawals.
- **Twenty CRM** (self-hosted at `localhost:3000`) currently holds the synced data. It's working but has fundamental mismatches with brokerage workflows — the `city` field is repurposed to store country, `jobTitle` is repurposed to store lead status, and individual deposit/withdrawal transactions are aggregated into `Opportunity` records so transaction history is lost.
- **Mautic** (at `localhost:8090`) currently handles email marketing via segment sync. **Mautic is being retired** — email marketing moves into the new CRM natively.
- **A Python sync pipeline** (`~/crm-imports/sync_from_matchtrader.py`) runs nightly via `henry-nightly-reset.sh` at 00:05. This pipeline contains the working MTR integration logic and is the reference implementation for the new sync. **Do not throw this code away — study it and port its logic.**
- **An MCP server** (`~/twenty-crm-mcp-server/`) gives Claude access to Twenty. It becomes irrelevant once Twenty is replaced.

### 1.3 What Werner needs that he doesn't have

The MTR CRM is sales-friendly but impossible to analyse. Werner cannot currently answer basic operational questions like:

- Which active traders dropped in volume this month?
- Which leads converted to deposits within 7 days of signup?
- How is my top IB's book performing week over week?
- Who hasn't logged in for 10+ days but still has equity above $5,000?

**The new CRM's core job is to answer those questions instantly**, and to trigger workflows (WhatsApp, email, tasks) based on the answers.

### 1.4 Important operational context from `MARKET_FUNDED_BRAIN.md`

- **Branches to INCLUDE in sync:** `Market Funded`, `QuickTrade`
- **Branches to EXCLUDE:** ATY Markets, Africa Markets, EarniMax, Global Forex Brokers, Imali Markets, The Magasa Group, Infinity Funded
- **Lead sources to EXCLUDE:** `DISTRIBUTOR`, `STAFF`
- **Transaction filters:** Only process `status = DONE`. Exclude gateways `Correction`, `Stock Market College Commission`. Exclude remarks containing `correction`, `mt5 transfer`, `commission`.
- **Lead vs Client rule:** A contact is a Client if MTR has set `becomeActiveClientTime`. Otherwise they're a Lead. **`contactType` is upgrade-only — never downgrade Client → Lead.**
- **Duplicate detection:** Same person may have multiple accounts under different emails. Detect duplicates by phone number OR (first name + last name). Log and link them (see data model).
- **Pipeline classification logic (for offers/deposits):**
  - `MFU_CAPITAL` if offer name contains: challenge, evaluation, phase, funded, prop, instant, verification, consistency — OR the offer UUID matches any prop challenge phase offer UUID
  - `MFU_ACADEMY` if offer name contains: course, academy, education, training
  - `MFU_MARKETS` otherwise (default — live trading)

---

## 2. Technology Stack

### 2.1 Core stack

| Layer | Choice | Why |
|---|---|---|
| Language | PHP 8.3 | Laravel ecosystem, Claude Code writes it excellently |
| Framework | **Laravel 11** | Mature, well-documented, huge ecosystem |
| Admin UI | **Filament v3** | Generates CRUD, dashboards, forms; designed for internal business tools |
| Database | **PostgreSQL 16** | JSON support, robust, Filament-compatible |
| Cache / Queue | **Redis** | For background jobs and caching |
| Queue Workers | **Laravel Horizon** | Queue monitoring dashboard |
| Real-time | **Laravel Reverb** (self-hosted WebSockets) | Native Laravel WebSocket server, replaces Soketi from the old blueprint |
| Background Jobs | Laravel queues on Redis | Nightly MTR sync, email sending, activity processing |
| Email delivery | Brevo SMTP (existing) | Already configured in the Mautic setup, credentials already working |
| Version control | Git, repo on GitHub (private) | Standard practice |
| Deployment target | macOS dev first, Linux VPS (DigitalOcean) later | Werner's current setup is macOS |

### 2.2 Explicitly NOT in this phase

These were in the old blueprint but are deferred or removed:

- ❌ Multi-tenancy (this CRM is single-tenant for Market Funded)
- ❌ Stripe / billing / SaaS mode
- ❌ Dograh voice AI (Phase 4+, optional)
- ❌ Krayin CRM as base
- ❌ Kubernetes / Docker Compose production setup (Phase 5+)
- ❌ TimescaleDB (standard PostgreSQL is enough)
- ❌ AI-based churn scoring (use rule-based scoring first)
- ❌ Mautic integration (email moves into the new CRM)

---

## 3. Data Model

All tables use UUID primary keys (not auto-increment integers). All timestamps are `timestamptz` (PostgreSQL timezone-aware). All money is stored in **cents/microcents** as bigint, never as floats (financial integrity).

### 3.1 `people` table (Person)

One record per unique human. A single person can have multiple trading accounts.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `first_name` | varchar(100) | From MTR `personalDetails.firstname` |
| `last_name` | varchar(100) | From MTR `personalDetails.lastname` |
| `email` | varchar(255) UNIQUE | Always lowercased before insert/lookup |
| `phone_e164` | varchar(20) nullable | E.164 format (e.g., `+27681234567`) |
| `phone_country_code` | varchar(3) nullable | ISO-2 (e.g., `ZA`) |
| `country` | varchar(3) nullable | ISO-2 from MTR `addressDetails.country` — **proper field, no more repurposing `city`** |
| `contact_type` | enum('LEAD','CLIENT') | Upgrade-only |
| `lead_status` | varchar(50) nullable | From MTR `leadDetails.status` (e.g., `HOT LEAD`, `New contact`) — **proper field, no more repurposing `jobTitle`** |
| `lead_source` | varchar(100) nullable | From MTR `leadDetails.source` |
| `affiliate` | varchar(100) nullable | Referring IB partner |
| `branch` | varchar(100) | MTR branch name (denormalized; usually `Market Funded` or `QuickTrade`) |
| `account_manager` | varchar(100) nullable | Assigned sales rep |
| `became_active_client_at` | timestamptz nullable | From MTR |
| `last_online_at` | timestamptz nullable | From MTR |
| `notes_contacted` | boolean default false | Manual flag |
| `duplicate_of_person_id` | uuid FK → people.id nullable | For duplicate profiles |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |
| `mtr_last_synced_at` | timestamptz nullable | For incremental sync |

**Indexes:** `email` (unique), `phone_e164`, `contact_type`, `branch`, `lead_source`, `account_manager`, `became_active_client_at`.

**Derived fields (computed, not stored):** `full_name`, `total_deposits_usd`, `total_withdrawals_usd`, `net_deposits_usd`, `last_deposit_at`, `days_since_last_deposit`, `days_since_last_login`, `health_score` — all computed from child tables. **If performance requires caching, cache these on a separate `person_metrics` table updated nightly; do not store on `people` directly.**

### 3.2 `trading_accounts` table

One record per MTR account UUID. A person can have multiple.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `person_id` | uuid FK → people.id | |
| `mtr_account_uuid` | varchar(64) UNIQUE | The MTR `uuid` field |
| `mtr_login` | varchar(20) nullable | MTR `login` (e.g., `719188`) |
| `offer_id` | uuid FK → offers.id nullable | Which product/offer this account trades |
| `pipeline` | enum('MFU_CAPITAL','MFU_ACADEMY','MFU_MARKETS','UNCLASSIFIED') | Derived from offer |
| `is_demo` | boolean default false | |
| `is_active` | boolean default true | |
| `opened_at` | timestamptz | From MTR `created` |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |

**Indexes:** `person_id`, `mtr_account_uuid` (unique), `pipeline`, `is_active`.

### 3.3 `transactions` table

**Every deposit and withdrawal is stored as its own row.** Aggregates are always derived.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `person_id` | uuid FK → people.id | Denormalized for query speed |
| `trading_account_id` | uuid FK → trading_accounts.id nullable | |
| `mtr_transaction_uuid` | varchar(64) UNIQUE | MTR `uuid` field |
| `type` | enum('DEPOSIT','WITHDRAWAL') | |
| `amount_cents` | bigint | Amount × 100 (never a float) |
| `currency` | varchar(3) | Usually `USD` |
| `status` | enum('DONE','PENDING','FAILED','REVERSED') | Only `DONE` is actionable |
| `gateway_name` | varchar(100) nullable | `paymentGatewayDetails.name` |
| `remark` | text nullable | From MTR `remark` |
| `occurred_at` | timestamptz | MTR `created` field |
| `synced_at` | timestamptz | When we pulled it |
| `pipeline` | enum(...) nullable | Copied from trading_account for fast filtering |

**Indexes:** `person_id`, `mtr_transaction_uuid` (unique), `type`, `status`, `occurred_at`, `pipeline`, composite `(person_id, type, status)`.

### 3.4 `offers` table

Product/offer catalog from MTR.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `mtr_offer_uuid` | varchar(64) UNIQUE | |
| `name` | varchar(255) | |
| `pipeline` | enum('MFU_CAPITAL','MFU_ACADEMY','MFU_MARKETS','UNCLASSIFIED') | Derived via keyword rules |
| `is_demo` | boolean default false | |
| `is_prop_challenge` | boolean default false | True if offer UUID found in any prop challenge phase |
| `branch_uuid` | varchar(64) nullable | |
| `raw_data` | jsonb | Full MTR response for future reference |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |

### 3.5 `activities` table

Timeline of everything that happens to a person. This is the "story" view.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `person_id` | uuid FK → people.id | |
| `type` | varchar(50) | `DEPOSIT`, `WITHDRAWAL`, `LOGIN`, `TRADE_OPENED`, `NOTE_ADDED`, `EMAIL_SENT`, `EMAIL_OPENED`, `CALL_LOG`, `WHATSAPP_SENT`, `TASK_CREATED`, `TASK_COMPLETED`, `STATUS_CHANGED`, `DUPLICATE_DETECTED` |
| `description` | text | Human-readable summary |
| `metadata` | jsonb nullable | Type-specific data (amount, trade details, etc.) |
| `user_id` | uuid FK → users.id nullable | The agent/system who triggered it |
| `occurred_at` | timestamptz | |
| `created_at` | timestamptz | |

**Indexes:** `person_id`, `type`, `occurred_at`.

### 3.6 `notes` table

Plain markdown notes (no complex blocknote format this time).

| Column | Type |
|---|---|
| `id` | uuid PK |
| `person_id` | uuid FK → people.id |
| `user_id` | uuid FK → users.id nullable |
| `title` | varchar(255) nullable |
| `body` | text |
| `source` | enum('MANUAL','MTR_IMPORT','SYSTEM') |
| `created_at` / `updated_at` | timestamptz |

### 3.7 `tasks` table

Sales follow-up tasks assigned to agents.

| Column | Type |
|---|---|
| `id` | uuid PK |
| `person_id` | uuid FK → people.id |
| `assigned_to_user_id` | uuid FK → users.id |
| `title` | varchar(255) |
| `description` | text nullable |
| `due_at` | timestamptz nullable |
| `completed_at` | timestamptz nullable |
| `priority` | enum('LOW','MEDIUM','HIGH','URGENT') |
| `created_at` / `updated_at` | timestamptz |

### 3.8 `users` table (Filament default, extended)

Sales agents and admin users. Use Filament's default scaffolding plus:

- `role` enum: `ADMIN`, `SALES_MANAGER`, `SALES_AGENT`, `VIEWER`

### 3.9 `email_campaigns`, `email_campaign_recipients`, `email_events` tables

Deferred to Phase 3 — defined there.

### 3.10 `branches` table

Simple lookup for MTR branches (seeded from API).

| Column | Type |
|---|---|
| `id` | uuid PK |
| `mtr_branch_uuid` | varchar(64) UNIQUE |
| `name` | varchar(100) |
| `is_included` | boolean | True for Market Funded & QuickTrade; false for all excluded branches |

---

## 4. Build Phases

### Phase 1 — Foundation & MTR Read-Only Sync (Week 1)

**Goal:** Log in, see all MTR contacts in a clean Filament table.

**Tasks:**

1. **Project setup**
   - Fresh `laravel new market-funded-crm`
   - Install Filament v3 (`composer require filament/filament`)
   - Install Horizon, Reverb, Telescope (dev only)
   - PostgreSQL + Redis configured via Docker Compose for local dev
   - Git repo initialized, `.env` excluded, `.env.example` committed
   - CI check: `php artisan test` runs clean on a fresh clone

2. **Core migrations**
   - All tables in section 3 created (except email/campaign — deferred to Phase 3)
   - Foreign keys, indexes, enums all in place
   - Seeders for `branches` (from MTR), `offers` (from MTR), an initial admin user

3. **Models**
   - Eloquent models for every table with correct relationships, casts (enums, jsonb), and scopes
   - `Person::active()`, `Person::leads()`, `Person::clients()`, `Person::byPipeline($pipeline)` scopes
   - `Transaction::donated()`, `Transaction::deposits()`, `Transaction::withdrawals()` scopes

4. **MTR service class**
   - `App\Services\MatchTrader\Client` with methods: `accounts($page, $size)`, `deposits($since = null)`, `withdrawals($since = null)`, `offers()`, `branches()`, `propChallenges()`
   - Guzzle HTTP client with Bearer token auth, rate-limiting (500 req/min), exponential retry on 429/5xx
   - All credentials from `.env` only — **zero hardcoded secrets, zero defaults in `os.environ.get()` style**
   - Phone normalizer helper (E.164, ZA-aware)
   - Email normalizer helper (lowercase, trim)
   - Pipeline classifier helper (keyword + prop offer UUID set)

5. **First sync command**
   - `php artisan mtr:sync --full` and `php artisan mtr:sync --incremental` (24h window)
   - Dispatches queued jobs: `SyncOffersJob`, `SyncBranchesJob`, `SyncAccountsJob`, `SyncDepositsJob`, `SyncWithdrawalsJob`
   - Each job processes in chunks, writes activities on significant changes, handles failures gracefully
   - Progress output via Laravel's command progress bar
   - Summary written to `storage/app/mtr-sync-summaries/YYYY-MM-DD.json`
   - `--dry-run` flag that logs what would happen without writing

6. **Filament resources (read-only first)**
   - `PersonResource` — list, view (no create/edit yet)
     - Columns: name, email, phone, country, contact_type badge, branch, lead_source, became_active_client_at
     - Filters: contact_type, branch, lead_source, pipeline (via relationship), date ranges
     - Search: name, email, phone
   - `TradingAccountResource` — list, view
   - `TransactionResource` — list, view
     - Columns: occurred_at, person, type badge, amount (formatted), gateway, status
     - Filters: type, status, pipeline, date range, amount range

7. **Dashboard widgets (v1)**
   - Total Contacts (with lead/client split)
   - Total Deposits (this month + all time, $)
   - Total Withdrawals (this month + all time, $)
   - New Leads Today
   - New Deposits Today
   - Recent Activity Feed (last 20 events)

**Phase 1 Acceptance Criteria:**

- Werner logs in, sees a list of his real MTR clients, can click one and see their trading accounts and transactions.
- `php artisan mtr:sync --full` completes without errors against live MTR API and populates the database.
- Dashboard shows real numbers that match MTR.
- No secrets in git history; all credentials in `.env`.
- At least 10 automated tests passing (MTR client, phone/email normalizers, pipeline classifier, sync dry-run).

---

### Phase 2 — Person Detail Page & Rich Filtering (Week 2)

**Goal:** Werner can look at any client and understand their story at a glance.

1. **Custom Person detail page** in Filament
   - Header: name, email, phone (with WhatsApp/call links), country flag, branch, account manager, contact type badge
   - Segment pills showing which pipelines this person has traded in
   - Key stats row: total deposits, total withdrawals, net deposits, days since last login, days since last deposit
   - **Left main panel:** Equity / deposit / withdrawal chart (last 90 days, toggleable)
   - **Right sidebar:** Trading accounts list, quick-add Note, quick-create Task
   - **Bottom tabs:** Activity timeline, Transactions table, Notes, Tasks, Trading Accounts

2. **Activity timeline component**
   - Chronological feed of everything: deposits, withdrawals, notes added, status changes, logins
   - Filterable by type
   - Icons and colors per activity type

3. **Advanced filtering on PersonResource**
   - "Active traders who dropped volume this month"
   - "Leads unconverted 7+ days after signup"
   - "Clients with equity > $X and no login in Y days"
   - Save filters as "Views" (Filament feature)

4. **Saved reports (simple v1)**
   - Top IB partners by volume
   - Lead source conversion rates
   - Deposits by pipeline, by month

**Phase 2 Acceptance Criteria:**

- Werner can open any person's detail page and immediately understand their trading behavior without opening MTR.
- At least 5 saved filters/views that answer the questions in section 1.3.

---

### Phase 3 — Workflows & Communication (Week 3–4)

**Goal:** The CRM starts acting, not just reporting.

1. **Tasks & assignments**
   - Agents see a personal task queue on login
   - Task creation from Person page ("Follow up re: deposit", "Call about drawdown")
   - Task notifications (in-app, optional email)

2. **Real-time alerts via Laravel Reverb**
   - New deposit toast for the assigned account manager
   - First-time deposit → "🎉 Converted!" alert to the team
   - Large withdrawal ($5k+) → alert to admin

3. **Native email campaigns (replacing Mautic)**

   New tables:
   - `email_templates` — reusable templates (HTML + variables)
   - `email_campaigns` — a scheduled send (template + recipient filter + schedule)
   - `email_campaign_recipients` — per-recipient row with status
   - `email_events` — sent, opened (via tracking pixel), clicked (via redirect), bounced

   Features:
   - WYSIWYG email builder (use `filament/spatie-laravel-media-library-plugin` or TinyMCE)
   - Recipient selection via saved filter (e.g., "All MFU_CAPITAL clients")
   - Merge tags: `{{first_name}}`, `{{last_deposit_amount}}`, etc.
   - Unsubscribe link (mandatory, with `email_unsubscribes` table)
   - Test send before live send
   - Tracking pixel for opens, redirect wrapper for clicks

4. **WhatsApp integration (Meta Cloud API direct)**
   - Service class: `App\Services\WhatsApp\MetaCloudClient`
   - Send individual message from Person page
   - Templated message support (WhatsApp Business API templates must be pre-approved)
   - Webhook endpoint for inbound messages → store as `Activity` + optional auto-reply
   - Unified message thread view on Person page

5. **Rule-based health scoring**
   - Nightly job calculates `health_score` (0–100) per Client based on:
     - Days since last trade (weight: -20 points if >14 days)
     - Days since last login (weight: -15 points if >7 days)
     - Equity change over 30 days (weight: ±20 points)
     - Deposit/withdrawal ratio (weight: ±15 points)
     - Number of open positions (weight: +10 if >0)
     - Average trade size trend (weight: ±10 points)
   - Score stored on `person_metrics` table
   - Dashboard widget: "At-Risk Clients" (health < 40)

**Phase 3 Acceptance Criteria:**

- Werner can send a templated email campaign to a filtered list of clients.
- When a real deposit lands in MTR, Werner sees a toast within 60 seconds of the next sync.
- Every MFU_CAPITAL client has a health score visible on their profile.

---

### Phase 4 — AI Assist (Optional, Week 5+)

Only start Phase 4 once Phases 1–3 are stable and Werner has been using the system for at least 2 weeks.

1. **AI-drafted outreach messages**
   - Button on Person page: "Draft follow-up WhatsApp" / "Draft re-engagement email"
   - Uses Claude API with Person context + health score + recent activity
   - Agent reviews/edits before sending — **AI never sends autonomously in Phase 4**

2. **AI-assisted KYC triage** (if/when KYC docs flow through CRM)
   - OCR + document validity checks
   - Flags anomalies for human review
   - Human always approves final status

3. **Optional: Dograh AI voice agent** (opt-in, per-jurisdiction toggle)
   - Self-hosted via Docker
   - Pre-call data fetch pulls Person context from CRM
   - Outbound calls for explicit retention workflows only
   - All calls logged as Activities, recordings stored
   - **Off by default. Werner enables per use case after legal review for the relevant jurisdiction.**

---

## 5. Migration Strategy (Twenty → New CRM)

The new CRM imports fresh from MTR, not from Twenty. This is cleaner than migrating Twenty's corrupted field mappings (`city`=country, `jobTitle`=status).

**Steps:**

1. Build and test Phase 1 against a dev PostgreSQL (not touching Twenty).
2. Run `php artisan mtr:sync --full` against the dev DB. Validate numbers match MTR.
3. **From Twenty, export only data that isn't in MTR**: manual notes, manual `contacted` flags, agent assignments that were set inside Twenty rather than MTR. Export as CSV.
4. Run a one-off import command: `php artisan import:twenty-extras path/to/twenty-export.csv` that merges manual annotations onto the MTR-sourced records.
5. Switch the nightly cron from the Python sync to the Laravel sync.
6. Keep Twenty running in read-only mode for one month as a fallback.
7. After one month, decommission Twenty.

---

## 6. Rules for Claude Code

When you hand this brief to Claude Code, include these rules:

1. **Security:**
   - No credentials in source code. Ever. Always `.env` + `config/`.
   - No real tokens in logs.
   - Stripe/webhook signature verification where applicable.
   - All routes behind authentication except `/login`, `/forgot-password`, and explicit webhook endpoints with signature verification.

2. **Code quality:**
   - Every migration has a `down()` method.
   - Every model has relationships typed and documented.
   - Queued jobs use `ShouldQueue` and the `tries`, `backoff`, and `timeout` properties explicitly set.
   - No `session()` inside jobs (jobs run without session context).
   - All money is bigint cents. All timestamps are `timestamptz`.
   - Strict types on everything possible.

3. **Testing:**
   - PHPUnit or Pest, Werner's choice — default to Pest.
   - Every service class has unit tests.
   - Every command has a feature test that runs the command in dry-run mode.
   - Every Filament resource has a smoke test that it loads without error.
   - Aim for ~60–70% coverage — not 100%, but the critical paths (sync, money, auth).

4. **Commits:**
   - One logical change per commit.
   - Conventional commits style: `feat(sync): add deposit sync job`, `fix(person): lowercase email on save`.
   - Push to `main` branch only after local tests pass.

5. **Documentation:**
   - Every command written has `--help` output explaining usage.
   - A `README.md` at the repo root with setup instructions.
   - A `docs/` folder where architectural decisions are captured as short markdown notes.

6. **When in doubt:**
   - Ask Werner, don't assume.
   - Prefer boring, well-understood patterns over clever ones.
   - Small commits, often.

---

## 7. What to Build First (Claude Code Kickoff Sequence)

If Claude Code asks "what do I do first?" — this is the order:

1. `laravel new market-funded-crm`
2. Add Filament, Horizon, Reverb, Pest, PHPStan
3. Create `docker-compose.dev.yml` with PostgreSQL 16 + Redis
4. Create all Phase 1 migrations (section 3.1–3.8)
5. Create all Phase 1 Eloquent models with relationships
6. Create `App\Services\MatchTrader\Client` with live API connection and test it against real MTR
7. Create `App\Services\Pipeline\Classifier` (port from `sync_from_matchtrader.py`)
8. Create `SyncOffersJob` + `SyncBranchesJob` + corresponding `php artisan mtr:sync --offers-only` etc.
9. Create `SyncAccountsJob` → populates `people` + `trading_accounts`
10. Create `SyncDepositsJob` and `SyncWithdrawalsJob` → populates `transactions`
11. Create Filament `PersonResource` (list + view)
12. Create Dashboard widgets
13. Run full sync. Validate. Commit. Celebrate.

---

## 8. Open Questions for Werner

Werner should answer these before coding starts:

1. **Local dev vs remote VPS for early development?** — Recommend local (your Mac) for Phase 1–3, then deploy to DigitalOcean for Phase 4.
2. **Email sending domain?** — `market-funded.com` via Brevo SMTP (already configured). Confirm Brevo credentials are in `.env`.
3. **WhatsApp Business API account status?** — Is Meta Cloud API already approved for `market-funded.com`, or still pending?
4. **Who else uses the CRM?** — Just Werner? Or sales team? If sales team, how many? (Affects role/permission design in Phase 1.)
5. **Data retention / POPIA compliance expectations?** — Anything specific beyond the standard (encrypted at rest, access logs, right-to-delete)?

---

## 9. Out of Scope (Explicit)

These are NOT being built. If Werner wants them later, they're separate projects:

- Multi-tenant SaaS to sell to other brokers
- Stripe billing / usage metering
- GHL (Go High Level) migration or integration
- Mobile app (web-responsive is enough)
- TradingView chart embedding
- MetaTrader 4 integration (only Match-Trader)
- Full-featured email builder like Mailchimp — basic templating is enough
- Slack / Microsoft Teams integration

---

## 10. Definition of Done

**The Phase 0–1 build is "done" when:**

- [ ] Werner logs into the new CRM.
- [ ] Werner sees his ~91k MTR contacts.
- [ ] Werner clicks one and sees their deposits, withdrawals, and trading accounts.
- [ ] The nightly sync runs and keeps the data current.
- [ ] Werner finds one question the MTR CRM can't answer, and answers it in the new CRM within 5 minutes.

That last point is the real test. If Werner can't answer a real operational question on day one of Phase 1 handover, something's wrong.

---

*End of brief. Hand this file to Claude Code. When Claude Code has questions, come back to Werner or the planning assistant for clarifications.*
