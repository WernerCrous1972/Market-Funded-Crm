<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\BranchNotDraftReadyException;
use App\Models\Activity;
use App\Models\AiDraft;
use App\Models\OutreachInboundMessage;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\Inbound\InboundClassification;
use App\Services\Notifications\TelegramNotifier;
use App\Services\WhatsApp\MessageSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the full draft-with-compliance pipeline.
 *
 * Phase 4a milestone 2 ships REVIEWED + BULK_REVIEWED only — those are
 * the agent-initiated paths a human owner has clicked. Autonomous wiring
 * (event-driven send + Activity logging + Henry alerts) lands in
 * milestone 4 when triggers go live.
 *
 * Public surface:
 *   reviewedDraft($person, $template, $user, $extra) → AiDraft
 *   bulkReviewedDrafts($people, $template, $user, $extra) → Collection<AiDraft>
 *
 * On every call:
 *   1. CostCeilingGuard::allowsAnyCall() — abort fast if hard cap hit
 *   2. DraftService::draft()             — persists AiDraft (pending_review)
 *   3. ComplianceAgent::check()          — persists check, blocks the draft
 *                                          on fail (status → blocked_compliance)
 *   4. Returns the AiDraft with compliance_check loaded
 *
 * If the cost-cap guard refuses, no draft is created — we fail-fast at
 * the boundary rather than persisting a half-row. Caller gets an
 * AiOrchestratorException.
 */
class OutreachOrchestrator
{
    private ?OutreachTemplate $cachedAutoReplyTemplate = null;
    private bool $autoReplyTemplateResolved = false;

    public function __construct(
        private readonly DraftService $drafts,
        private readonly ComplianceAgent $compliance,
        private readonly CostCeilingGuard $guard,
        private readonly MessageSender $messageSender,
        private readonly TelegramNotifier $telegram,
    ) {}

    /**
     * Agent clicked "Draft with AI" on one person.
     *
     * @param  array<string, mixed>  $extraContext
     *
     * @throws AiOrchestratorException  when the cost ceiling refuses the call
     */
    public function reviewedDraft(
        Person $person,
        OutreachTemplate $template,
        ?User $triggeredBy = null,
        array $extraContext = [],
    ): AiDraft {
        $this->ensureCostAllowed();

        try {
            $draft = $this->drafts->draft(
                $person,
                $template,
                mode: AiDraft::MODE_REVIEWED,
                extraContext: $extraContext,
                triggeredByUserId: $triggeredBy?->id,
            );
        } catch (BranchNotDraftReadyException $e) {
            $this->alertBranchNotReady($e, 'reviewedDraft');
            throw $e;
        }

        $this->compliance->check($draft, pipelineHint: $this->pipelineFor($person));

        return $draft->fresh(['complianceCheck', 'template']);
    }

    /**
     * Agent ran a bulk-draft action on a filtered list.
     *
     * Each draft is independent; one failing doesn't stop the others. Per-
     * recipient errors get caught and recorded as blocked drafts (with the
     * exception message in the compliance flags) so the agent can see what
     * went wrong in the review queue.
     *
     * @param  Collection<int, Person>  $people
     * @param  array<string, mixed>     $extraContext
     *
     * @return Collection<int, AiDraft>
     *
     * @throws AiOrchestratorException  when the cost ceiling refuses ALL calls
     *                                  (we check once up-front; if a later call
     *                                  pushes us over, that draft will fail at
     *                                  the next loop iteration)
     */
    public function bulkReviewedDrafts(
        Collection $people,
        OutreachTemplate $template,
        ?User $triggeredBy = null,
        array $extraContext = [],
    ): Collection {
        $this->ensureCostAllowed();

        $results = collect();

        foreach ($people as $person) {
            try {
                $results->push($this->reviewedDraftBulkInner($person, $template, $triggeredBy, $extraContext));
            } catch (AiOrchestratorException $e) {
                // Cost ceiling tripped mid-loop — stop, return what we have.
                break;
            } catch (\Throwable $e) {
                // Per-person error — skip but continue. Caller can re-run
                // for the missing rows.
                report($e);
                continue;
            }
        }

        return $results;
    }

