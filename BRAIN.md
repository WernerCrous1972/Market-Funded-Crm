# BRAIN.md — Business Rules for Market Funded CRM

This file is the business rules source of truth. It does not contain code or credentials — those live in `app/` and `.env`. Update this file when a business rule changes, not when implementation details change.

---

## 1. The Three MFU Segments (Pipelines)

Market Funded operates three revenue streams. Every contact, trading account, and transaction is classified into one of these pipelines:

| Pipeline | Parent Company | Product |
|---|---|---|
| `MFU_ACADEMY` | Stock Market College | Trading courses, IB Academy, educational products |
| `MFU_CAPITAL` | TurboTrade | Prop trading challenges and evaluations |
| `MFU_MARKETS` | QuickTrade | Live forex/CFD trading accounts (default) |

A single person can belong to multiple pipelines simultaneously — they may have a live trading account (MFU_MARKETS) and have purchased a course (MFU_ACADEMY). Each trading account and transaction is classified independently.

---

## 2. Match-Trader Brokers API

**Base URL pattern:** `https://broker-api-{platform}.match-trade.com` — stored in `.env` as `MTR_BASE_URL`
**Auth:** Bearer token — stored in `.env` as `MTR_TOKEN`. Never log or commit the token.
**Rate limit:** 500 requests per minute. The client enforces this client-side via a timestamp ring buffer.
**Retry policy:** On 429 — wait 65s (attempt 1), 120s (attempt 2+). On 5xx — exponential backoff starting at 5s.

**Important:** Always inspect live API responses with `php artisan tinker` before writing field-access code. The API response shapes differ from what documentation suggests. See `CHANGELOG.md` for discovered field mapping corrections.

---

## 3. Branch Filtering

The MTR instance is shared across many IB partners. Only import data from Market Funded's own branches.

**INCLUDE these branches:**
- Market Funded
- QuickTrade

**The sync uses an inclusion list, not an exclusion list.** Only branches explicitly listed in `config/matchtrader.php` under `included_branches` are imported. Every other branch on the MTR instance is automatically excluded — no action required when new unrelated branches appear.

**INCLUDE (synced):**
- Market Funded
- QuickTrade

If a new branch appears in MTR that should be included, add it to `config/matchtrader.php` under `included_branches` — not to an exclusion list.

**Not included — observed at Phase 1 completion (2026-04-24), 24 branches:**
- Alpha Forex Trading
- ATY Markets
- Africa Markets
- EarniMax
- Ego Markets
- eSwatini
- Fav X Capital
- Forex Evolution
- Funding Frontier
- Global Forex Brokers
- Henderson and Henderson
- Imali Markets
- Infinity Funded
- Introducing Broker Academy
- Kript Capital
- MATI Trader
- MTT-test
- NO Withdrawal Branch
- PulseTrade
- Smart Online Trader
- The Magasa Group
- Trade With Chantel
- Ultimate Money Concepts
- Zimbabwe

This list is a snapshot for reference only — it does not drive any filtering logic.

Branch filtering is applied at the account level (by `accountConfiguration.branchUuid`, resolved against the `branches` table) and at the deposit/withdrawal level (by `accountInfo.branchUuid`).

---

## 4. Lead Source Filtering

Exclude these lead sources from import — they are internal/affiliate accounts, not real clients:

- `DISTRIBUTOR`
- `STAFF`

Applied to accounts during `SyncAccountsJob` and to withdrawals during `SyncWithdrawalsJob`.

---

## 5. Transaction Filters

Only store transactions that pass ALL of the following:

**Status:** Only `DONE`. Exclude: `FAILED_PAYMENT`, `PROCESSING_PAYMENT`, `NEW`, `CANCELLED_BY_USER`, `AWAITING_CONFIRMATION`, `REJECTED`, `FAILED`.

**Excluded gateways** (case-insensitive match on gateway name):
- `correction`
- `stock market college commission`

**Excluded remarks** (case-insensitive substring match on remark text):
- `correction`
- `mt5 transfer`
- `commission`

---

## 6. Lead vs Client Rule (Upgrade-Only)

A contact's `contact_type` is either `LEAD` or `CLIENT`. This field is **upgrade-only — a CLIENT can never be downgraded back to LEAD**, even if `becameActiveClientTime` is later cleared in MTR.

**Trigger:** A contact becomes a CLIENT when the MTR API field `leadDetails.becomeActiveClientTime` is non-null.

**On sync:**
- If new contact has `becomeActiveClientTime` → create as `CLIENT`
- If new contact has no `becomeActiveClientTime` → create as `LEAD`
- If existing `CLIENT` re-syncs → leave as `CLIENT` regardless of MTR state
- If existing `LEAD` now has `becomeActiveClientTime` → upgrade to `CLIENT` and log a `STATUS_CHANGED` activity

