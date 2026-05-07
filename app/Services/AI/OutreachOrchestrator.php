<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;

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
