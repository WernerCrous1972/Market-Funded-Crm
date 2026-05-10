<?php

declare(strict_types=1);

use App\Events\LargeWithdrawalReceived;
use App\Events\LeadConverted;
use App\Events\LeadCreated;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\Transaction;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

describe('Autonomous trigger listeners', function () {

    beforeEach(function () {
        config()->set('ai.tasks', [
            'outreach_draft_individual' => 'claude-sonnet-4-6',
            'compliance_check'          => 'claude-haiku-4-5-20251001',
        ]);
        config()->set('ai.default', 'claude-haiku-4-5-20251001');
        config()->set('ai.fallback_chain', []);
        config()->set('ai.pricing', [
            'claude-sonnet-4-6'         => ['input' => 300, 'output' => 1500],
            'claude-haiku-4-5-20251001' => ['input' => 100, 'output' => 500],
        ]);
        config()->set('ai.cost_caps.soft_usd', 300);
        config()->set('ai.cost_caps.hard_usd', 500);
        config()->set('outreach_compliance.hard_banned_phrases', []);
        config()->set('outreach_compliance.soft_warning_rules', []);
        config()->set('outreach_compliance.required_disclosures', []);
        config()->set('outreach_compliance.agent_system_prompt', 'sys');
    });

    /**
     * Replace the singleton ModelRouter in the container with a fake that
     * returns scripted responses. Keeps tests away from the network.
     */
    function bindFakeRouter(array $textsInOrder): void
    {
        $fake = new class($textsInOrder) extends ModelRouter
        {
            private int $i = 0;

            public function __construct(private readonly array $texts) {}

            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $text = $this->texts[$this->i] ?? throw new \RuntimeException("Out of fake responses at #{$this->i}");
                $this->i++;
                return new ModelResponse(
                    text: $text,
                    model_used: 'claude-haiku-4-5-20251001',
                    tokens_input: 50,
                    tokens_output: 20,
                    cost_cents: 1,
                    used_fallback: false,
                );
            }
        };

        app()->instance(ModelRouter::class, $fake);
    }

    it('Person observer fires LeadCreated on new LEAD inserts', function () {
        Event::fake([LeadCreated::class]);

        Person::factory()->create(['contact_type' => 'LEAD']);

        Event::assertDispatched(LeadCreated::class);
    });

    it('does NOT fire LeadCreated when contact_type is CLIENT at insert', function () {
        Event::fake([LeadCreated::class]);

        Person::factory()->create(['contact_type' => 'CLIENT']);

        Event::assertNotDispatched(LeadCreated::class);
    });

    it('OnLeadCreated no-ops when no autonomous_enabled template matches', function () {
        $person = Person::factory()->create(['contact_type' => 'LEAD']);
        // No matching template exists — listener should silently skip
        // (LeadCreated fires from the observer, listener runs synchronously
        // because we haven't faked queueing here)

        expect(AiDraft::count())->toBe(0);
    });

    it('OnLeadCreated dispatches autonomousSend when a matching template is enabled', function () {
        // Set up: matching enabled template + scripted Anthropic responses
        OutreachTemplate::create([
            'name'               => 'Welcome — auto',
            'trigger_event'      => 'lead_created',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'Friendly welcome.',
            'autonomous_enabled' => true,
            'is_active'          => true,
        ]);

        bindFakeRouter([
            'Welcome to Market Funded!',                                        // draft
            '{"passed":true,"flags":[],"verdict":"clean"}',                     // compliance
        ]);

        // Run listeners synchronously (they implement ShouldQueue but in tests
        // we want immediate execution)
        \Illuminate\Support\Facades\Queue::fake();

        $person = Person::factory()->create(['contact_type' => 'LEAD']);

        // Listener was queued; manually run it
        $listener = new \App\Listeners\AI\OnLeadCreated();
        $listener->handle(new LeadCreated($person));

        $draft = AiDraft::first();
        expect($draft)->not->toBeNull();
        expect($draft->mode)->toBe(AiDraft::MODE_AUTONOMOUS);
        expect($draft->triggered_by_event)->toBe('lead_created');
        expect($draft->status)->toBe(AiDraft::STATUS_SENT); // dispatched (no-op send while WA disabled)
    });

    it('OnDepositFirst dispatches autonomousSend on LeadConverted', function () {
        OutreachTemplate::create([
            'name'               => 'First deposit congrats',
            'trigger_event'      => 'deposit_first',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'Congratulate.',
            'autonomous_enabled' => true,
            'is_active'          => true,
        ]);

        bindFakeRouter([
            'Congrats on your first deposit!',
            '{"passed":true,"flags":[],"verdict":"clean"}',
        ]);

        $person = Person::factory()->create(['contact_type' => 'CLIENT']);

        (new \App\Listeners\AI\OnDepositFirst())->handle(new LeadConverted($person));

        $draft = AiDraft::first();
        expect($draft)->not->toBeNull();
        expect($draft->triggered_by_event)->toBe('deposit_first');
    });

    it('OnLargeWithdrawal dispatches autonomousSend on LargeWithdrawalReceived', function () {
        OutreachTemplate::create([
            'name'               => 'Large withdrawal check-in',
            'trigger_event'      => 'large_withdrawal',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'Retention check.',
            'autonomous_enabled' => true,
            'is_active'          => true,
        ]);

        bindFakeRouter([
            'We noticed your withdrawal — anything we can help with?',
            '{"passed":true,"flags":[],"verdict":"clean"}',
        ]);

        $person = Person::factory()->create(['contact_type' => 'CLIENT']);
        $tx     = Transaction::factory()->create([
            'person_id'    => $person->id,
            'category'     => 'EXTERNAL_WITHDRAWAL',
            'amount_cents' => 1_000_000, // $10k
            'currency'     => 'USD',
            'status'       => 'DONE',
            'occurred_at'  => now(),
        ]);

        (new \App\Listeners\AI\OnLargeWithdrawal())->handle(new LargeWithdrawalReceived($person, $tx));

        $draft = AiDraft::first();
        expect($draft)->not->toBeNull();
        expect($draft->triggered_by_event)->toBe('large_withdrawal');
    });

});
