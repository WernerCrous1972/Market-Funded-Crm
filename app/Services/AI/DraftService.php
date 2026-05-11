<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\BranchNotDraftReadyException;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\Transaction;

/**
 * Generates an AI draft message for one person from one template.
 *
 *   draft($person, $template, $mode, $extraContext = []) → AiDraft
 *
 * The orchestrator (chunk 4) wraps this with cost-cap checks, compliance
 * runs, and send dispatch. DraftService itself only:
 *
 *   1. Builds person context (financials, segments, recent activity)
 *   2. Renders user message + picks system prompt
 *   3. Calls ModelRouter with the right task name
 *   4. Persists an ai_drafts row with status=pending_review
 *   5. Returns the saved row
 *
 * Compliance is NOT run here — that's a separate concern (ComplianceAgent),
 * orchestrated outside.
 */
class DraftService
{
    public function __construct(
        private readonly ModelRouter $router,
    ) {}

    public function draft(
        Person $person,
        OutreachTemplate $template,
        string $mode = AiDraft::MODE_REVIEWED,
        array $extraContext = [],
        ?string $triggeredByEvent = null,
        ?string $triggeredByUserId = null,
    ): AiDraft {
        $taskName     = $this->taskNameFor($template, $mode);
        $systemPrompt = $this->resolveSystemPrompt($person, $template);
        $userMsg      = $this->buildUserMessage($person, $template, $extraContext);
        $promptRaw    = $systemPrompt . "\n\n--USER--\n" . $userMsg;

        $response = $this->router->call(
            task:        $taskName,
            system:      $systemPrompt,
            messages:    [['role' => 'user', 'content' => $userMsg]],
            max_tokens:  1024,
        );

        // Compress per the plan: AUTONOMOUS rows skip prompt_full to save DB
        // bytes (it's reconstructable from template + person at the time).
        // REVIEWED rows store the full prompt for audit/forensics.
        $storeFullPrompt = $mode !== AiDraft::MODE_AUTONOMOUS;

        return AiDraft::create([
            'person_id'           => $person->id,
            'template_id'         => $template->id,
            'mode'                => $mode,
            'channel'             => $template->channel,
            'model_used'          => $response->model_used,
            'prompt_hash'         => hash('sha256', $promptRaw),
            'prompt_full'         => $storeFullPrompt ? $promptRaw : null,
            'draft_text'          => $response->text,
            'final_text'          => null,
            'status'              => AiDraft::STATUS_PENDING_REVIEW,
            'triggered_by_event'  => $triggeredByEvent,
            'triggered_by_user_id' => $triggeredByUserId,
            'tokens_input'        => $response->tokens_input,
            'tokens_output'       => $response->tokens_output,
            'cost_cents'          => $response->cost_cents,
        ]);
    }

    /**
     * Resolve the template's system_prompt by substituting branch-persona
     * tokens with the person's branch context. Tokens supported:
     *
     *   {{ persona_name }}    → branch.persona_name        (e.g. "Alex")
     *   {{ branch_brand }}    → branch.customer_facing_name (fallback branch.name)
     *   {{ persona_signoff }} → branch.resolvedSignoff()    (full line)
     *
     * Guards (raise BranchNotDraftReadyException so the orchestrator can
     * route a Henry alert):
     *   - person has no branch_id                  → missing_branch
     *   - branch.outreach_enabled = false          → outreach_disabled
     *   - branch.persona_name is null              → persona_unset
     *
     * The inbound auto-reply template (trigger_event=null) is exempted from
     * persona guards — that path is system-driven and uses a fixed system
     * persona, not a branch-scoped one.
     */
    private function resolveSystemPrompt(Person $person, OutreachTemplate $template): string
    {
        $prompt = (string) $template->system_prompt;

        // Inbound auto-reply path is system-scoped, no persona substitution.
        if ($template->trigger_event === null) {
            return $prompt;
        }

        $branch = $person->branchModel;
        if (! $branch) {
            throw new BranchNotDraftReadyException(
                $person,
                BranchNotDraftReadyException::REASON_MISSING_BRANCH,
            );
        }
        if (! $branch->outreach_enabled) {
            throw new BranchNotDraftReadyException(
                $person,
                BranchNotDraftReadyException::REASON_OUTREACH_DISABLED,
                $branch->name,
            );
        }
        $signoff = $branch->resolvedSignoff();
        if ($signoff === null) {
            throw new BranchNotDraftReadyException(
                $person,
                BranchNotDraftReadyException::REASON_PERSONA_UNSET,
                $branch->name,
            );
        }

        return strtr($prompt, [
            '{{ persona_name }}'    => (string) $branch->persona_name,
            '{{ branch_brand }}'    => (string) ($branch->customer_facing_name ?: $branch->name),
            '{{ persona_signoff }}' => $signoff,
        ]);
    }

