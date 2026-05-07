<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;
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

        $draft = $this->drafts->draft(
            $person,
            $template,
            mode: AiDraft::MODE_REVIEWED,
            extraContext: $extraContext,
            triggeredByUserId: $triggeredBy?->id,
        );

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

        $draft = $this->drafts->draft(
            $person,
            $template,
            mode: AiDraft::MODE_BULK_REVIEWED,
            extraContext: $extraContext,
            triggeredByUserId: $triggeredBy?->id,
        );

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