    /**
     * Autonomous, event-driven send. Caller is a listener that has already
     * decided "this event matched this template". We run the full pipeline:
     *
     *   1. CostCeilingGuard::allowsAutonomous()    — blocks soft+hard cap
     *   2. Template's `autonomous_enabled` toggle   — admin opt-in gate
     *   3. DraftService::draft (mode=AUTONOMOUS)
     *   4. ComplianceAgent::check
     *   5. Either: dispatch via MessageSender + Activity log + status=sent
     *   6. Or:     leave status=blocked_compliance + Telegram alert + Activity log
     *
     * Returns the persisted AiDraft regardless of outcome — caller can inspect
     * `status` to see what happened. Returns NULL only when the autonomous
     * gate refused before a draft was even created (cost cap or template
     * not enabled), so the caller can no-op.
     *
     * Never throws on per-person errors — listeners must be resilient. We
     * log + Telegram-alert on hard failures so they're visible.
     */
    public function autonomousSend(
        Person $person,
        OutreachTemplate $template,
        string $triggerEvent,
        array $extraContext = [],
    ): ?AiDraft {
        // Gate 1: template must be opted in
        if (! $template->autonomous_enabled) {
            Log::debug('OutreachOrchestrator::autonomousSend skipped — template not autonomous_enabled', [
                'template_id'   => $template->id,
                'trigger_event' => $triggerEvent,
                'person_id'     => $person->id,
            ]);
            return null;
        }
        if (! $template->is_active) {
            return null;
        }

        // Gate 2: cost ceiling refuses autonomous (soft cap or hard cap or kill switch)
        if (! $this->guard->allowsAutonomous()) {
            $state = $this->guard->check();
            Log::warning('OutreachOrchestrator::autonomousSend blocked by cost guard', [
                'state'         => $state->value,
                'template_id'   => $template->id,
                'trigger_event' => $triggerEvent,
                'person_id'     => $person->id,
            ]);
            // Don't spam Telegram on every blocked autonomous send — the caller
            // (a listener) usually fires per-person on a daily run. The cost-cap
            // hit notification fires once when the cap is crossed (wired
            // separately in chunk 4d).
            return null;
        }

        // Build draft + run compliance
        try {
            $draft = $this->drafts->draft(
                $person,
                $template,
                mode: AiDraft::MODE_AUTONOMOUS,
                extraContext: $extraContext,
                triggeredByEvent: $triggerEvent,
            );
            $this->compliance->check($draft, pipelineHint: $this->pipelineFor($person));
            $draft->refresh();
        } catch (BranchNotDraftReadyException $e) {
            $this->alertBranchNotReady($e, "autonomousSend trigger={$triggerEvent}");
            return null;
        } catch (\Throwable $e) {
            // Draft pipeline itself errored (router exhausted, DB issue,
            // unparseable verdict). Log + Telegram + bail.
            Log::error('OutreachOrchestrator::autonomousSend pipeline error', [
                'template_id'   => $template->id,
                'trigger_event' => $triggerEvent,
                'person_id'     => $person->id,
                'error'         => $e->getMessage(),
            ]);
            $this->telegram->notify(
                "Autonomous draft pipeline error for trigger {$triggerEvent} on person {$person->id}: " . $e->getMessage(),
                'alert',
            );
            return null;
        }

        // Compliance blocked → log Activity + Telegram, no send
        if ($draft->status === AiDraft::STATUS_BLOCKED_COMPLIANCE) {
            $this->logActivity(
                $person,
                'STATUS_CHANGED',
                "Autonomous draft for {$triggerEvent} blocked by compliance: " .
                    ($draft->complianceCheck?->verdict_text ?? 'no verdict'),
                [
                    'draft_id'      => $draft->id,
                    'trigger_event' => $triggerEvent,
                    'flags'         => $draft->complianceCheck?->flags ?? [],
                ],
            );

            $this->telegram->notify(
                "Autonomous send BLOCKED by compliance.\n" .
                "Trigger: {$triggerEvent}\n" .
                "Person: {$person->email}\n" .
                "Verdict: " . ($draft->complianceCheck?->verdict_text ?? 'no verdict'),
                'alert',
            );
            return $draft;
        }

        // Compliance passed → dispatch send + Activity + status=sent
        try {
            $finalText = $draft->draft_text;
            $draft->final_text = $finalText;
            $draft->status     = AiDraft::STATUS_APPROVED;
            $draft->save();

            $this->messageSender->send(
                person: $person,
                body:   $finalText,
                sentByUser: null, // autonomous — no human
            );

            $draft->status  = AiDraft::STATUS_SENT;
            $draft->sent_at = now();
            $draft->save();

            $this->logActivity(
                $person,
                'WHATSAPP_SENT',
                "Autonomous WhatsApp sent for {$triggerEvent}",
                [
                    'draft_id'      => $draft->id,
                    'trigger_event' => $triggerEvent,
                    'channel'       => $template->channel,
                    'cost_cents'    => $draft->cost_cents,
                ],
            );
        } catch (\Throwable $e) {
            $draft->status = AiDraft::STATUS_FAILED;
            $draft->save();
            Log::error('OutreachOrchestrator::autonomousSend dispatch failed', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
            $this->telegram->notify(
                "Autonomous send DISPATCH FAILED for {$triggerEvent} on person {$person->email}: " . $e->getMessage(),
                'alert',
            );
        }

        return $draft;
    }

    /**
     * Inbound auto-reply path. Caller (RouteToAgentListener) has already run
     * the classifier and decided this reply is "safe + confident enough" to
     * answer with AI. We:
     *
     *   1. Cost-cap check  — skip to escalation if AI work is paused
     *   2. Draft a reply via the system inbound auto-reply template
     *   3. Compliance gate
     *   4. If passed: dispatch via MessageSender + Activity log + status=sent
     *      If blocked: leave status=blocked_compliance, fall through to
     *      escalation so the client still gets a response
     *   5. Persist outreach_inbound_messages row tying it all together
     *
     * Returns the OutreachInboundMessage row.
     */
    public function inboundAutoReply(
        Person $person,
        WhatsAppMessage $inboundMessage,
        InboundClassification $classification,
    ): OutreachInboundMessage {
        // Cost guard — autonomous-class call. If paused, fall through to
        // escalation so the client still gets the holding reply.
        if (! $this->guard->allowsAutonomous()) {
            return $this->inboundEscalation($person, $inboundMessage, $classification);
        }

        $template = $this->autoReplyTemplate();
        if (! $template) {
            // Misconfiguration — system template wasn't seeded. Fail safe
            // by escalating so the client doesn't sit in silence.
            Log::error('OutreachOrchestrator::inboundAutoReply: system inbound template missing', [
                'expected_name' => config('outreach_inbound.auto_reply_template_name'),
            ]);
            return $this->inboundEscalation($person, $inboundMessage, $classification);
        }

        try {
            $draft = $this->drafts->draft(
                $person,
                $template,
                mode: AiDraft::MODE_AUTONOMOUS,
                extraContext: [
                    'inbound_message_text' => $inboundMessage->body_text ?? '',
                    'classified_intent'    => $classification->intent,
                    'classified_confidence' => $classification->confidence,
                ],
                triggeredByEvent: 'inbound_reply',
            );
            $this->compliance->check($draft, pipelineHint: $this->pipelineFor($person));
            $draft->refresh();
        } catch (\Throwable $e) {
            Log::error('OutreachOrchestrator::inboundAutoReply: draft pipeline error', [
                'person_id' => $person->id,
                'inbound_message_id' => $inboundMessage->id,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->notify(
                "Inbound auto-reply pipeline error for {$person->email}: " . $e->getMessage(),
                'alert',
            );
            return $this->inboundEscalation($person, $inboundMessage, $classification);
        }

        // Compliance blocked → fall through to escalation. The client still
        // needs a response; the holding message is safe.
        if ($draft->status === AiDraft::STATUS_BLOCKED_COMPLIANCE) {
            Log::warning('OutreachOrchestrator::inboundAutoReply: AI reply blocked by compliance, escalating', [
                'draft_id' => $draft->id,
                'person_id' => $person->id,
            ]);
            return $this->inboundEscalation($person, $inboundMessage, $classification, blockedDraft: $draft);
        }

        // Compliance passed → send the AI reply
        try {
            $finalText = $draft->draft_text;
            $draft->final_text = $finalText;
            $draft->status     = AiDraft::STATUS_APPROVED;
            $draft->save();

            $this->messageSender->send(
                person:     $person,
                body:       $finalText,
                sentByUser: null,
            );

            $draft->status  = AiDraft::STATUS_SENT;
            $draft->sent_at = now();
            $draft->save();

            $this->logActivity(
                $person,
                'WHATSAPP_SENT',
                "Inbound AI auto-reply sent (intent: {$classification->intent}, confidence: {$classification->confidence})",
                [
                    'draft_id'   => $draft->id,
                    'inbound_message_id' => $inboundMessage->id,
                    'intent'     => $classification->intent,
                    'confidence' => $classification->confidence,
                    'cost_cents' => $draft->cost_cents,
                ],
            );
        } catch (\Throwable $e) {
            $draft->status = AiDraft::STATUS_FAILED;
            $draft->save();
            Log::error('OutreachOrchestrator::inboundAutoReply: dispatch failed', [
                'draft_id' => $draft->id,
                'error'    => $e->getMessage(),
            ]);
            $this->telegram->notify(
                "Inbound auto-reply DISPATCH FAILED for {$person->email}: " . $e->getMessage(),
                'alert',
            );
            return $this->inboundEscalation($person, $inboundMessage, $classification);
        }

        return OutreachInboundMessage::create([
            'whatsapp_message_id' => $inboundMessage->id,
            'person_id'           => $person->id,
            'intent'              => $classification->intent,
            'confidence'          => $classification->confidence,
            'routing'             => OutreachInboundMessage::ROUTING_AUTO_REPLIED,
            'auto_reply_draft_id' => $draft->id,
            'assigned_to_user_id' => null,
            'created_at'          => now(),
        ]);
    }

    /**
     * Inbound escalation path. Sends a short, pre-written holding message to
     * the client (via MessageSender → no-op while WA is disabled), then fires
     * a Telegram alert to the assigned account manager OR Henry if no manager
     * is set. Persists the routing decision.
     *
     * `$blockedDraft` is set when this path is reached because an AI auto-
     * reply was generated but blocked by compliance — it still gets linked
     * to the inbound row so we can audit the chain.
     */
    public function inboundEscalation(
        Person $person,
        WhatsAppMessage $inboundMessage,
        InboundClassification $classification,
        ?AiDraft $blockedDraft = null,
    ): OutreachInboundMessage {
        // Pick holding message by intent
        $holdingText = (string) (
            config("outreach_inbound.holding_messages.{$classification->intent}")
            ?? config('outreach_inbound.holding_messages.default')
            ?? 'Thanks for your message — we will get back to you shortly.'
        );

        // Send the holding message. Failures are logged but don't stop the
        // escalation alert — a missed holding reply is better than no human
        // ever seeing the inbound.
        try {
            $this->messageSender->send(
                person:     $person,
                body:       $holdingText,
                sentByUser: null,
            );
            $this->logActivity(
                $person,
                'WHATSAPP_SENT',
                "Inbound holding reply sent (intent: {$classification->intent}, confidence: {$classification->confidence})",
                [
                    'inbound_message_id' => $inboundMessage->id,
                    'intent'             => $classification->intent,
                    'confidence'         => $classification->confidence,
                    'holding_message'    => $holdingText,
                    'auto_reply_blocked' => $blockedDraft !== null,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('OutreachOrchestrator::inboundEscalation: holding reply dispatch failed (continuing to alert)', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);
        }

        // Decide routing target: assigned manager > Henry
        $assignedUserId = $person->account_manager_user_id ?? null;
        $routing = $assignedUserId
            ? OutreachInboundMessage::ROUTING_ESCALATED_TO_AGENT
            : OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY;

        // Telegram alert. Includes original message + which intent/confidence
        // triggered the escalation + assignee.
        $assigneeLabel = $assignedUserId
            ? ($person->accountManager?->name ?? "user {$assignedUserId}")
            : 'Henry (no assigned manager)';

        $personName = trim("{$person->first_name} {$person->last_name}") ?: $person->email ?: $person->id;
        $excerpt    = mb_substr((string) ($inboundMessage->body_text ?? ''), 0, 200);

        $alertLines = [
            "Inbound escalated to: {$assigneeLabel}",
            "From: {$personName}",
            "Intent: {$classification->intent} ({$classification->confidence}%)",
            "Message: {$excerpt}",
            "Holding reply sent: yes",
        ];
        if ($blockedDraft) {
            $alertLines[] = "Note: AI auto-reply was generated but BLOCKED by compliance (draft {$blockedDraft->id}).";
        }

        $this->telegram->notify(implode("\n", $alertLines), 'warning');

        return OutreachInboundMessage::create([
            'whatsapp_message_id' => $inboundMessage->id,
            'person_id'           => $person->id,
            'intent'              => $classification->intent,
            'confidence'          => $classification->confidence,
            'routing'             => $routing,
            'auto_reply_draft_id' => $blockedDraft?->id,
            'assigned_to_user_id' => $assignedUserId,
            'created_at'          => now(),
        ]);
    }

    /**
     * Look up the seeded system inbound auto-reply template by name. Cached
     * per request so we don't re-query on every reply.
     */
    private function autoReplyTemplate(): ?OutreachTemplate
    {
        if ($this->autoReplyTemplateResolved) {
            return $this->cachedAutoReplyTemplate;
        }
        $this->autoReplyTemplateResolved = true;
        $name = (string) config('outreach_inbound.auto_reply_template_name');
        $this->cachedAutoReplyTemplate = OutreachTemplate::query()
            ->where('name', $name)
            ->where('is_active', true)
            ->first();
        return $this->cachedAutoReplyTemplate;
    }

    /**
     * Activity row helper. Kept defensive so a logging miss never aborts the
     * autonomous flow itself.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function logActivity(Person $person, string $type, string $description, array $metadata = []): void
    {
        try {
            Activity::create([
                'person_id'   => $person->id,
                'type'        => $type,
                'description' => $description,
                'metadata'    => $metadata,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OutreachOrchestrator: Activity write failed (non-fatal)', [
                'person_id' => $person->id,
                'type'      => $type,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function reviewedDraftBulkInner(
        Person $person,
        OutreachTemplate $template,
        ?User $triggeredBy,
        array $extraContext,
    ): AiDraft {
        $this->ensureCostAllowed();

        try {
            $draft = $this->drafts->draft(
                $person,
                $template,
                mode: AiDraft::MODE_BULK_REVIEWED,
                extraContext: $extraContext,
                triggeredByUserId: $triggeredBy?->id,
            );
        } catch (BranchNotDraftReadyException $e) {
            $this->alertBranchNotReady($e, 'bulkReviewedDraft');
            throw $e;
        }

        $this->compliance->check($draft, pipelineHint: $this->pipelineFor($person));

        return $draft->fresh(['complianceCheck', 'template']);
    }

    private function ensureCostAllowed(): void
    {
        if (! $this->guard->allowsAnyCall()) {
            throw new AiOrchestratorException(
                'AI calls are paused: ' . $this->guard->check()->value,
            );
        }
    }

    /**
     * Fire a Henry-routed Telegram alert when a draft attempt fails because
     * the person's branch isn't ready (no branch, outreach disabled, or
     * persona unset). The data needs Werner's eyes; the call itself is
     * always swallowed by callers, so this is the only signal that surfaces.
     */
    private function alertBranchNotReady(BranchNotDraftReadyException $e, string $callsite): void
    {
        Log::warning('Outreach: branch not draft-ready', [
            'callsite'    => $callsite,
            'reason'      => $e->reason,
            'person_id'   => $e->person->id,
            'person_email'=> $e->person->email,
            'branch_name' => $e->branchName,
        ]);

        $this->telegram->notify(
            "Branch not draft-ready ({$e->reason}).\n" .
            "Person: {$e->person->email} (id {$e->person->id})\n" .
            "Branch: " . ($e->branchName ?? '(none assigned)') . "\n" .
            "Callsite: {$callsite}\n" .
            "Action: review branch persona / outreach_enabled, then retry.",
            'alert',
        );
    }

    /**
     * Pick a pipeline hint for the compliance check, falling back to the
     * template's name if person.metrics doesn't tell us anything useful.
     * Markets gets the strictest disclosures — preferred when ambiguous.
     */
    private function pipelineFor(Person $person): ?string
    {
        $metric = $person->metrics;
        if (! $metric) {
            return null;
        }
        if ($metric->has_markets ?? false) return 'MFU_MARKETS';
        if ($metric->has_capital ?? false) return 'MFU_CAPITAL';
        if ($metric->has_academy ?? false) return 'MFU_ACADEMY';
        return null;
    }
}
