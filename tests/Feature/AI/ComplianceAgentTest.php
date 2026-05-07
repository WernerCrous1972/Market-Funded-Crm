<?php

declare(strict_types=1);

use App\Models\AiComplianceCheck;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Services\AI\ComplianceAgent;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use App\Services\AI\ModelRouterException;
use Illuminate\Support\Str;

describe('ComplianceAgent', function () {

    beforeEach(function () {
        // Minimal but realistic compliance config for tests.
        config()->set('outreach_compliance.hard_banned_phrases', [
            '/\bguaranteed?\s+(returns?|profits?)/i',
            '/\brisk[-\s]?free\b/i',
            '/\bget[-\s]?rich(?:[-\s]quick)?\b/i',
        ]);
        config()->set('outreach_compliance.soft_warning_rules', [
            'unhedged_performance_claim' => 'Claims about returns without acknowledging risk.',
        ]);
        config()->set('outreach_compliance.required_disclosures', [
            'MFU_MARKETS' => ['risk_warning' => 'A brief acknowledgment that trading carries risk.'],
        ]);
        config()->set('outreach_compliance.agent_system_prompt', 'You are a compliance reviewer.');
    });

    /**
     * Build a fake ModelRouter that returns the supplied JSON string verbatim
     * as the model's text. Captures inputs for assertions.
     */
    function makeFakeRouterForCompliance(string $textOut): array
    {
        $captured = (object) ['task' => null, 'system' => null, 'messages' => null];

        $fake = new class($captured, $textOut) extends ModelRouter
        {
            public function __construct(
                private readonly object $captured,
                private readonly string $textOut,
            ) {
                // skip parent constructor
            }

            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $this->captured->task = $task;
                $this->captured->system = $system;
                $this->captured->messages = $messages;

                return new ModelResponse(
                    text:           $this->textOut,
                    model_used:     'claude-haiku-4-5-20251001',
                    tokens_input:   80,
                    tokens_output:  20,
                    cost_cents:     1,
                    used_fallback:  false,
                );
            }
        };

        return [$fake, $captured];
    }

    function makeRouterThatThrows(): ModelRouter
    {
        return new class extends ModelRouter
        {
            public function __construct() {} // skip parent

            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                throw new ModelRouterException('every model failed', []);
            }
        };
    }

    function makeDraft(string $text, ?OutreachTemplate $template = null): AiDraft
    {
        $person   = Person::factory()->create();
        $template ??= OutreachTemplate::create([
            'name'               => 'Welcome',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'sys',
            'autonomous_enabled' => false,
            'is_active'          => true,
        ]);

        return AiDraft::create([
            'person_id'    => $person->id,
            'template_id'  => $template->id,
            'mode'         => AiDraft::MODE_REVIEWED,
            'channel'      => 'WHATSAPP',
            'model_used'   => 'claude-sonnet-4-6',
            'prompt_hash'  => str_repeat('a', 64),
            'prompt_full'  => 'fake prompt',
            'draft_text'   => $text,
            'status'       => AiDraft::STATUS_PENDING_REVIEW,
            'tokens_input' => 100,
            'tokens_output' => 50,
            'cost_cents'   => 5,
        ]);
    }

    it('blocks a draft that contains a hard banned phrase, without calling the AI', function () {
        $draft = makeDraft('We offer guaranteed returns of 10% per month.');
        [$router, $captured] = makeFakeRouterForCompliance('{"passed":true,"flags":[],"verdict":"ok"}');

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeFalse();
        expect($check->flags)->toHaveCount(1);
        expect($check->flags[0]['severity'])->toBe('hard');
        expect($check->flags[0]['rule'])->toBe('hard_banned_phrase');
        expect($captured->task)->toBeNull(); // AI was not called

        // Draft state should be updated
        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_BLOCKED_COMPLIANCE);
        expect($draft->compliance_check_id)->toBe($check->id);
    });

    it('passes a clean draft when the AI verdict is passed=true', function () {
        $draft = makeDraft('Hello, welcome to our platform.');
        [$router, $captured] = makeFakeRouterForCompliance('{"passed":true,"flags":[],"verdict":"clean"}');

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeTrue();
        expect($check->flags)->toBe([]);
        expect($check->model_used)->toBe('claude-haiku-4-5-20251001');
        expect($captured->task)->toBe('compliance_check');

        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_PENDING_REVIEW); // unchanged
        expect($draft->compliance_check_id)->toBe($check->id);
    });

    it('blocks when the AI returns hard-severity flags (regardless of self-rated passed)', function () {
        $draft = makeDraft('Click this link now or you lose forever!');
        [$router] = makeFakeRouterForCompliance(
            '{"passed":false,"flags":[{"rule":"urgency_pressure","severity":"hard","excerpt":"now or you lose"}],"verdict":"too aggressive"}'
        );

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeFalse();
        expect($check->flags)->toHaveCount(1);
        expect($check->flags[0]['rule'])->toBe('urgency_pressure');

        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_BLOCKED_COMPLIANCE);
    });

    it('passes when ALL flags are soft, even if the AI self-rates passed=false', function () {
        // Real-world finding (live demo, 2026-05-07): the AI tends to flip
        // its `passed` boolean too eagerly when soft flags exist. We derive
        // the final pass/fail from flag severity, not the AI's verdict.
        $draft = makeDraft('Welcome to our trading platform.');
        [$router] = makeFakeRouterForCompliance(
            '{"passed":false,"flags":[{"rule":"missing_risk_disclaimer","severity":"soft","excerpt":"no risk warning"}],"verdict":"missing disclaimer"}'
        );

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeTrue();
        expect($check->flags)->toHaveCount(1);
        expect($check->flags[0]['severity'])->toBe('soft');

        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_PENDING_REVIEW);
    });

    it('passes with soft flags logged when AI returns soft warnings only', function () {
        $draft = makeDraft('Last few spots left in our trading course.');
        [$router] = makeFakeRouterForCompliance(
            '{"passed":true,"flags":[{"rule":"urgency_pressure","severity":"soft","excerpt":"last few spots"}],"verdict":"borderline; passing"}'
        );

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeTrue();
        expect($check->flags)->toHaveCount(1);
        expect($check->flags[0]['severity'])->toBe('soft');

        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_PENDING_REVIEW);
    });

    it('fails closed when ModelRouter throws (every model exhausted)', function () {
        $draft  = makeDraft('Hello, welcome.');
        $router = makeRouterThatThrows();

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeFalse();
        expect($check->flags[0]['rule'])->toBe('ai_check_unavailable');

        $draft->refresh();
        expect($draft->status)->toBe(AiDraft::STATUS_BLOCKED_COMPLIANCE);
    });

    it('fails closed when AI returns unparseable JSON', function () {
        $draft = makeDraft('Hello, welcome.');
        [$router] = makeFakeRouterForCompliance('Sure, looks fine to me!'); // not JSON

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeFalse();
        expect($check->flags[0]['rule'])->toBe('unparseable_verdict');
    });

    it('strips markdown code fences from the AI response', function () {
        $draft = makeDraft('Hello, welcome.');
        [$router] = makeFakeRouterForCompliance("```json\n{\"passed\":true,\"flags\":[],\"verdict\":\"ok\"}\n```");

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeTrue();
    });

    it('combines hard flags + AI flags when both fire (defensive — first hard wins block)', function () {
        // Hard regex catches "guaranteed returns" → AI not called → only hard flag.
        // This documents the short-circuit behaviour.
        $draft = makeDraft('We offer guaranteed returns and risk-free trading.');
        [$router] = makeFakeRouterForCompliance('{"passed":true,"flags":[],"verdict":"clean"}');

        $check = (new ComplianceAgent($router))->check($draft);

        expect($check->passed)->toBeFalse();
        // Two hard regexes match → 2 flags from layer 1 only
        expect($check->flags)->toHaveCount(2);
        expect($check->flags[0]['severity'])->toBe('hard');
        expect($check->flags[1]['severity'])->toBe('hard');
    });

    it('passes pipelineHint into the AI checker context', function () {
        $draft = makeDraft('Welcome — trading involves risk.');
        [$router, $captured] = makeFakeRouterForCompliance('{"passed":true,"flags":[],"verdict":"ok"}');

        (new ComplianceAgent($router))->check($draft, pipelineHint: 'MFU_MARKETS');

        $userMsg = $captured->messages[0]['content'];
        expect($userMsg)->toContain('Required disclosures for pipeline MFU_MARKETS');
        expect($userMsg)->toContain('risk_warning');
    });

});
