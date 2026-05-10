<?php

declare(strict_types=1);

use App\Jobs\AI\DetectDormantClientsJob;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use Illuminate\Support\Str;

describe('DetectDormantClientsJob', function () {

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

    function bindFakeRouterForJob(array $textsInOrder): void
    {
        $fake = new class($textsInOrder) extends ModelRouter {
            private int $i = 0;
            public function __construct(private readonly array $texts) {}
            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $text = $this->texts[$this->i] ?? throw new \RuntimeException("Out of fake responses at #{$this->i}");
                $this->i++;
                return new ModelResponse(
                    text: $text, model_used: 'claude-haiku-4-5-20251001',
                    tokens_input: 50, tokens_output: 20, cost_cents: 1, used_fallback: false,
                );
            }
        };
        app()->instance(ModelRouter::class, $fake);
    }

    function makeDormantClient(int $daysSinceLogin): Person
    {
        $person = Person::factory()->create(['contact_type' => 'CLIENT']);
        PersonMetric::create([
            'id'                       => Str::uuid()->toString(),
            'person_id'                => $person->id,
            'days_since_last_login'    => $daysSinceLogin,
            'has_markets'              => true,
            'refreshed_at'             => now(),
        ]);
        return $person->load('metrics');
    }

    function makeDormantTemplate(string $triggerEvent): OutreachTemplate
    {
        return OutreachTemplate::create([
            'name'               => "Dormant {$triggerEvent}",
            'trigger_event'      => $triggerEvent,
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'Re-engage warmly.',
            'autonomous_enabled' => true,
            'is_active'          => true,
        ]);
    }

    it('does nothing when no templates have autonomous_enabled', function () {
        makeDormantClient(20); // would match 14d if a template were enabled
        OutreachTemplate::create([
            'name' => 'inactive',
            'trigger_event' => 'dormant_14d',
            'channel' => 'WHATSAPP',
            'system_prompt' => 'sys',
            'autonomous_enabled' => false, // not enabled
            'is_active' => true,
        ]);

        bindFakeRouterForJob([]); // shouldn't be called

        (new DetectDormantClientsJob())->handle();

        expect(AiDraft::count())->toBe(0);
    });

    it('dispatches autonomousSend for clients in the 14d window with an enabled template', function () {
        makeDormantClient(20); // [14, 29] window — fires dormant_14d
        makeDormantTemplate('dormant_14d');

        bindFakeRouterForJob([
            "It's been a while — anything we can help with?",
            '{"passed":true,"flags":[],"verdict":"clean"}',
        ]);

        (new DetectDormantClientsJob())->handle();

        expect(AiDraft::count())->toBe(1);
        expect(AiDraft::first()->triggered_by_event)->toBe('dormant_14d');
    });

    it('dispatches dormant_30d for clients past 30 days, NOT dormant_14d', function () {
        makeDormantClient(45);
        makeDormantTemplate('dormant_14d');
        makeDormantTemplate('dormant_30d');

        bindFakeRouterForJob([
            "It's been over a month — checking in.",
            '{"passed":true,"flags":[],"verdict":"clean"}',
        ]);

        (new DetectDormantClientsJob())->handle();

        $drafts = AiDraft::all();
        expect($drafts)->toHaveCount(1);
        expect($drafts[0]->triggered_by_event)->toBe('dormant_30d');
    });

    it('skips a person who already received the same trigger within dedup window', function () {
        $person = makeDormantClient(20);
        $template = makeDormantTemplate('dormant_14d');

        // Pre-existing draft within 30-day window
        AiDraft::create([
            'person_id'           => $person->id,
            'template_id'         => $template->id,
            'mode'                => AiDraft::MODE_AUTONOMOUS,
            'channel'             => 'WHATSAPP',
            'model_used'          => 'claude-sonnet-4-6',
            'prompt_hash'         => str_repeat('a', 64),
            'draft_text'          => 'previous',
            'status'              => AiDraft::STATUS_SENT,
            'triggered_by_event'  => 'dormant_14d',
            'tokens_input'        => 50, 'tokens_output' => 20, 'cost_cents' => 1,
            'created_at'          => now()->subDays(5),
        ]);

        bindFakeRouterForJob([]); // shouldn't be called

        (new DetectDormantClientsJob())->handle();

        // No new draft created
        expect(AiDraft::count())->toBe(1);
    });

    it('does NOT process LEAD-type people, only CLIENTs', function () {
        $person = Person::factory()->create(['contact_type' => 'LEAD']);
        PersonMetric::create([
            'id'                    => Str::uuid()->toString(),
            'person_id'             => $person->id,
            'days_since_last_login' => 20,
            'refreshed_at'          => now(),
        ]);
        makeDormantTemplate('dormant_14d');
        bindFakeRouterForJob([]);

        (new DetectDormantClientsJob())->handle();

        expect(AiDraft::count())->toBe(0);
    });

});
