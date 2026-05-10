<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiUsageLog;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Routes one logical AI call to the right model, with failover.
 *
 *   call(task, system, messages, max_tokens) → ModelResponse
 *
 *   - Looks up `config('ai.tasks.<task>')` to pick the primary model
 *     (falls back to `config('ai.default')` for unknown task names)
 *   - On primary error (timeout, 429, 5xx, connection error), walks
 *     `config('ai.fallback_chain.<primary>')` in order until something
 *     answers
 *   - Computes per-call cost from `config('ai.pricing.<model>')`
 *   - Upserts a daily-aggregated row in `ai_usage_log`
 *
 * Out-of-scope this phase:
 *   - External providers (gpt-5.5-mini, kimi-2.5) — calling them returns
 *     a "not_configured" failure so the chain advances; the failure is
 *     visible in logs and the per-call attempt log
 *   - Streaming responses (we always read the whole thing)
 *   - Tool use / structured outputs (callers parse JSON themselves)
 *   - Prompt caching (worth adding once we have steady state)
 */
class ModelRouter
{
    private const ANTHROPIC_RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly GuzzleClient $http,
    ) {
        // Wired by AppServiceProvider with base_uri + timeout from config/ai.php.
        // Tests pass a mocked GuzzleClient directly.
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @throws ModelRouterException  when every model in the chain failed
     */
    public function call(
        string $task,
        string $system,
        array $messages,
        int $max_tokens = 1024,
    ): ModelResponse {
        $primary = (string) (config("ai.tasks.{$task}") ?? config('ai.default'));
        $chain   = $this->resolveChain($primary);

        $attempts = [];
        $usedFallback = false;

        foreach ($chain as $model) {
            try {
                $result = $this->callModel($model, $system, $messages, $max_tokens);

                $cost = $this->computeCostCents($model, $result['tokens_input'], $result['tokens_output']);
                $this->recordUsage($task, $model, $result['tokens_input'], $result['tokens_output'], $cost);

                return new ModelResponse(
                    text:           $result['text'],
                    model_used:     $model,
                    tokens_input:   $result['tokens_input'],
                    tokens_output:  $result['tokens_output'],
                    cost_cents:     $cost,
                    used_fallback:  $usedFallback,
                    raw:            $result['raw'],
                );
            } catch (\Throwable $e) {
                $attempts[] = ['model' => $model, 'error' => $e->getMessage()];
                Log::warning('ModelRouter: model failed, advancing chain', [
                    'task'  => $task,
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
                $usedFallback = true;
            }
        }

        throw new ModelRouterException(
            "All models failed for task '{$task}': " .
                implode(' → ', array_map(fn ($a) => "{$a['model']}: {$a['error']}", $attempts)),
            $attempts,
        );
    }

    public function computeCostCents(string $model, int $tokensIn, int $tokensOut): int
    {
        $pricing = config("ai.pricing.{$model}");
        if (! is_array($pricing)) {
            // No pricing configured (e.g. external stubs) → treat as zero rather
            // than guessing wrong; we'll still log call_count + tokens.
            return 0;
        }

        $inputCents  = (int) round(($tokensIn / 1_000_000) * (int) $pricing['input']);
        $outputCents = (int) round(($tokensOut / 1_000_000) * (int) $pricing['output']);

        return $inputCents + $outputCents;
    }

    /**
     * @return list<string>
     */
    private function resolveChain(string $primary): array
    {
        $fallbacks = (array) config("ai.fallback_chain.{$primary}", []);
        return array_values(array_unique([$primary, ...$fallbacks]));
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @return array{text: string, tokens_input: int, tokens_output: int, raw: array<string,mixed>}
     */
    private function callModel(string $model, string $system, array $messages, int $maxTokens): array
    {
        if (str_starts_with($model, 'claude-')) {
            return $this->callAnthropic($model, $system, $messages, $maxTokens);
        }

        // External providers (gpt-5.5-mini, kimi-2.5) are stubbed — fail loud
        // so the chain advances and ops can see fallback fired.
        throw new \RuntimeException("Provider for model '{$model}' is not configured (Phase 4a stub)");
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     *
     * @return array{text: string, tokens_input: int, tokens_output: int, raw: array<string,mixed>}
     */
    private function callAnthropic(string $model, string $system, array $messages, int $maxTokens): array
    {
        $apiKey = (string) config('ai.anthropic.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY_CRM is not set');
        }

        $body = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => $messages,
        ];

        try {
            $response = $this->http->post('/v1/messages', [
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => (string) config('ai.anthropic.version'),
                    'content-type'      => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse()
                ? $e->getResponse()->getStatusCode()
                : 0;

            // Retryable statuses get bubbled the same way as transport errors —
            // the outer foreach in call() will advance to the next model.
            $retryable = $status === 0 || in_array($status, self::ANTHROPIC_RETRYABLE_STATUS, true);

            $detail = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $detail = (string) $e->getResponse()->getBody();
            }

            $msg = sprintf(
                'Anthropic API %s: %s%s',
                $status ?: 'transport',
                $e->getMessage(),
                $detail ? " | {$detail}" : '',
            );

            // For non-retryable errors (4xx other than 429) we still throw —
            // call() catches and advances. The semantic difference exists
            // mostly for log clarity / future retry-with-backoff inside one
            // model.
            throw new \RuntimeException($msg, previous: $e);
        }

        $data  = json_decode((string) $response->getBody(), true) ?? [];
        $text  = (string) ($data['content'][0]['text'] ?? '');
        $usage = (array) ($data['usage'] ?? []);

        return [
            'text'          => $text,
            'tokens_input'  => (int) ($usage['input_tokens'] ?? 0),
            'tokens_output' => (int) ($usage['output_tokens'] ?? 0),
            'raw'           => $data,
        ];
    }

    private function recordUsage(string $task, string $model, int $tokensIn, int $tokensOut, int $costCents): void
    {
        $today = CarbonImmutable::now()->toDateString();

        // Postgres UPSERT on the unique (date, task_type, model) triple.
        // Cheaper and race-safer than a SELECT-then-UPDATE.
        DB::statement(
            <<<SQL
            INSERT INTO ai_usage_log (id, date, task_type, model, call_count, tokens_input, tokens_output, cost_cents, created_at, updated_at)
            VALUES (gen_random_uuid(), ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())
            ON CONFLICT (date, task_type, model) DO UPDATE SET
                call_count    = ai_usage_log.call_count    + 1,
                tokens_input  = ai_usage_log.tokens_input  + EXCLUDED.tokens_input,
                tokens_output = ai_usage_log.tokens_output + EXCLUDED.tokens_output,
                cost_cents    = ai_usage_log.cost_cents    + EXCLUDED.cost_cents,
                updated_at    = NOW()
            SQL,
            [$today, $task, $model, $tokensIn, $tokensOut, $costCents],
        );
    }

}
