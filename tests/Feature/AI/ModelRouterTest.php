<?php

declare(strict_types=1);

use App\Models\AiUsageLog;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use App\Services\AI\ModelRouterException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

describe('ModelRouter', function () {

    beforeEach(function () {
        // Sensible config for tests; the real config/ai.php drives prod.
        config()->set('ai.anthropic.api_key', 'test-key');
        config()->set('ai.anthropic.base_url', 'https://api.anthropic.com');
        config()->set('ai.anthropic.version', '2023-06-01');
        config()->set('ai.anthropic.timeout', 5);

        config()->set('ai.tasks', [
            'compliance_check'           => 'claude-haiku-4-5-20251001',
            'outreach_draft_individual'  => 'claude-sonnet-4-6',
        ]);
        config()->set('ai.default', 'claude-haiku-4-5-20251001');

        config()->set('ai.fallback_chain', [
            'claude-sonnet-4-6'         => ['claude-haiku-4-5-20251001', 'gpt-5.5-mini'],
            'claude-haiku-4-5-20251001' => ['gpt-5.5-mini'],
        ]);

        config()->set('ai.pricing', [
            'claude-sonnet-4-6'         => ['input' => 300,  'output' => 1500],
            'claude-haiku-4-5-20251001' => ['input' => 100,  'output' => 500],
        ]);
    });

    /**
     * @param  list<\GuzzleHttp\Psr7\Response|\Throwable>  $responses
     */
    $makeRouter = function (array $responses, ?array &$captured = null): ModelRouter {
        $captured = [];
        $mock     = new MockHandler($responses);
        $stack    = HandlerStack::create($mock);
        $stack->push(function ($handler) use (&$captured) {
            return function ($request, $options) use ($handler, &$captured) {
                $captured[] = $request;
                return $handler($request, $options);
            };
        });

        return new ModelRouter(new GuzzleClient(['handler' => $stack]));
    };

    $anthropicResponse = function (string $text, int $in, int $out, string $model = 'claude-haiku-4-5-20251001'): Response {
        return new Response(200, [], json_encode([
            'id'         => 'msg_test',
            'type'       => 'message',
            'role'       => 'assistant',
            'model'      => $model,
            'content'    => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage'      => ['input_tokens' => $in, 'output_tokens' => $out],
        ]));
    };

    it('routes a known task to its mapped model and returns a ModelResponse', function () use ($makeRouter, $anthropicResponse) {
        $router = $makeRouter(
            [$anthropicResponse('hello world', 12, 5, 'claude-haiku-4-5-20251001')],
            $captured,
        );

        $result = $router->call('compliance_check', 'You are a helper.', [
            ['role' => 'user', 'content' => 'say hello'],
        ]);

        expect($result)->toBeInstanceOf(ModelResponse::class)
            ->and($result->text)->toBe('hello world')
            ->and($result->model_used)->toBe('claude-haiku-4-5-20251001')
            ->and($result->tokens_input)->toBe(12)
            ->and($result->tokens_output)->toBe(5)
            ->and($result->used_fallback)->toBeFalse();

        // Cost: 12 in × $1/MTok + 5 out × $5/MTok ≈ 0 cents (rounds down — both are tiny)
        expect($result->cost_cents)->toBe(0);

        // Verify the request sent matches what we expect
        expect($captured)->toHaveCount(1);
        $body = json_decode((string) $captured[0]->getBody(), true);
        expect($body['model'])->toBe('claude-haiku-4-5-20251001');
        expect($body['system'])->toBe('You are a helper.');
        expect($captured[0]->getHeaderLine('x-api-key'))->toBe('test-key');
    });

    it('falls back to the next model in the chain when the primary fails', function () use ($makeRouter, $anthropicResponse) {
        // Primary (Sonnet) → 503; Haiku succeeds
        $router = $makeRouter([
            new Response(503, [], 'overloaded'),
            $anthropicResponse('hi from haiku', 8, 3, 'claude-haiku-4-5-20251001'),
        ], $captured);

        $result = $router->call('outreach_draft_individual', 'sys', [
            ['role' => 'user', 'content' => 'hi'],
        ]);

        expect($result->model_used)->toBe('claude-haiku-4-5-20251001');
        expect($result->used_fallback)->toBeTrue();
        expect($captured)->toHaveCount(2);
    });

    it('throws ModelRouterException with attempts when all models fail', function () use ($makeRouter) {
        // Sonnet → 500, Haiku → 503, then external stub which throws "not configured"
        $router = $makeRouter([
            new Response(500, [], 'boom'),
            new Response(503, [], 'overloaded'),
        ]);

        expect(fn () => $router->call('outreach_draft_individual', 'sys', [
            ['role' => 'user', 'content' => 'x'],
        ]))->toThrow(ModelRouterException::class);
    });

    it('captures per-attempt errors on the exception', function () use ($makeRouter) {
        $router = $makeRouter([
            new Response(500, [], 'boom-sonnet'),
            new Response(503, [], 'overloaded-haiku'),
        ]);

        try {
            $router->call('outreach_draft_individual', 'sys', [
                ['role' => 'user', 'content' => 'x'],
            ]);
            $this->fail('expected exception');
        } catch (ModelRouterException $e) {
            expect($e->attempts)->toHaveCount(3); // sonnet + haiku + external stub
            expect($e->attempts[0]['model'])->toBe('claude-sonnet-4-6');
            expect($e->attempts[1]['model'])->toBe('claude-haiku-4-5-20251001');
            expect($e->attempts[2]['model'])->toBe('gpt-5.5-mini');
            expect($e->attempts[2]['error'])->toContain('not configured');
        }
    });

    it('survives transport-level errors (no response at all)', function () use ($makeRouter, $anthropicResponse) {
        $exception = new ConnectException('refused', new Request('POST', '/v1/messages'));
        $router    = $makeRouter([
            $exception,
            $anthropicResponse('recovered', 5, 2, 'claude-haiku-4-5-20251001'),
        ]);

        $result = $router->call('outreach_draft_individual', 'sys', [
            ['role' => 'user', 'content' => 'x'],
        ]);

        expect($result->used_fallback)->toBeTrue();
        expect($result->text)->toBe('recovered');
    });

    it('writes an ai_usage_log row on success', function () use ($makeRouter, $anthropicResponse) {
        $router = $makeRouter([$anthropicResponse('done', 1000, 500, 'claude-haiku-4-5-20251001')]);

        $router->call('compliance_check', 'sys', [['role' => 'user', 'content' => 'q']]);

        $row = AiUsageLog::where('task_type', 'compliance_check')
            ->where('model', 'claude-haiku-4-5-20251001')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->call_count)->toBe(1);
        expect($row->tokens_input)->toBe(1000);
        expect($row->tokens_output)->toBe(500);
        // 1000 × $1/MTok = 0.1¢ → 0 (rounded). 500 × $5/MTok = 0.25¢ → 0.
        // Both individually round to 0; sum is 0. Realistic small calls.
        expect($row->cost_cents)->toBe(0);
    });

    it('aggregates repeated calls into one daily row (UPSERT)', function () use ($makeRouter, $anthropicResponse) {
        $router = $makeRouter([
            $anthropicResponse('a', 100_000, 50_000, 'claude-haiku-4-5-20251001'),
            $anthropicResponse('b', 200_000, 80_000, 'claude-haiku-4-5-20251001'),
        ]);

        $router->call('compliance_check', 'sys', [['role' => 'user', 'content' => 'x']]);
        $router->call('compliance_check', 'sys', [['role' => 'user', 'content' => 'y']]);

        $rows = AiUsageLog::where('task_type', 'compliance_check')->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->call_count)->toBe(2);
        expect($rows[0]->tokens_input)->toBe(300_000);
        expect($rows[0]->tokens_output)->toBe(130_000);
        // 300_000 / 1M × 100 = 30¢; 130_000 / 1M × 500 = 65¢; total 95¢
        expect($rows[0]->cost_cents)->toBe(95);
    });

    it('keeps separate rows per model when fallback fired', function () use ($makeRouter, $anthropicResponse) {
        // Sonnet fails, Haiku succeeds — only Haiku should have a usage row
        $router = $makeRouter([
            new Response(503, [], 'overloaded'),
            $anthropicResponse('answer', 1_000_000, 500_000, 'claude-haiku-4-5-20251001'),
        ]);

        $router->call('outreach_draft_individual', 'sys', [['role' => 'user', 'content' => 'x']]);

        $rows = AiUsageLog::all();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->model)->toBe('claude-haiku-4-5-20251001');
        // 1M × $1/MTok = 100¢; 500k × $5/MTok = 250¢; total 350¢
        expect($rows[0]->cost_cents)->toBe(350);
    });

    it('computes cost from the pricing table', function () {
        // computeCostCents is pure (no HTTP), so we still need a Guzzle to
        // satisfy the constructor — but it's never used.
        $router = new ModelRouter(new GuzzleClient());

        // Sonnet $3/$15 per MTok
        // 1M input × 300¢/MTok = 300¢
        // 1M output × 1500¢/MTok = 1500¢
        // total = 1800¢ = $18
        expect($router->computeCostCents('claude-sonnet-4-6', 1_000_000, 1_000_000))->toBe(1800);

        // Haiku $1/$5 per MTok
        expect($router->computeCostCents('claude-haiku-4-5-20251001', 1_000_000, 1_000_000))->toBe(600);

        // Unknown model → 0
        expect($router->computeCostCents('unknown-model', 1_000_000, 1_000_000))->toBe(0);
    });

    it('falls back to default model for unknown task names', function () use ($makeRouter, $anthropicResponse) {
        $router = $makeRouter([$anthropicResponse('default-route', 5, 2, 'claude-haiku-4-5-20251001')]);

        $result = $router->call('not_a_known_task', 'sys', [['role' => 'user', 'content' => 'x']]);

        // ai.default = haiku; should route there
        expect($result->model_used)->toBe('claude-haiku-4-5-20251001');
    });

});
