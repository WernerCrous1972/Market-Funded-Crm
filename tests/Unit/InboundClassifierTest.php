<?php

declare(strict_types=1);

use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use App\Services\Inbound\InboundClassifier;

describe('InboundClassifier', function () {

    beforeEach(function () {
        config()->set('outreach_inbound.intents', [
            'acknowledgment',
            'simple_question',
            'complex_question',
            'complaint',
            'unsubscribe',
            'sensitive_request',
            'unclear',
        ]);
        config()->set('outreach_inbound.safe_intents', ['acknowledgment', 'simple_question']);
        config()->set('ai.inbound_confidence_threshold', 75);
    });

    function makeInboundFakeRouter(string $text, bool $throwInstead = false): ModelRouter
    {
        return new class($text, $throwInstead) extends ModelRouter
        {
            public function __construct(private readonly string $text, private readonly bool $throwInstead) {}
            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                if ($this->throwInstead) {
                    throw new \RuntimeException('Simulated model failure');
                }
                return new ModelResponse(
                    text: $this->text,
                    model_used: 'claude-haiku-4-5-20251001',
                    tokens_input: 50,
                    tokens_output: 20,
                    cost_cents: 1,
                    used_fallback: false,
                );
            }
        };
    }

    it('parses clean JSON and returns the right intent + confidence', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":92}');
        $c = new InboundClassifier($router);
        $r = $c->classify('thanks!');
        expect($r->intent)->toBe('acknowledgment');
        expect($r->confidence)->toBe(92);
        expect($r->shouldAutoReply())->toBeTrue();
    });

    it('strips markdown fences when the model wraps JSON', function () {
        $router = makeInboundFakeRouter("```json\n{\"intent\":\"complaint\",\"confidence\":80}\n```");
        $r = (new InboundClassifier($router))->classify('not happy');
        expect($r->intent)->toBe('complaint');
        expect($r->confidence)->toBe(80);
    });

    it('coerces unknown intents to "unclear"', function () {
        $router = makeInboundFakeRouter('{"intent":"feeling_chatty","confidence":99}');
        $r = (new InboundClassifier($router))->classify('hello');
        expect($r->intent)->toBe('unclear');
        expect($r->confidence)->toBe(99);
    });

    it('clamps confidence to 0..100', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":150}');
        $r = (new InboundClassifier($router))->classify('ok');
        expect($r->confidence)->toBe(100);

        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":-30}');
        $r = (new InboundClassifier($router))->classify('ok');
        expect($r->confidence)->toBe(0);
    });

    it('coerces non-numeric confidence to 0', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":"high"}');
        $r = (new InboundClassifier($router))->classify('ok');
        expect($r->confidence)->toBe(0);
    });

    it('returns (unclear, 0) on unparseable JSON', function () {
        $router = makeInboundFakeRouter('I think this is acknowledgment, very sure');
        $r = (new InboundClassifier($router))->classify('ok');
        expect($r->intent)->toBe('unclear');
        expect($r->confidence)->toBe(0);
    });

    it('returns (unclear, 0) and does not throw on model failure', function () {
        $router = makeInboundFakeRouter('', throwInstead: true);
        $r = (new InboundClassifier($router))->classify('hello');
        expect($r->intent)->toBe('unclear');
        expect($r->confidence)->toBe(0);
        expect($r->model_used)->toBe('(error)');
    });

    it('skips classification for empty body', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":99}'); // unreached
        $r = (new InboundClassifier($router))->classify('   ');
        expect($r->intent)->toBe('unclear');
        expect($r->confidence)->toBe(0);
        expect($r->model_used)->toBe('(empty input)');
    });

    it('shouldAutoReply: high-conf safe intent → true', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":90}');
        expect((new InboundClassifier($router))->classify('ty')->shouldAutoReply())->toBeTrue();
    });

    it('shouldAutoReply: high-conf UNsafe intent → false', function () {
        $router = makeInboundFakeRouter('{"intent":"complaint","confidence":99}');
        expect((new InboundClassifier($router))->classify('mad')->shouldAutoReply())->toBeFalse();
    });

    it('shouldAutoReply: low-conf safe intent → false', function () {
        $router = makeInboundFakeRouter('{"intent":"acknowledgment","confidence":50}');
        expect((new InboundClassifier($router))->classify('?')->shouldAutoReply())->toBeFalse();
    });

    it('shouldAutoReply: confidence exactly at threshold → true', function () {
        $router = makeInboundFakeRouter('{"intent":"simple_question","confidence":75}');
        expect((new InboundClassifier($router))->classify('?')->shouldAutoReply())->toBeTrue();
    });

});
