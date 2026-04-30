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

### Prop challenge phase offers (synced 2026-04-26)

Challenge phase offers are NOT returned by `/v1/offers`. They exist only in `/v1/prop/challenges` as phase entries, each with an `offerUuid`. `SyncOffersJob` now iterates all prop challenges on included branches, builds an offer name as `"{challenge.name} - {phase.phaseName}"` (e.g. `$10k TTR 3-Phase Challenge - Evaluation`), and upserts these into the `offers` table with `is_prop_challenge = true`.

**Education/course challenges are excluded** — challenges whose name classifies as `MFU_ACADEMY` (contains "course", "academy", etc.) are skipped. Their phase names (Evaluation, Verification, etc.) would otherwise false-positive as CHALLENGE_PURCHASE.

This naming format is deliberately designed so the classifier matches:
- `"{challenge name}"` contains the brand code (TTR, QT, or MFU) as a whole word
- `"{phase name}"` contains a challenge keyword (Evaluation, Verification, Consistency, or Instant Funded — if the challenge name already contains "Instant Funded", the phase name of "Live" is covered by the challenge name portion)

As of 2026-04-26: **133 prop challenge phase offers** in `offers` table (`is_prop_challenge = true`, `pipeline = MFU_CAPITAL`) across QuickTrade and Market Funded branches.

### Historical backfill (completed 2026-04-26)

`backfill:full-history` was run twice:
- First run (2026-04-25): 447 CHALLENGE_REFUND rows promoted to CHALLENGE_PURCHASE (withdrawal side).
- Second run (2026-04-26): 3,890 existing DONE deposit rows had `offer_name` populated from the API's `offerUuid` → offers table lookup (previously all NULL because challenge offers were absent from the table). The command was enhanced to update `offer_name` on existing deposit rows when currently NULL and the API provides a resolvable offer.

After `backfill:transaction-categories` re-ran with populated offer names (2026-04-26):

**Final breakdown (5,849 total, as of 2026-04-26):**
- EXTERNAL_DEPOSIT: 3,579 (61.2%)
- EXTERNAL_WITHDRAWAL: 1,121 (19.2%)
- CHALLENGE_PURCHASE: 534 (9.1%) — 458 withdrawal-side + 76 deposit-side
- INTERNAL_TRANSFER: 606 (10.4%)
- CHALLENGE_REFUND: 9 (0.2%)
- UNCLASSIFIED: 0

### Known gaps (as of 2026-04-26, verified)

Current state: **534 CP / $66,607** (458 withdrawal-side + 76 deposit-side).

**Deposit-side gap — CONFIRMED ILLUSORY (2026-04-26).**
Previous estimate of "~329 expected, ~254 missing" was an estimation error, not a real gap. Verified by exporting all DONE deposits for April 2026 from MTR and applying the classifier rules directly: 24 qualifying rows in the export, 24 in our DB — exact match. The classifier's offer-name-wins-over-gateway rule was also confirmed: a card-paid TTR challenge (`ZAR Card/Online EFT Payments PayGate`, `$200k TTR 1-Phase Challenge - Consistency`, 2026-04-14) is correctly classified as `CHALLENGE_PURCHASE` in our DB. There is no deposit-side gap. The "retired/archived challenges" theory from earlier investigation was wrong and is retracted.

**Withdrawal-side gap (~93 rows):** Expected ~551, have 458. Gap reduced from 104 → 93 after `SyncOurChallengeBuyersJob` recovered 11 additional people. Root cause: these withdrawals belong to people whose CRM account is on an excluded branch (cross-broker challenge buyers — see §11). Partially recoverable as those people appear in future challenge syncs. Extending `--since` will not recover historical rows; a targeted backfill after a new person is imported is required.

### Gateways confirmed excluded from real cashflow

See §5 for the full excluded gateway list. Additionally:
- `Internal Transfer` — always a wallet movement or challenge purchase, never real cashflow
- `TurboTrade Challenge` — challenge purchase (our brand) or affiliate challenge refund, never real cashflow from the deposit/withdrawal perspective

---

## 11. Brand vs Branch — Customer Identity Rule

**Brand code is the durable signal of customer ownership. Branch is mutable.**

MTR branch fields on a person's CRM account can change at any time due to manual operations (e.g. a Market Funded client's record may be moved to PulseTrade or another affiliate branch for operational reasons). The branch field is therefore NOT a reliable indicator of whether a person is a Market Funded customer.

The reliable indicator is a whole-word brand code (`TTR`, `QT`, or `MFU`) appearing in the offer name or challenge name of any transaction, trading account, or prop challenge associated with that person.

### The rule
> If a person has any deposit, withdrawal, or prop account where the offer/challenge name contains `TTR`, `QT`, or `MFU` as a whole-word brand code, they are a Market Funded customer regardless of their current MTR branch.

### `SyncOurChallengeBuyersJob`

This job implements the brand-first import path:

