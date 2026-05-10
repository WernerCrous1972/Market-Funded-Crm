<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Services\AI\ModelRouter;
use Illuminate\Support\Facades\Log;

/**
 * Classifies an inbound WhatsApp reply into (intent, confidence).
 *
 *   classify($messageText, $personContext = []) → InboundClassification
 *
 * Calls Haiku via ModelRouter (`inbound_classify` task). The classifier is
 * told the closed intent vocabulary from `config/outreach_inbound.intents`
 * and required to return strict JSON. Output is parsed defensively:
 *
 *   - Strips ``` fences if the model wraps the JSON
 *   - Coerces unknown intents to `unclear`
 *   - Clamps confidence to [0, 100]
 *
 * Fails CLOSED on any error (parse failure, model exhausted, etc.) — returns
 * `(intent=unclear, confidence=0)` so the caller escalates to a human. This
 * is the same fail-closed posture as ComplianceAgent.
 *
 * Cost-cap is checked by the caller (OutreachOrchestrator), not here.
 */
class InboundClassifier
{
    public function __construct(
        private readonly ModelRouter $router,
    ) {}

    public function classify(string $messageText, array $personContext = []): InboundClassification
    {
        if (trim($messageText) === '') {
            return new InboundClassification(
                intent:     'unclear',
                confidence: 0,
                model_used: '(empty input)',
                tokens_input:  0,
                tokens_output: 0,
                cost_cents: 0,
            );
        }

        $intents     = (array) config('outreach_inbound.intents', []);
        $intentList  = implode(', ', $intents);
        $system      = $this->buildSystemPrompt($intentList);
        $user        = $this->buildUserMessage($messageText, $personContext);

        try {
            $response = $this->router->call(
                task:       'inbound_classify',
                system:     $system,
                messages:   [['role' => 'user', 'content' => $user]],
                max_tokens: 200,
            );
        } catch (\Throwable $e) {
            Log::warning('InboundClassifier: model call failed; failing closed', [
                'error' => $e->getMessage(),
            ]);
            return new InboundClassification(
                intent:        'unclear',
                confidence:    0,
                model_used:    '(error)',
                tokens_input:  0,
                tokens_output: 0,
                cost_cents:    0,
            );
        }

        $parsed = $this->parseVerdict($response->text);
        $intent = $this->normalizeIntent((string) ($parsed['intent'] ?? 'unclear'), $intents);
        $confidence = $this->clampConfidence($parsed['confidence'] ?? 0);

        return new InboundClassification(
            intent:        $intent,
            confidence:    $confidence,
            model_used:    $response->model_used,
            tokens_input:  $response->tokens_input,
            tokens_output: $response->tokens_output,
            cost_cents:    $response->cost_cents,
        );
    }

    private function buildSystemPrompt(string $intentList): string
    {
        return <<<PROMPT
You classify inbound WhatsApp replies from clients of a financial brokerage.
Pick exactly ONE intent from this closed list: {$intentList}.

Return ONLY a JSON object on a single line, no commentary, no markdown:
{"intent": "<one of the listed values>", "confidence": <integer 0-100>}

Confidence reflects how sure you are the intent is correct. Use the FULL
0-100 range. Genuine ambiguity → low confidence. A clear "thanks" → high.
A complaint dressed as a question → "complaint" (intent wins over surface form).
PROMPT;
    }

    private function buildUserMessage(string $text, array $personContext): string
    {
        $sections = [];
        $sections[] = "## Inbound message text";
        $sections[] = $text;

        if (! empty($personContext)) {
            $sections[] = "\n## Sender context (for disambiguation only — do not echo back)";
            foreach ($personContext as $key => $value) {
                $renderable = is_scalar($value) ? (string) $value : json_encode($value);
                $sections[] = "{$key}: {$renderable}";
            }
        }

        return implode("\n", $sections);
    }

    /**
     * @return array{intent?: string, confidence?: int|string|float}
     */
    private function parseVerdict(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('InboundClassifier: could not parse verdict JSON', ['raw' => substr($text, 0, 300)]);
        return [];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeIntent(string $candidate, array $allowed): string
    {
        $candidate = strtolower(trim($candidate));
        return in_array($candidate, $allowed, true) ? $candidate : 'unclear';
    }

    private function clampConfidence(mixed $raw): int
    {
        if (! is_numeric($raw)) {
            return 0;
        }
        $n = (int) round((float) $raw);
        return max(0, min(100, $n));
    }
}