---

## 7. Duplicate Detection

The same person may register multiple accounts under different email addresses. Detect and link them:

**Detection triggers:**
1. Same `phone_e164` (E.164 normalised)
2. Same `first_name` + `last_name` combination (future enhancement — not yet implemented in Phase 1)

**On detection:**
- The newer record's `duplicate_of_person_id` is set to the older record's `id`
- A `DUPLICATE_DETECTED` activity is logged on the newer record
- Neither record is deleted — human review is required before merging

---

## 8. Pipeline Classification

Every trading account offer is classified into a pipeline using this priority order:

### Priority 1 — Prop Challenge UUID match
If the offer's UUID matches any `offerUuid` found in a prop challenge phase (from `/v1/prop/challenges`), classify as `MFU_CAPITAL`.

### Priority 2 — Offer name keyword match (case-insensitive)
| Pipeline | Keywords |
|---|---|
| `MFU_CAPITAL` | challenge, evaluation, phase, funded, prop, instant, verification, consistency |
| `MFU_ACADEMY` | course, academy, education, training |
| `MFU_MARKETS` | (default — no keywords matched) |

### Priority 3 — Default
If no keyword matches, classify as `MFU_MARKETS`.

**Note:** The `/v1/offers` endpoint returns trading account group offers (e.g. "QT2 Live USD 1:500"). These are standard live/demo accounts and will almost always classify as `MFU_MARKETS` by keyword. Capital and Academy pipeline accounts are identified by prop challenge UUID lookup or by the offer name containing prop keywords.

---

## 9. Phone Normalisation Rules

All phone numbers are stored in E.164 format (e.g. `+27681234567`).

**South Africa default:** Numbers starting with `0` and 10 digits long → strip leading `0`, prepend `27`.
Numbers already prefixed with `27` and 11 digits → leave as-is.

**Test/placeholder numbers to reject:** Strings of 7+ repeated digits (all zeros, all nines, all ones).

**Minimum length:** 7 digits (after stripping country code) before rejecting.

**Country code extraction:** Attempted by longest-prefix match against the known calling codes in `PhoneNormalizer::CALLING_CODES`.

---

## 10. Transaction Classification

**This is the most important business rule in the CRM.** Without correct classification, deposit and withdrawal totals are inflated by internal wallet movements and challenge-related transfers that are not real client cashflow.

### Category enum

Every transaction has a `category` column (VARCHAR 25, NOT NULL, DEFAULT 'UNCLASSIFIED'):

| Category | Meaning |
|---|---|
| `EXTERNAL_DEPOSIT` | Real client deposit via payment gateway (card, crypto, bank) |
| `EXTERNAL_WITHDRAWAL` | Real client withdrawal via payment gateway |
| `CHALLENGE_PURCHASE` | Prop challenge bought — post-31 Mar 2026: Internal Transfer deposit with offer name; pre-31 Mar 2026: TurboTrade Challenge withdrawal with our brand code |
| `CHALLENGE_REFUND` | TurboTrade Challenge withdrawal with affiliate or unknown brand — not our revenue |
| `INTERNAL_TRANSFER` | Wallet movement between accounts — not real cashflow |
| `UNCLASSIFIED` | Non-DONE status (PENDING/FAILED/REVERSED), or unrecognised pattern |

Only `EXTERNAL_DEPOSIT` and `EXTERNAL_WITHDRAWAL` are counted as real business cashflow in all dashboard aggregations and Person-level financial totals.

### Brand codes (our brands only)

These are the brands whose challenge transactions count as **our** revenue. Challenges under any other brand code (ATY, SOT, EAR, GFB, etc.) belong to affiliate brokers.

| Code | Broker | Notes |
|---|---|---|
| `TTR` | QuickTrade / TurboTrade | Current naming convention (post-rebrand) |
| `QT` | QuickTrade | Legacy naming convention (pre-rebrand) — same broker as TTR |
| `MFU` | Market Funded | Market Funded prop challenge brand |

Brand codes are stored in `config/matchtrader.php` under `our_brand_codes`. To add a new brand, append there — do NOT modify `CategoryClassifier`.

### The 31 March 2026 gateway changeover

MTR changed how TurboTrade challenge activity is represented in the transaction feed on 31 March 2026:

**Before 31 March 2026 (historical format):**
- Challenge purchases appeared as **withdrawals** with gateway = `TurboTrade Challenge` and an offer name containing our brand code (TTR, QT, or MFU)
- Challenge refunds for **affiliate** brands also used gateway = `TurboTrade Challenge` but with a different brand code — these are not our revenue

**After 31 March 2026 (current format):**
- Challenge purchases appear as **deposits** with gateway = `Internal Transfer` **AND** offer name containing a challenge keyword + our brand code
- Challenge refunds continue to use gateway = `TurboTrade Challenge`
- Real internal transfers also use gateway = `Internal Transfer`

