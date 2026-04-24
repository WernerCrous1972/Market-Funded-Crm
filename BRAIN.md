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

**EXCLUDE these branches** (do not import contacts or transactions from):
- ATY Markets
- Africa Markets
- EarniMax
- Global Forex Brokers
- Imali Markets
- The Magasa Group
- Infinity Funded

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

## 10. Data Integrity Rules

- **All money** is stored as `bigint` in cents (multiply by 100 on write, divide by 100 on read). Never use floats.
- **All timestamps** are `timestamptz` (PostgreSQL timezone-aware). App timezone is `Africa/Johannesburg`.
- **Email** is always lowercased before insert and lookup (`setEmailAttribute` on `Person` model).
- **Transactions are immutable** — no `updated_at` column. A deposit is never edited, only inserted or skipped.
- **Branch filter must run before email validation** in sync jobs — avoids unnecessary DB lookups for excluded accounts.