    /**
     * Pick the ModelRouter task name based on mode + template override.
     *
     * Per-template `model_preference` is handled by the router itself via
     * task lookup — we just pass the right task name and the router maps
     * to the model.
     */
    private function taskNameFor(OutreachTemplate $template, string $mode): string
    {
        if ($mode === AiDraft::MODE_BULK_REVIEWED) {
            return 'outreach_draft_bulk';
        }
        return 'outreach_draft_individual';
    }

    /**
     * Build the user message — a structured snapshot of the person's state
     * the model can reason about. Keep it terse; we pay per token.
     *
     * @param  array<string, mixed>  $extraContext
     */
    private function buildUserMessage(Person $person, OutreachTemplate $template, array $extraContext): string
    {
        $sections = [];

        $sections[] = "## Recipient";
        $sections[] = sprintf(
            "name: %s\nemail: %s\ncountry: %s\ncontact_type: %s\nlead_status: %s\nbranch: %s",
            trim("{$person->first_name} {$person->last_name}") ?: '(unknown)',
            $person->email ?? '(none)',
            $person->country ?? '(unknown)',
            $person->contact_type,
            $person->lead_status ?? '(none)',
            $person->branch ?? '(none)',
        );

        $metric = $person->metrics; // hasOne PersonMetric (singular row)
        if ($metric) {
            $sections[] = "\n## Financial summary";
            $sections[] = sprintf(
                "total_deposits_usd: %.2f\ntotal_withdrawals_usd: %.2f\nnet_deposits_usd: %.2f\ndays_since_last_deposit: %s\ndays_since_last_login: %s\nsegments: %s",
                ($metric->total_deposits_cents ?? 0) / 100,
                ($metric->total_withdrawals_cents ?? 0) / 100,
                ($metric->net_deposits_cents ?? 0) / 100,
                $metric->days_since_last_deposit ?? 'unknown',
                $metric->days_since_last_login ?? 'unknown',
                $this->segmentsFor($metric),
            );
        }

        $recent = Transaction::where('person_id', $person->id)
            ->where('status', 'DONE')
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get(['category', 'amount_cents', 'currency', 'occurred_at']);
        if ($recent->isNotEmpty()) {
            $sections[] = "\n## Recent transactions (latest 5)";
            foreach ($recent as $t) {
                $sections[] = sprintf(
                    '- %s: %s %.2f %s',
                    $t->occurred_at?->toDateString() ?? 'unknown',
                    $t->category,
                    $t->amount_cents / 100,
                    $t->currency,
                );
            }
        }

        if ($template->trigger_event) {
            $sections[] = "\n## Trigger";
            $sections[] = "event: {$template->trigger_event}";
        }

        if (! empty($extraContext)) {
            $sections[] = "\n## Additional context";
            foreach ($extraContext as $key => $value) {
                $renderable = is_scalar($value) ? (string) $value : json_encode($value);
                $sections[] = "{$key}: {$renderable}";
            }
        }

        if ($template->compliance_rules) {
            $sections[] = "\n## Per-template compliance rules (must respect)";
            $sections[] = $template->compliance_rules;
        }

        $sections[] = "\n## Output";
        $sections[] = 'Write the message text only. No subject line, no JSON, no markdown headings. Plain text the recipient would read.';

        return implode("\n", $sections);
    }

    private function segmentsFor($metric): string
    {
        $segs = [];
        if ($metric->has_markets ?? false)  $segs[] = 'MFU_MARKETS';
        if ($metric->has_capital ?? false)  $segs[] = 'MFU_CAPITAL';
        if ($metric->has_academy ?? false)  $segs[] = 'MFU_ACADEMY';
        return $segs ? implode(', ', $segs) : 'none';
    }
}