1. Streams `/v1/prop/accounts` (all paginated prop challenge account records).
2. Filters to records where `challengeName` contains `TTR`, `QT`, or `MFU` as a whole word.
3. For each such account: if no matching `TradingAccount` exists in our DB, creates one.
4. If the person's email is also absent from our `people` table, creates the person:
   - Fetches full CRM profile from a pre-built `/v1/accounts` map (no branch filter applied).
   - Falls back to name + email from prop/accounts if no CRM record exists (ghost records).
   - Sets `imported_via_challenge = true` to flag the import source.
5. Runs after `SyncAccountsJob` in the nightly sequence.

### `imported_via_challenge` column

Boolean flag on `people` (default `false`). Set `true` only when a person is created by `SyncOurChallengeBuyersJob`. Used for reporting on cross-branch challenge imports. `SyncAccountsJob` never writes this column (it is not in `$personData`), so the flag is preserved if the person later moves to an included branch.

### `SyncAccountsJob` idempotency

`SyncAccountsJob` applies a branch filter before any DB write. People on excluded branches are silently skipped — their existing records are never touched. This is the intentional preservation mechanism for cross-branch-imported people.

### Deferred decision (Phase 3 or later)
Should the CRM eventually import ALL MTR records regardless of brand or branch (full-coverage CRM)? This would capture affiliate broker clients who have never bought a Market Funded product. **Deferred until after Phase 2.** The current brand-first approach is the correct interim answer.

---

## 13. MTR API — Verified Production Behaviour (overrides docs)

`docs/Mt-api.md` is the canonical API reference but contains several inaccuracies vs production. **When docs and production conflict, production wins.** Always verify with `php artisan tinker` before writing field-access code.

### Match-Trader API sits behind Cloudflare (confirmed 2026-04-30)

The MTR API is fronted by Cloudflare. This has a critical implication for production server access:

**Diagnostic signal:** An HTTP 403 response with these headers indicates Cloudflare bot protection, NOT an MTR application-level rejection:
```
server: cloudflare
cf-mitigated: challenge
cf-ray: <ray-id>-LHR
```
The response body will be a "Just a moment..." HTML page (JavaScript challenge). Server-side curl/PHP cannot solve this challenge.

**Root cause:** Cloudflare aggressively challenges requests from datacentre IP ranges (DigitalOcean, AWS, etc.) by default. Residential IPs pass through. This is why:
- `php artisan mtr:sync` works on Werner's Mac (residential IP `197.184.x.x`)
- The same sync hangs/403s from the production droplet (`144.126.225.3` — DigitalOcean datacentre)

**Whitelist requirement:** The whitelist request submitted to Match-Trader must be applied at the **Cloudflare layer** — specifically an IP Access Rule with action "Allow" or a WAF Skip rule for `144.126.225.3`. A standard origin/firewall whitelist will not resolve the issue because Cloudflare rejects before the request reaches the MTR origin server.

**Diagnostic command** (run from production server to confirm status):
```bash
curl -sI --max-time 10 -H "Authorization: Bearer $TOKEN" "$MTR_BASE_URL/v1/branches" 2>&1 | grep -E "HTTP/|server:|cf-"
```
- `HTTP/2 200` + no `cf-mitigated` → whitelist applied, working
- `HTTP/2 403` + `cf-mitigated: challenge` → still blocked at Cloudflare
- `HTTP/2 401` → reached MTR origin but token issue

**Escalation path:** The whitelist request was raised by the QuickTrade owner to Match-Trader's technical contact. They need to add `144.126.225.3` to their Cloudflare IP Access Rules, not their origin firewall.

**Base URL note:** Production `.env` uses `crm-quicktrade.match-trade.com` (not `broker-api-quicktrade.match-trade.com` as in local dev). Both endpoints should be covered by the whitelist request.

### Verified corrections (2026-04-26)

| Endpoint / param | Docs say | Production | Status |
|---|---|---|---|
| Date filter on `/v1/deposits`, `/v1/withdrawals` | `from` (RFC 3339) | `from` — `dateFrom` is **silently ignored** | Fixed in `Client.php` |
| `/v1/accounts/{uuid}` | Valid GET | Returns 405 | Likely permissions — do not use |
| `/v1/accounts` response shape | `personalDetails` only (phone/country inline) | Also includes `contactDetails` and `addressDetails` wrappers | Our code is correct |
| `/v1/accounts/by-email/{email}` | Documented | Returns 200 with full account shape | Works — refactor candidate for `SyncOurChallengeBuyersJob` |
| `/v1/prop/trading-accounts` | Documented | Returns 404 | Use `/v1/prop/accounts` instead |
| `/v1/branches`, `/v1/offers` | Flat array | Wrapped: `{branches:[…]}` / `{offers:[…]}` | Our fallback handles both |
| `/v1/prop/challenges` phases | Single `offerUuid` per challenge | `phases[]` array with `offerUuid` per phase | Docs outdated; our code is correct |

---

## 14. WhatsApp Business (Meta Cloud API)