### Dual classification path

`CHALLENGE_PURCHASE` is identified via two separate paths depending on era:

**Post-31 Mar 2026 — deposit-side rule (offer name wins over gateway):**
1. Transaction must be a DEPOSIT with status DONE
2. Offer name must contain a challenge keyword (case-insensitive): `Instant Funded`, `Evaluation`, `Verification`, `Consistency`
3. Offer name must also contain one of our brand codes as a whole word (case-sensitive): `TTR`, `QT`, `MFU`

**Pre-31 Mar 2026 — withdrawal-side rule (TurboTrade Challenge gateway):**
1. Transaction must be a WITHDRAWAL with status DONE
2. Gateway must be `TurboTrade Challenge`
3. Offer name must contain one of our brand codes as a whole word (case-insensitive)

### Full classification rules (`App\Services\Transaction\CategoryClassifier`)

```
CHALLENGE_KEYWORDS = ['Instant Funded', 'Evaluation', 'Verification', 'Consistency']
  (case-insensitive substring match on offer name)

OUR_BRAND_CODES = ['TTR', 'QT', 'MFU']
  (whole-word match; case-sensitive for deposits, case-insensitive for withdrawals)

If status != DONE:
  → UNCLASSIFIED

If type = DEPOSIT and status = DONE:
  If offer name contains challenge keyword AND our brand code (case-sensitive) → CHALLENGE_PURCHASE
  Else if gateway = 'Internal Transfer'                                        → INTERNAL_TRANSFER
  Else                                                                         → EXTERNAL_DEPOSIT

If type = WITHDRAWAL and status = DONE:
  If gateway = 'TurboTrade Challenge':
    If offer name contains our brand code (case-insensitive)  → CHALLENGE_PURCHASE
    Else                                                      → CHALLENGE_REFUND
  Else if gateway = 'Internal Transfer'                       → INTERNAL_TRANSFER
  Else                                                        → EXTERNAL_WITHDRAWAL
```

### Why Internal Transfer appears as three different things

`Internal Transfer` as a gateway name can mean:
1. **A real wallet-to-wallet transfer** between a client's own accounts (always INTERNAL_TRANSFER)
2. **A pre-31-Mar-2026 challenge purchase** — these are actually the TurboTrade Challenge **withdrawals** (identified via the withdrawal-side rule above), not Internal Transfer deposits
3. **A post-31-Mar-2026 challenge purchase** — identifiable because MTR now attaches the offer name to the deposit

The INTERNAL_TRANSFER bucket for deposits with `Internal Transfer` gateway and no offer name remains an **accepted ambiguity** — these are pre-changeover records that cannot be distinguished from real wallet movements. Do not attempt to reclassify this bucket.

### Historical backfill (completed 2026-04-25)

`php artisan backfill:full-history` was run on 2026-04-25, covering 2025-03-20 → 2026-04-25 (the earliest MTR record date). Results:

- 44,259 rows fetched from API (37,167 deposits + 7,092 withdrawals)
- 20 new transactions inserted (14 EXTERNAL_DEPOSIT, 4 INTERNAL_TRANSFER, 2 EXTERNAL_WITHDRAWAL)
- 447 existing CHALLENGE_REFUND rows promoted to CHALLENGE_PURCHASE (offer name confirmed our brand)
- 9 CHALLENGE_REFUND rows retained (affiliate brand challenges — correctly not our revenue)

Final breakdown after backfill (5,786 total):
- EXTERNAL_DEPOSIT: 3,592 (62.1%)
- EXTERNAL_WITHDRAWAL: 1,112 (19.2%)
- INTERNAL_TRANSFER: 626 (10.8%)
- CHALLENGE_PURCHASE: 447 (7.7%)
- CHALLENGE_REFUND: 9 (0.2%)

### Gateways confirmed excluded from real cashflow

See §5 for the full excluded gateway list. Additionally:
- `Internal Transfer` — always a wallet movement or challenge purchase, never real cashflow
- `TurboTrade Challenge` — challenge purchase (our brand) or affiliate challenge refund, never real cashflow from the deposit/withdrawal perspective

---

## 11. Data Integrity Rules

- **All money** is stored as `bigint` in cents (multiply by 100 on write, divide by 100 on read). Never use floats.
- **All timestamps** are `timestamptz` (PostgreSQL timezone-aware). App timezone is `Africa/Johannesburg`.
- **Email** is always lowercased before insert and lookup (`setEmailAttribute` on `Person` model).
- **Transactions are immutable** — no `updated_at` column. A deposit is never edited, only inserted or skipped.
- **Branch filter must run before email validation** in sync jobs — avoids unnecessary DB lookups for excluded accounts.
