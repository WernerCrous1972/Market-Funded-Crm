# Phase 4a Plan — AI Outreach + Henry Integration

**Version:** 0.1 (draft)
**Date:** 2026-05-06
**Owner:** Werner Crous
**Author:** Claude Code (with Werner)
**Status:** Awaiting review

---

## 1. Scope and intent

Phase 4a delivers **AI-driven autonomous outreach** for the Market Funded CRM, integrated with **Henry** (the user's existing OpenClaw-based ops AI on Telegram). Voice agent work is deferred to Phase 4b. Health-score factors 5 & 6 are deferred to Phase 4.5.

The system is designed for **autonomous operation with regulatory oversight by AI agents**, not human-in-the-loop on every send. A human-review path remains available for campaigns and sensitive cases.

### Phase 4a deliverables

1. Henry integration foundation — bidirectional comms between the CRM and Henry's OpenClaw gateway
2. AI outreach engine for WhatsApp (autonomous + reviewed modes) with compliance pre-checks
3. Filament UI for drafts, templates, autonomous-trigger config, AI ops dashboard
4. Inbound auto-response with confidence-based handoff to humans

### Out of scope (this phase)

- Voice agent (Phase 4b)
- Email outreach (follows after WhatsApp ships and is stable)
- Health-score factors 5 & 6 (Phase 4.5)
- KYC document AI triage
- Henry's voice/STT/TTS — Henry already has it; we just send him events

---

## 2. Operating modes

| Mode | Trigger | Human review | Channel |
|---|---|---|---|
| **Autonomous** | Event-driven (new lead, deposit, challenge purchase, dormant, etc.) | No — AI sends directly, audited later | WhatsApp |
| **Reviewed** | Agent or admin clicks "Draft with AI" | Yes — agent edits + approves | WhatsApp |
| **Bulk reviewed** | Agent runs draft action on a filtered list | Yes — per-recipient approval or one-template-many-recipients | WhatsApp |
| **Inbound auto-reply** | Client/lead replies to an AI-sent message | Confidence-gated — auto if confident, escalate to human if not | WhatsApp |

Every autonomous trigger has a per-template **autonomy switch** that an admin must explicitly enable. New triggers ship **disabled by default**.

---

## 3. Architecture overview

```
┌────────────────────────────────────────────────────────────────┐
│ Market Funded CRM (Laravel)                                    │
│                                                                │
│  Events (DepositReceived, LeadConverted, etc.)                 │
│        │                                                       │
│        ▼                                                       │
│  OutreachOrchestrator                                          │
│        │                                                       │
│        ├─► ModelRouter ──► Claude API (Sonnet 4.6 / Haiku 4.5) │
│        │                   ↳ failover: Haiku → external        │
│        │                                                       │
│        ├─► ComplianceAgent (Haiku self-check)                  │
│        │                                                       │
│        ├─► CostCeilingGuard (soft $300, hard $500)             │
│        │                                                       │
│        └─► MessageSender ──► Meta Cloud API ──► WhatsApp       │
│                                                                │
│  HenryGatewayClient ──► http://localhost:18789/rpc             │
│        ↑                                                       │
│        │ webhook /webhooks/henry                               │
│        ▼                                                       │
└────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌────────────────────────────────────────────────────────────────┐
│ Henry (OpenClaw gateway, port 18789, loopback only)            │
│                                                                │
│  - Telegram → Werner (alerts, briefings, queries)              │
│  - MCP server: market-funded-crm (Laravel API tools)           │
│  - Cron: 9am book health summary, anomaly checks               │
│  - Memory dreaming, model failover already built in            │
└────────────────────────────────────────────────────────────────┘
```

### Key principles

- **Henry is a peer, not a dependency.** If Henry's gateway is down, the CRM still operates. Outreach still sends. Henry just doesn't get notified.
- **Compliance gate is mandatory** for autonomous sends, optional for reviewed sends.
- **Cost ceiling is a hard guardrail.** Above the soft limit, autonomy pauses; above hard, all AI sends pause until admin resets.
- **Every AI action is logged as an `Activity`** — full audit trail per the CLAUDE.md convention. Logging is trimmed (per-spec) for high-volume autonomous sends to control DB growth.
- **POPIA position:** Client PII is sent to Anthropic API. Anthropic's zero-retention policy on API calls is the safety net. Documented decision; revisit before Phase 5.

---

## 4. Data model changes

Five new tables:

### 4.1 `outreach_templates`

Reusable message templates with variables, channel, target trigger, and autonomy state.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `name` | varchar(100) | Human label, e.g. "Welcome — new lead" |
| `trigger_event` | varchar(100) nullable | e.g. `lead_created`, `deposit_first`, `challenge_passed`, `dormant_14d`. Null = manual only |
| `channel` | enum('WHATSAPP','EMAIL') | Email is structural-only this phase |
| `system_prompt` | text | The AI prompt body — describes tone, message goal, constraints |
| `compliance_rules` | text nullable | Extra rules the compliance agent must apply for this template |
| `model_preference` | varchar(50) nullable | Override (`sonnet-4-6`, `haiku-4-5`); null = use ModelRouter default |
| `autonomous_enabled` | boolean default false | Admin must explicitly enable. New templates always start false |
| `is_active` | boolean default true | |
| `created_by_user_id` | uuid FK | |
| `created_at` / `updated_at` | timestamptz | |

### 4.2 `ai_drafts`

Every AI-generated message (autonomous or reviewed) before send. Becomes immutable once status leaves `pending_review`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `person_id` | uuid FK | |
| `template_id` | uuid FK nullable | Null for one-off drafts (no template) |
| `mode` | enum('AUTONOMOUS','REVIEWED','BULK_REVIEWED') | |
| `channel` | enum('WHATSAPP','EMAIL') | |
| `model_used` | varchar(50) | e.g. `sonnet-4-6` |
| `prompt_hash` | varchar(64) | SHA256 of full prompt; full prompt only stored for non-autonomous |
| `prompt_full` | text nullable | Stored for REVIEWED + BULK_REVIEWED; null for AUTONOMOUS |
| `draft_text` | text | What the model produced |
| `final_text` | text nullable | Set after agent edit (REVIEWED) or after send (AUTONOMOUS) |
| `status` | enum('pending_review','approved','rejected','sent','failed','blocked_compliance') | |
| `compliance_check_id` | uuid FK | |
| `triggered_by_user_id` | uuid FK nullable | Null for autonomous |
| `triggered_by_event` | varchar(100) nullable | e.g. `DepositReceived`, set for autonomous |
| `tokens_input` | int | |
| `tokens_output` | int | |
| `cost_cents` | int | Computed at send time |
| `sent_at` | timestamptz nullable | |
| `created_at` / `updated_at` | timestamptz | |

### 4.3 `ai_compliance_checks`

Result of running the compliance agent against a draft.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `draft_id` | uuid FK | |
| `model_used` | varchar(50) | Default `haiku-4-5` |
| `passed` | boolean | |
| `flags` | jsonb | Array of `{rule, severity, excerpt}` — empty when `passed=true` |
| `verdict_text` | text | Compliance agent's reasoning |
| `tokens_input` / `tokens_output` | int | |
| `cost_cents` | int | |
| `created_at` | timestamptz | |

### 4.4 `ai_usage_log`

Aggregated daily spend per task type. Populated incrementally; queried by `CostCeilingGuard`.

| Column | Type |
|---|---|
| `id` | uuid PK |
| `date` | date |
| `task_type` | varchar(50) — `draft`, `compliance`, `inbound_classify`, `henry_query` |
| `model` | varchar(50) |
| `call_count` | int |
| `tokens_input` | bigint |
| `tokens_output` | bigint |
| `cost_cents` | int |
| Unique `(date, task_type, model)` | |

### 4.5 `outreach_inbound_messages`

Inbound replies, with confidence score and routing decision. Subset of WhatsApp messages — only the ones we route through the AI inbound flow.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `whatsapp_message_id` | uuid FK → whatsapp_messages.id | |
| `person_id` | uuid FK | |
| `intent` | varchar(50) nullable | e.g. `question`, `complaint`, `acknowledgment`, `unsubscribe` |
| `confidence` | int | 0–100 |
| `routing` | enum('auto_replied','escalated_to_agent','escalated_to_henry') | |
| `auto_reply_draft_id` | uuid FK → ai_drafts.id nullable | Set if routing=auto_replied |
| `assigned_to_user_id` | uuid FK nullable | Set if escalated to agent |
| `created_at` | timestamptz | |

### Deferrable

- `outreach_compliance_rules` — global rule list. v0 ships as a config file (`config/outreach_compliance.php`), promoted to DB if rules need to be edited via UI.

---

## 5. Service layer

### 5.1 `App\Services\AI\ModelRouter`

Single entry point for all AI calls. Per-task model selection from `config/ai.php`:

```php
'tasks' => [
    'outreach_draft_individual'   => 'claude-sonnet-4-6',
    'outreach_draft_bulk'          => 'claude-haiku-4-5',
    'outreach_draft_high_stakes'   => 'claude-sonnet-4-6',  // large clients, retention
    'compliance_check'             => 'claude-haiku-4-5',
    'inbound_classify'             => 'claude-haiku-4-5',
    'inbound_response_draft'       => 'claude-sonnet-4-6',
    'henry_query_complex'          => 'claude-sonnet-4-6',
],
'fallback_chain' => [
    'claude-sonnet-4-6'  => ['claude-haiku-4-5', 'gpt-5.5-mini', 'kimi-2.5'],
    'claude-haiku-4-5'   => ['gpt-5.5-mini'],
],
```

Failover triggers: API timeout, 429 rate limit, 5xx error. External providers configured as separate API clients; only invoked when Anthropic chain exhausted.

### 5.2 `App\Services\AI\DraftService`

```php
$service->draft(
    Person $person,
    OutreachTemplate $template,
    array $extraContext = [],
): AiDraft;
```

- Loads person context (recent activity, financials if accessible, segment, lead status)
- Renders system prompt from template
- Calls `ModelRouter` with the appropriate task name
- Persists `ai_drafts` row with prompt_hash + (full prompt if non-autonomous)
- Triggers `ComplianceAgent` on the result before returning

### 5.3 `App\Services\AI\ComplianceAgent`

A separate Claude Haiku call that audits the draft against:

- **Hard banned phrases** (`config/outreach_compliance.php`):
  - "guaranteed returns", "guaranteed profit", "risk-free", "no risk", "100% success"
  - "easy money", "get rich", "make millions"
  - Specific return promises ("you will make X%")
- **Soft warnings:** unhedged performance claims, urgency manipulation, missing risk disclaimers on financial promotions
- **Required disclosures** (configurable per template — e.g. "trading involves risk" for MFU_MARKETS messages)
- **Tone checks:** no aggressive pressure, no impersonation of regulators

The agent returns `{passed: bool, flags: [...], verdict: text}`. Hard failures **block the send**. Soft warnings are logged but pass.

### 5.4 `App\Services\AI\OutreachOrchestrator`

```php
$orchestrator->autonomousSend(string $event, Person $person, array $context = []): SendResult;
$orchestrator->reviewedDraft(Person $person, ?OutreachTemplate $template, User $agent): AiDraft;
$orchestrator->bulkDraft(Collection $people, OutreachTemplate $template, User $agent): Collection;
```

Owns the full pipeline:

1. `CostCeilingGuard::checkAndAccrue()` — abort if hard limit hit
2. Look up template by event (or use provided one)
3. `DraftService::draft()` → `AiDraft`
4. `ComplianceAgent::check()` → `AiComplianceCheck`
5. If **autonomous + passed**: dispatch `SendWhatsAppMessageJob`, log `Activity`, ping Henry
6. If **autonomous + blocked**: write `Activity` flagged compliance failure, ping Henry urgently
7. If **reviewed**: return draft for UI display, no send

### 5.5 Henry integration — split design

**Important architectural finding:** OpenClaw's gateway exposes RPC over **WebSocket only**, not HTTP. PHP isn't a great fit for long-lived WS connections, and we don't actually need bidirectional reasoning calls from Laravel to Henry in this phase. So Phase 4a uses two separate, simpler transports:

#### `App\Services\Notifications\TelegramNotifier`

Outbound CRM → Werner notifications go via the **Telegram Bot API directly**. Same bot token Henry uses (configured in `~/.openclaw/openclaw.json`); Laravel sends messages from a "CRM" prefix so Werner can tell them apart from Henry's analytical messages.

```php
$telegram->notify(string $message, string $severity = 'info'): bool;
$telegram->isReachable(): bool;
```

POSTs to `https://api.telegram.org/bot<token>/sendMessage`. Bot token + Werner's chat ID from `.env`. Queued (`SendTelegramJob`) for fire-and-forget — never blocks the request flow. Failures logged once per hour, never retried indefinitely.

Why this over going through Henry: simpler (one HTTP POST, no WebSocket), fewer dependencies, no shared failure mode (CRM can alert even when Henry's gateway is down — important for "Henry is down" alerts).

#### `App\Services\Henry\GatewayClient`

For the **other direction** — when *Henry* needs to query the CRM. Henry calls our MCP server (registered in his config), which uses the gateway's HTTP `tools/invoke` endpoint internally. Laravel only needs:

```php
$henry->isReachable(): bool;     // GET /health — for status widget on dashboard
```

That's the entire client surface. The richer interaction is: Henry sees an MCP tool called `market_funded_crm.search_people`, calls it, gets data back, replies to Werner on Telegram. The CRM is the tool provider, not the caller.

If a future phase needs Laravel to trigger Henry's reasoning, we'll add a WebSocket RPC client then. Out of scope now.

### 5.6 `App\Services\Inbound\InboundClassifier`

For WhatsApp replies to AI-sent messages:

1. `ModelRouter::call('inbound_classify', ...)` — returns `{intent, confidence}`
2. If `confidence >= 75` AND `intent` is in `['acknowledgment', 'simple_question_answerable_from_kb']`: draft auto-reply, run compliance, send
3. Else: escalate to assigned agent (if any) or to Henry (Telegram alert) for triage

Initial confidence threshold = 75. Tunable in config. Logged per-event so threshold can be tuned from real data.

---

## 6. Trigger inventory

The 9 autonomous triggers, each gets a default template seeded `autonomous_enabled = false`.

| # | Event | Source | Template default tone |
|---|---|---|---|
| 1 | `lead_created` | `LeadCreated` (new) — fired by `SyncAccountsJob` when person inserted | Welcome + brand intro + how to start |
| 2 | `deposit_first` | `DepositReceived` event — first deposit detected | Congrats + activation steps |
| 3 | `challenge_purchased` | `ChallengePurchased` (new) — fired when CHALLENGE_PURCHASE transaction lands | Instructions + first-day tips |
| 4 | `challenge_passed` | `ChallengeStateChanged` (new) — needs MTR data we don't fully sync yet — **Phase 4.5** | Congrats + funded onboarding |
| 5 | `challenge_failed` | Same as above — **Phase 4.5** | Encouragement + retry offer |
| 6 | `course_purchased` | `CoursePurchased` (new) — fired on MFU_ACADEMY transaction | Congrats + access instructions |
| 7 | `dormant_14d` | Cron job daily 09:00 — `DetectDormantClientsJob` | Re-engagement, light touch |
| 8 | `dormant_30d` | Cron job daily 09:00 | Re-engagement, last-chance |
| 9 | `large_withdrawal` | `LargeWithdrawalReceived` event (already exists) | Retention check-in |

Triggers 4 and 5 depend on data we don't currently sync; they ship as **placeholder templates only**, no event wiring, until Phase 4.5 brings in the equity/state stream.

---

## 7. Filament UI

### 7.1 New resources

- **`OutreachTemplateResource`** — admin CRUD for templates. Includes:
  - Trigger event picker (with descriptions)
  - System prompt editor (textarea with variable hints: `{{first_name}}`, `{{last_deposit_amount}}`, etc.)
  - Compliance rules text field
  - Model override dropdown
  - **Autonomous enabled** toggle — separate confirmation modal warning on enable
  - Test send: pick a person, generate a sample draft without sending

- **`AiDraftResource`** — review queue for `pending_review` drafts. Inline edit + approve + send, or reject. Filter by mode/template/person.

### 7.2 New Person page actions

- **WhatsApp composer:** "Draft with AI" button next to manual send. Opens drawer with generated draft + edit field + compliance flags display. "Send" goes through `MessageSender`.

### 7.3 New bulk action on PersonResource list

- **"Draft re-engagement for selected"** — agent picks a template, system drafts for each selected person (queued), then routes to `AiDraftResource` review queue with bulk-approve action.

### 7.4 New AI Ops page (admin only)

Single page at `/admin/ai-ops`:

- **Spend today** / **Spend this month** / **Soft cap** / **Hard cap** progress bars
- **Autonomous sends today** by template
- **Compliance flags raised today** (count, click to drill into blocked drafts)
- **Henry status** — gateway reachable, last cron run, last alert sent
- **Kill switch** — single button "Pause all autonomous sends" with confirmation. Sets a flag in cache that all triggers check.

### 7.5 Henry status widget on dashboard

Tiny header strip on `/admin`: green dot "Henry online" or red "Henry offline (last seen Xm ago)". Click → `/admin/ai-ops`.

---

## 8. Henry integration details

### 8.1 What Henry can do for the CRM

- Receive **events** (new lead, large deposit, sync failure, compliance flag, cost ceiling reached) and decide what's worth a Telegram alert
- Answer **ad-hoc questions** from Werner via Telegram by querying the CRM's API (e.g., "Henry, how many MFU clients deposited yesterday?")
- Run **scheduled summaries** — morning book health briefing (9am SAST), nightly "yesterday's autonomous sends" digest
- **Escalation target** for inbound replies the AI can't confidently answer

### 8.2 What the CRM exposes for Henry

New API routes under `routes/api.php`, all behind a single shared-secret token (`HENRY_API_TOKEN` in `.env`):

- `GET /api/henry/health` — system status (queue depth, last sync, AI cost ceiling state)
- `GET /api/henry/people/search?q=...` — name/email/phone search
- `GET /api/henry/people/{id}` — full person summary including financials, recent activity, autonomous send history
- `GET /api/henry/metrics/book` — high-level book metrics (deposits today/MTD, active clients, dormant counts)
- `POST /api/henry/events` — Henry can log an event (e.g., "Werner asked me to flag this person for follow-up")
- `POST /api/henry/actions/pause-autonomous` — kill switch (admin token only)

These get registered as an MCP server in `~/.openclaw/openclaw.json` so Henry can use them as native tools.

### 8.3 What the CRM sends to Werner directly (via Telegram)

`TelegramNotifier::notify()` calls fire on:

- Compliance flag raised (severity: warning)
- Autonomous send blocked by compliance (severity: alert)
- Cost ceiling soft cap hit (severity: warning)
- Cost ceiling hard cap hit (severity: critical, autonomy paused)
- MTR sync failure (severity: alert)
- Inbound reply escalated to agent (severity: info)
- Daily summary at 09:00 (severity: info, batched)
- **Henry gateway down** (severity: alert) — this is why we don't route through Henry for these

Messages are prefixed `[MFU CRM]` so Werner can distinguish them from Henry's reasoning output. Henry's analytical/conversational replies (the "morning briefing", "tell me about client X") flow separately through Henry's existing Telegram channel. Two voices, one chat.

### 8.4 Failure modes

- Gateway down: Laravel logs, doesn't retry indefinitely. CRM continues to function.
- Auth invalid: Logged once per hour, doesn't spam.
- Network timeout (>3s): Drop to background queue, retry up to 3 times with exponential backoff, then drop.

---

## 9. Cost ceilings and kill switch

### 9.1 Ceilings

| Cap | Amount | Effect |
|---|---|---|
| **Soft monthly** | $300 | Autonomous sends pause. Reviewed sends continue. Henry alert sent. |
| **Hard monthly** | $500 | All AI calls pause (including compliance and inbound classify). Henry critical alert. |

Configurable in `config/ai.php`. Spend tracked in `ai_usage_log` daily totals + Redis counter for current-month accrual.

### 9.2 `CostCeilingGuard`

Called at the start of every AI request. Returns one of:

- `proceed` — under soft cap
- `pause_autonomous` — over soft cap; rejects autonomous, allows reviewed
- `pause_all` — over hard cap; rejects everything

Resets at the start of each calendar month (Africa/Johannesburg timezone).

### 9.3 Manual kill switch

Admin can pause all autonomous sends via the AI Ops page button. Stored as `ai_autonomy_paused = true` in cache (no DB row needed). Persists until admin unpauses or app restarts (and is restored from a config flag if needed).

---

## 10. Logging and audit policy

Per Werner's compression decision in the conversation:

### 10.1 Always log full

- Final sent text
- Compliance result (passed + flags array)
- Token + cost
- Trigger / mode / who initiated
- Outcome

### 10.2 Trim for autonomous (high volume)

- Don't store `prompt_full` — only `prompt_hash` + template_id + person_id (prompt is reconstructable)
- Don't store separate `draft_text` if it equals `final_text` (no edit happened)

### 10.3 Always log full for reviewed

- Full prompt
- Full draft
- Edit diff if agent edited
- Reviewer user_id + timestamp

### 10.4 Mirror to `Activity`

Every send also creates an `Activity` row (existing table). Activity gets a summary; the full record stays in `ai_drafts`. This keeps the Person timeline readable.

---

## 11. Test strategy

| Layer | Coverage target |
|---|---|
| `ModelRouter` (failover) | Mock provider responses, exercise primary → Haiku → external |
| `DraftService` | Mocked Claude responses, verify prompt construction + DB persistence |
| `ComplianceAgent` | Hand-crafted bad drafts (each banned phrase), verify all caught |
| `OutreachOrchestrator` autonomous path | Full event-to-send integration test with mocked Claude + mocked Meta |
| `OutreachOrchestrator` blocked path | Compliance fail → Activity logged + Henry pinged + no send |
| `HenryGatewayClient` | Mock RPC server, verify notify / event / ask wire correctly; verify graceful degradation when down |
| `CostCeilingGuard` | Boundary cases at soft/hard caps |
| `InboundClassifier` | Confidence boundary (74 vs 76), escalation routing |
| Filament resources | Smoke tests (page loads, action runs) |

Existing 195 tests keep passing. Target: ~30 new tests, total ~225 by end of Phase 4a.

---

## 12. Sequencing

**Total estimate: 12-15 working days** depending on debugging.

### Milestone 1 — Henry foundation (≈3 days)
1. `HenryGatewayClient` service + tests
2. `routes/api.php` for Henry, `HenryApiToken` middleware
3. Add MCP server config to `~/.openclaw/openclaw.json` (manual edit, document in plan)
4. Test: send Werner a Telegram from the CRM ("Hello from MFU CRM, this is a test")
5. Status widget on dashboard

**Demo to Werner:** "Henry, how many leads did we have this week?" answered correctly via Telegram.

### Milestone 2 — AI outreach core (≈4 days)
1. Migrations for 5 new tables
2. `config/ai.php` and `config/outreach_compliance.php`
3. `ModelRouter` with primary + Haiku fallback (external providers stubbed)
4. `DraftService` + `ComplianceAgent` + `CostCeilingGuard`
5. `OutreachOrchestrator` (reviewed mode only first)
6. Tests for all of the above

**Demo:** Run a one-off draft via Tinker, confirm DB rows + cost tracked.

### Milestone 3 — Filament UI (≈3 days)
1. `OutreachTemplateResource`
2. `AiDraftResource` review queue
3. "Draft with AI" button in Person WhatsApp composer
4. Bulk draft action on Person list
5. AI Ops page + kill switch

**Demo to Werner:** Click "Draft with AI" on a real person, edit, send, see Activity row.

### Milestone 4 — Autonomous triggers (≈3 days)
1. Wire 7 triggers (1, 2, 3, 6, 7, 8, 9 — skip 4 & 5 for Phase 4.5)
2. `LeadCreated`, `ChallengePurchased`, `CoursePurchased` events (new)
3. `DetectDormantClientsJob` daily cron
4. End-to-end test: simulate event, verify autonomous send chain
5. Henry hooks: blocked-by-compliance, cost-cap, daily summary

**Demo:** Toggle one template `autonomous_enabled = true`, fire the event manually, watch the message send + Telegram alert.

### Milestone 5 — Inbound auto-response (≈2 days)
1. `InboundClassifier` + tests
2. `RouteToAgentListener` (replace stub)
3. Confidence-based routing
4. Test cases for boundary confidence

**Demo:** Reply to a sent AI message, watch auto-reply or escalation fire.

### Final tag

Tag `v1.3.0` after all 5 milestones pass smoke tests and Werner has spent at least 3 days using the system.

---

## 13. Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Compliance agent misses a banned phrase | Medium | High (regulatory) | Hard regex blocklist runs *before* AI compliance check; daily admin audit of autonomous sends; soft cap forces human eyes early |
| Anthropic API outage | Low | High | Failover chain (Sonnet → Haiku → external) — Phase 4a stubs externals; wire when needed |
| Cost runaway (bug, infinite loop) | Low | High | Hard cap at $500; kill switch; Henry monitoring monthly spend |
| Prompt injection from inbound message | Medium | Medium | Inbound messages classified, never appended to outbound prompts directly; templates explicit about input boundaries |
| Henry gateway local-only | Certain | Low (this phase) | Documented; for Phase 4b/production, add tunnel or move gateway to VPS |
| WhatsApp templates not approved | Existing blocker | High | Phase 4a designs around it. Test with Werner's number until templates land |
| Cloudflare MTR block still active | Existing blocker | Medium | Phase 4a develops on local data; production sync separately blocked |
| AI draft quality is poor | Medium | Medium | Reviewed mode for first 2 weeks; tune prompts based on real data; revisit model choice per task |
| Autonomous send to wrong person | Low | High | Person ID is the trigger source; templates render with person context only; tests cover person-context boundaries |

---

## 14. Configuration files

New / changed:

- `config/ai.php` — task → model mapping, fallback chain, cost ceilings, confidence threshold
- `config/outreach_compliance.php` — banned phrases (regex array), required disclosures
- `.env` additions:
  - `HENRY_GATEWAY_URL=http://localhost:18789` (for `/health` reachability check + future use)
  - `HENRY_API_TOKEN=...` (token Henry's MCP server presents when calling our `/api/henry/*` routes — generated by us)
  - `TELEGRAM_BOT_TOKEN=...` (same bot Henry uses, copied from `~/.openclaw/openclaw.json`)
  - `TELEGRAM_CHAT_ID=2107007918` (Werner's chat ID)
  - `ANTHROPIC_API_KEY=...` (added at start of milestone 2)
  - `AI_AUTONOMOUS_PAUSED=false`
  - `AI_COST_SOFT_CAP_USD=300`
  - `AI_COST_HARD_CAP_USD=500`
- `~/.openclaw/openclaw.json` — register `market-funded-crm` MCP server pointing at the CRM API

---

## 15. Open decisions before kickoff

- [ ] Confirm $300 soft / $500 hard cap (or pick different)
- [ ] Confirm initial confidence threshold for inbound = 75
- [ ] Confirm 7-trigger v1 scope (excluding challenge_passed/failed)
- [ ] Confirm `Activity` row creation per AI send (default yes)
- [ ] Werner reviews compliance banned-phrase list before milestone 2 ships

---

## 16. What this plan does NOT cover

- **Phase 4b voice agent.** Separate plan. Decision Dograh vs Vapi/Retell deferred until 4a is in production.
- **Phase 4.5 health-score factors 5 & 6.** Equity snapshot pipeline (gRPC vs REST). Separate plan.
- **Email outreach.** Same architecture, follows after WhatsApp ships and is stable. Probably 2-3 days of work.
- **Production deployment of Henry gateway.** Henry is local-Mac-only this phase; if Phase 4a needs to run on production droplet, that's an extra workstream — current plan assumes local development.

---

*End of plan. Awaiting Werner's review and sign-off.*