**Integration status:** Scaffolded, feature flag OFF by default (`WA_FEATURE_ENABLED=false`).
No messages will be sent until Meta approval is received and the flag is enabled.

### Channel

- **Single shared number** for all Market Funded communications.
- Direct Meta Cloud API integration (no BSP, no Twilio).
- Graph API version: `v19.0` (set in `config/whatsapp.php`).

### Service window rule (Meta 24-hour rule)

> A free-form message can only be sent to a person who sent an inbound WhatsApp message within the last 24 hours.
> Outside that window, only pre-approved **templates** may be sent.

Enforced by `ServiceWindowTracker::requiresTemplate()` in `App\Services\WhatsApp\ServiceWindowTracker`.
Calling `MessageSender::send()` without a template name when outside the window throws `TemplateRequiredException`.

### Agents (internal logical separation)

Eight agents are seeded — one per department. These are internal routing buckets only.
The client always sees "Market Funded", never the agent name.
System prompts are empty until Werner configures them. AI routing is deferred — `RouteToAgentListener` is a TODO stub.

| Key | Department |
|---|---|
| `education` | EDUCATION |
| `deposits` | DEPOSITS |
| `challenges` | CHALLENGES |
| `support` | SUPPORT |
| `onboarding` | ONBOARDING |
| `retention` | RETENTION |
| `nurturing` | NURTURING |
| `general` | GENERAL |

### Template workflow

1. Werner creates a template record in CRM (status: DRAFT).
2. Werner submits the template in Meta Business Manager manually.
3. On approval, Werner updates the record in CRM: sets `meta_template_id`, `approved_at`, status → APPROVED.
4. The CRM does **not** submit templates to Meta via API — manual workflow only.

### Webhook endpoint

```
GET  /webhooks/whatsapp   → Meta verification challenge
POST /webhooks/whatsapp   → Inbound events (messages + status updates)
```

Authentication: `X-Hub-Signature-256` HMAC verified against `WA_APP_SECRET`. Requests failing signature check are rejected with 401.

### Brand-first inbound rule

If an inbound WhatsApp arrives from a phone number not in `people`, the message is **discarded** (logged as warning). Auto-creation of person records is prohibited — consistent with the brand-first identity rule (§11).

### Key files

```
app/
  Services/WhatsApp/
    MetaCloudClient.php          — Graph API wrapper (sendTemplate, sendFreeForm, verifyWebhookSignature)
    ServiceWindowTracker.php     — 24-hour window checks
    MessageSender.php            — Single entry point; validates then dispatches job
    SendResult.php               — Typed result DTO
  Jobs/WhatsApp/
    SendWhatsAppMessageJob.php   — Queued send (3 tries, 30s backoff)
    ProcessWhatsAppWebhookJob.php — Parses Meta webhook payloads
  Events/WhatsApp/
    WhatsAppMessageReceived.php  — Fired on inbound; AI plugs in here
  Listeners/WhatsApp/
    RouteToAgentListener.php     — TODO stub
  Exceptions/
    WhatsAppSendException.php
    TemplateRequiredException.php
  Http/Controllers/Webhooks/
    WhatsAppWebhookController.php
config/whatsapp.php
```

### What is deferred

- AI / Claude API integration (RouteToAgentListener stub)
- Trigger jobs (deposit → send template, etc.) — defer until templates approved
- Media uploads (images, PDFs)
- Per-agent phone numbers

---

## 15. Market Funded Legal Entity & Meta Business Context

**This matters because Meta Business Verification asks for a legal entity name and documents.**

| Field | Value |
|---|---|
| Legal entity | Werner Crous (sole proprietor) |
| Brand / trading name | Market Funded |
| CIPC registration | None — Market Funded is a brand, not a registered company |
| Operating licence | Under QuickTrade's licence (Market Funded is a Master IB) |
| Meta Business Manager | Registered under Werner Crous, "Market Funded" as the business name |
| Business Verification docs | SARS letter / tax registration (being obtained — not yet submitted) |
| WhatsApp number | New SIM, dedicated to Cloud API (separate from consumer WhatsApp) |
| Meta portfolio | Market Funded portfolio — separate from Stock Market Dynamics portfolio |

**POPIA note:** The CRM holds 29,332+ contact records of South African and international retail forex/prop trading clients. Werner operates under QuickTrade's licence. Any bulk messaging (email or WhatsApp) must respect unsubscribe / opt-out obligations under POPIA and Meta's messaging policies.

---

## 12. Data Integrity Rules

- **All money** is stored as `bigint` in cents (multiply by 100 on write, divide by 100 on read). Never use floats.
- **All timestamps** are `timestamptz` (PostgreSQL timezone-aware). App timezone is `Africa/Johannesburg`.
- **Email** is always lowercased before insert and lookup (`setEmailAttribute` on `Person` model).
- **Transactions are immutable** — no `updated_at` column. A deposit is never edited, only inserted or skipped.
- **Branch filter must run before email validation** in sync jobs — avoids unnecessary DB lookups for excluded accounts.
