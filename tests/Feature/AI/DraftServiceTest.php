<?php

declare(strict_types=1);

use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Models\Transaction;
use App\Services\AI\DraftService;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use Illuminate\Support\Str;

describe('DraftService', function () {

    beforeEach(function () {
        config()->set('ai.tasks', [
            'outreach_draft_individual' => 'claude-sonnet-4-6',
            'outreach_draft_bulk'       => 'claude-haiku-4-5-20251001',
        ]);
        config()->set('ai.default', 'claude-haiku-4-5-20251001');
    });

    /**
     * A fake ModelRouter that captures the task + system + messages it was
     * called with, so tests can assert without hitting the network.
     */
    function makeFakeRouter(string $textOut = 'Welcome!', int $in = 100, int $out = 50, string $modelUsed = 'claude-sonnet-4-6'): array
    {
        $captured = (object) ['task' => null, 'system' => null, 'messages' => null, 'maxTokens' => null];

        $fake = new class($captured, $textOut, $in, $out, $modelUsed) extends ModelRouter
        {
            public function __construct(
                private readonly object $captured,
                private readonly string $textOut,
                private readonly int $in,
                private readonly int $out,
                private readonly string $modelUsed,
            ) {
                // intentionally skip parent ctor — Guzzle isn't needed here
            }

            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $this->captured->task = $task;
                $this->captured->system = $system;
                $this->captured->messages = $messages;
                $this->captured->maxTokens = $max_tokens;

                return new ModelResponse(
                    text:           $this->textOut,
                    model_used:     $this->modelUsed,
                    tokens_input:   $this->in,
                    tokens_output:  $this->out,
                    cost_cents:     5,
                    used_fallback:  false,
                );
            }
        };

        return [$fake, $captured];
    }

    function makeTemplate(array $overrides = []): OutreachTemplate
    {
        return OutreachTemplate::create(array_merge([
            'name'               => 'Welcome — new lead',
            'trigger_event'      => 'lead_created',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'You are a friendly trading-broker concierge writing short WhatsApp welcomes. Keep replies under 80 words.',
            'autonomous_enabled' => false,
            'is_active'          => true,
        ], $overrides));
    }

    it('creates a pending_review ai_drafts row from a person + template', function () {
        $person = Person::factory()->create([
            'first_name' => 'Alice',
            'last_name'  => 'Smith',
            'email'      => 'alice@example.com',
            'country'    => 'ZA',
            'branch'     => 'Market Funded',
        ]);

        [$router, $captured] = makeFakeRouter('Hi Alice, welcome to Market Funded!');
        $template = makeTemplate();

        $service = new DraftService($router);
        $draft   = $service->draft($person, $template);

        expect($draft)->toBeInstanceOf(AiDraft::class);
        expect($draft->status)->toBe(AiDraft::STATUS_PENDING_REVIEW);
        expect($draft->mode)->toBe(AiDraft::MODE_REVIEWED);
        expect($draft->channel)->toBe('WHATSAPP');
        expect($draft->draft_text)->toBe('Hi Alice, welcome to Market Funded!');
        expect($draft->model_used)->toBe('claude-sonnet-4-6');
        expect($draft->prompt_hash)->toHaveLength(64); // sha256
        expect($draft->prompt_full)->not->toBeNull();  // REVIEWED → full prompt stored
        expect($draft->tokens_input)->toBe(100);
        expect($draft->tokens_output)->toBe(50);
        expect($draft->cost_cents)->toBe(5);
    });

    it('routes individual mode to outreach_draft_individual task', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        (new DraftService($router))->draft($person, $template, AiDraft::MODE_REVIEWED);

        expect($captured->task)->toBe('outreach_draft_individual');
    });

    it('routes bulk mode to outreach_draft_bulk task', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        (new DraftService($router))->draft($person, $template, AiDraft::MODE_BULK_REVIEWED);

        expect($captured->task)->toBe('outreach_draft_bulk');
    });

    it('compresses autonomous drafts by NOT storing prompt_full', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        $draft = (new DraftService($router))->draft(
            $person,
            $template,
            AiDraft::MODE_AUTONOMOUS,
            triggeredByEvent: 'lead_created',
        );

        expect($draft->mode)->toBe(AiDraft::MODE_AUTONOMOUS);
        expect($draft->prompt_full)->toBeNull();
        expect($draft->prompt_hash)->toHaveLength(64);
        expect($draft->triggered_by_event)->toBe('lead_created');
    });

    it('passes the templates system_prompt to the router', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate(['system_prompt' => 'You are a SOMETHING UNIQUE.']);

        (new DraftService($router))->draft($person, $template);

        expect($captured->system)->toBe('You are a SOMETHING UNIQUE.');
    });

    it('builds a user message containing the persons name + branch + segments', function () {
        $person = Person::factory()->create([
            'first_name' => 'Carol',
            'last_name'  => 'Davis',
            'branch'     => 'Market Funded',
        ]);

        PersonMetric::create([
            'id'                       => Str::uuid()->toString(),
            'person_id'                => $person->id,
            'total_deposits_cents'     => 500_00,
            'total_withdrawals_cents'  => 100_00,
            'net_deposits_cents'       => 400_00,
            'has_markets'              => true,
            'has_capital'              => true,
            'has_academy'              => false,
            'refreshed_at'             => now(),
        ]);

        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        (new DraftService($router))->draft($person, $template);

        $userMsg = $captured->messages[0]['content'];
        expect($userMsg)->toContain('Carol Davis');
        expect($userMsg)->toContain('Market Funded');
        expect($userMsg)->toContain('MFU_MARKETS');
        expect($userMsg)->toContain('MFU_CAPITAL');
        expect($userMsg)->not->toContain('MFU_ACADEMY'); // has_academy=false
        expect($userMsg)->toContain('total_deposits_usd: 500.00');
        expect($userMsg)->toContain('event: lead_created');
    });

    it('includes recent transactions in the user message', function () {
        $person = Person::factory()->create();
        Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_DEPOSIT',
            'amount_cents' => 250_00,
            'currency'     => 'USD',
            'status'       => 'DONE',
            'occurred_at'  => now()->subDay(),
        ]);

        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        (new DraftService($router))->draft($person, $template);

        $userMsg = $captured->messages[0]['content'];
        expect($userMsg)->toContain('Recent transactions');
        expect($userMsg)->toContain('EXTERNAL_DEPOSIT');
        expect($userMsg)->toContain('250.00');
    });

    it('passes extraContext through to the user message', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate();

        (new DraftService($router))->draft(
            $person,
            $template,
            extraContext: ['deposit_amount' => '$500', 'first_time' => true],
        );

        $userMsg = $captured->messages[0]['content'];
        expect($userMsg)->toContain('deposit_amount: $500');
        expect($userMsg)->toContain('first_time: 1'); // bools serialise as ints
    });

    it('appends per-template compliance rules into the user message', function () {
        $person = Person::factory()->create();
        [$router, $captured] = makeFakeRouter();
        $template = makeTemplate([
            'compliance_rules' => 'Do not mention specific past performance.',
        ]);

        (new DraftService($router))->draft($person, $template);

        $userMsg = $captured->messages[0]['content'];
        expect($userMsg)->toContain('Do not mention specific past performance.');
    });

});
