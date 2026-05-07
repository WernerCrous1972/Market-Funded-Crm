<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiComplianceCheck;
use App\Models\AiDraft;
use Illuminate\Support\Facades\Log;

/**
 * Two-layer compliance gate for outbound AI-generated messages.
 *
 *   1. Hard regex blocklist (config/outreach_compliance.php) — runs FIRST.
 *      Any match → passed=false, draft is blocked. No AI involvement.
 *
 *   2. AI self-check via ModelRouter (Haiku, cheap) — runs SECOND. Reads
 *      the soft-warning rules + per-template rules + required disclosures
 *      and returns a JSON verdict {passed, flags[], verdict}.
 *
 * Both layers contribute flags to the same `flags` jsonb on the resulting
 * ai_compliance_checks row. A single passed=false on either layer blocks
 * the send.
 *
 * Rationale: regex catches the cheap, definite cases without burning a
 * model call. The AI catches subtler issues (style, tone, missing
 * disclaimers) the regex can't reason about.
 */
class ComplianceAgent
{
    public function __construct(
        private readonly ModelRouter $router,
    ) {}

    public function check(AiDraft $draft, ?string $pipelineHint = null): AiComplianceCheck
    {
        // Layer 1 — hard regex blocklist
        $hardFlags = $this->runHardBlocklist($draft->draft_text);
        $hardPassed = empty($hardFlags);

        // Layer 2 — AI self-check (only if layer 1 passed; otherwise we
        // already know the answer and a model call is wasted spend).
        $aiFlags    = [];
        $aiPassed   = true;
        $aiVerdict  = '';
        $tokensIn   = 0;
        $tokensOut  = 0;
        $costCents  = 0;
        $modelUsed  = '(skipped)';

        if ($hardPassed) {
            try {
                [$aiFlags, $aiPassed, $aiVerdict, $tokensIn, $tokensOut, $costCents, $modelUsed] =
                    $this->runAiSelfCheck($draft, $pipelineHint);
            } catch (\Throwable $e) {
                // If the AI check itself failed (router exhausted, etc.) we
                // FAIL CLOSED — better to block than send unchecked content.
                Log::warning('ComplianceAgent: AI self-check failed; failing closed', [
                    'draft_id' => $draft->id,
                    'error'    => $e->getMessage(),
                ]);
                $aiFlags = [[
                    'rule'     => 'ai_check_unavailable',
                    'severity' => 'hard',
                    'excerpt'  => substr($e->getMessage(), 0, 80),
                ]];
                $aiPassed  = false;
                $aiVerdict = 'AI compliance check unavailable; blocking by default.';
            }
        }

        $allFlags = array_merge($hardFlags, $aiFlags);

        // Final pass/fail derives from FLAG SEVERITY, not the AI's self-rated
        // `passed` boolean. The AI tends to be over-strict on its own output
        // schema (it'll say `passed=false` even when all its flags are soft).
        // Spec: hard flags block; soft flags log + pass.
        $hasHardFlag = false;
        foreach ($allFlags as $flag) {
            if (($flag['severity'] ?? '') === 'hard') {
                $hasHardFlag = true;
                break;
            }
        }
        $passed = ! $hasHardFlag;

        $check = AiComplianceCheck::create([
            'draft_id'      => $draft->id,
            'model_used'    => $modelUsed,
            'passed'        => $passed,
            'flags'         => $allFlags,
            'verdict_text'  => $aiVerdict ?: ($passed ? 'Hard regex passed; no AI verdict needed.' : 'Hard regex blocked.'),
            'tokens_input'  => $tokensIn,
            'tokens_output' => $tokensOut,
            'cost_cents'    => $costCents,
            'created_at'    => now(),
        ]);

        // Link the check back to the draft + update draft status if blocked.
        $draft->compliance_check_id = $check->id;
        if (! $passed) {
            $draft->status = AiDraft::STATUS_BLOCKED_COMPLIANCE;
        }
        $draft->save();

        return $check;
    }

    /**
     * @return list<array{rule: string, severity: string, excerpt: string}>
     */
    private function runHardBlocklist(string $text): array
    {
        $patterns = (array) config('outreach_compliance.hard_banned_phrases', []);
        $flags    = [];

        foreach ($patterns as $regex) {
            if (preg_match($regex, $text, $matches)) {
                $excerpt = (string) ($matches[0] ?? '');
                $flags[] = [
                    'rule'     => 'hard_banned_phrase',
                    'severity' => 'hard',
                    'excerpt'  => substr($excerpt, 0, 80),
                ];
            }
        }

        return $flags;
    }

    /**
     * @return array{0: list<array{rule: string, severity: string, excerpt: string}>, 1: bool, 2: string, 3: int, 4: int, 5: int, 6: string}
     */
    private function runAiSelfCheck(AiDraft $draft, ?string $pipelineHint): array
    {
        $system = (string) config('outreach_compliance.agent_system_prompt');
        $user   = $this->buildAiSelfCheckUserMessage($draft, $pipelineHint);

        $response = $this->router->call(
            task:        'compliance_check',
            system:      $system,
            messages:    [['role' => 'user', 'content' => $user]],
            max_tokens:  500,
        );

        $parsed = $this->parseAiVerdict($response->text);

        return [
            $parsed['flags'] ?? [],
            (bool) ($parsed['passed'] ?? true),
            (string) ($parsed['verdict'] ?? ''),
            $response->tokens_input,
            $response->tokens_output,
            $response->cost_cents,
            $response->model_used,
        ];
    }

    private function buildAiSelfCheckUserMessage(AiDraft $draft, ?string $pipelineHint): string
    {
        $sections = [];

        $sections[] = "## Draft to audit";
        $sections[] = $draft->draft_text;

        $sections[] = "\n## Banned patterns (hard fail if any matches — regex level, already passed)";
        $sections[] = json_encode((array) config('outreach_compliance.hard_banned_phrases', []), JSON_UNESCAPED_SLASHES);

        $sections[] = "\n## Soft warning rules";
        $sections[] = json_encode((array) config('outreach_compliance.soft_warning_rules', []), JSON_PRETTY_PRINT);

        if ($pipelineHint) {
            $disclosures = (array) config("outreach_compliance.required_disclosures.{$pipelineHint}", []);
            if (! empty($disclosures)) {
                $sections[] = "\n## Required disclosures for pipeline {$pipelineHint}";
                $sections[] = json_encode($disclosures, JSON_PRETTY_PRINT);
            }
        }

        if ($draft->template?->compliance_rules) {
            $sections[] = "\n## Per-template compliance rules";
            $sections[] = (string) $draft->template->compliance_rules;
        }

        $sections[] = "\n## Output";
        $sections[] = 'Return ONLY a JSON object: {"passed": true|false, "flags": [{"rule": "...", "severity": "hard|soft", "excerpt": "..."}], "verdict": "..."}';

        return implode("\n", $sections);
    }

    /**
     * @return array{passed?: bool, flags?: array, verdict?: string}
     */
    private function parseAiVerdict(string $text): array
    {
        // The model SHOULD return clean JSON. Be defensive — strip markdown
        // fences and find the first {...} block if it wraps the JSON.
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $candidate = $m[0];
            $decoded   = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Couldn't parse — treat as unpassed verdict so a human reviews.
        Log::warning('ComplianceAgent: could not parse AI verdict JSON', ['raw' => substr($text, 0, 300)]);
        return [
            'passed'  => false,
            'flags'   => [['rule' => 'unparseable_verdict', 'severity' => 'hard', 'excerpt' => substr($text, 0, 80)]],
            'verdict' => 'AI returned non-JSON; failing closed.',
        ];
    }
}
