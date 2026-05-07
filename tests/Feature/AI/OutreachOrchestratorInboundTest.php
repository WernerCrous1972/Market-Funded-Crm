<?php

declare(strict_types=1);

use App\Models\AiDraft;
use App\Models\Activity;
use App\Models\OutreachInboundMessage;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\AI\ComplianceAgent;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\DraftService;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use App\Services\AI\OutreachOrchestrator;
use App\Services\Inbound\InboundClassification;
use Illuminate\Support\Facades\Cache;

describe('OutreachOrchestrator — inbound', function () {

    beforeEach(function () {
        config()->set('ai.tasks', [
            'outreach_draft_individual' => 'claude-sonnet-4-6',
            'outreach_draft_bulk'       => 'claude-haiku-4-5-20251001',
            'compliance_check'          => 'claude-haiku-4-5-20251001',
            'inbound_response_draft'    => 'claude-sonnet-4-6',
        ]);
        config()->set('ai.default', 'claude-haiku-4-5-20251001');
        config()->set('ai.cost_caps.soft_usd', 300);
        config()->set('ai.cost_caps.hard_usd', 500);
        config()->set('ai.autonomous_paused', false);

        config()->set('outreach_compliance.hard_banned_phrases', [
            '/\bguaranteed?\s+returns?/i',
        ]);
        config()->set('outreach_compliance.soft_warning_rules', []);
        config()->set('outreach_compliance.required_disclosures', []);
        config()->set('outreach_compliance.agent_system_prompt', 'You are a compliance reviewer.');

        // The migration seeds the system inbound template with this exact name,
        // and that's the lookup key the orchestrator uses.
        config()->set('outreach_inbound.auto_reply_template_name', 'System — Inbound auto-reply');
        config()->set('outreach_inbound.holding_messages', [
            'complaint'         => 'I\'m sorry — escalating.',
            'unsubscribe'       => 'Understood — passing it on.',
            'sensitive_request' => 'Getting the right person.',
            'complex_question'  => 'Getting the right person.',
            'unclear'           => 'Passing it on.',
            'default'           => 'Passing it on.',
        ]);

        Cache::flush();
    });

    function makeInboundQueuedRouter(array $textsInOrder): ModelRouter
    {
        return new class($textsInOrder) extends ModelRouter
        {
            private int $i = 0;
            public function __construct(private readonly array $texts) {}
            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $text = $this->texts[$this->i] ?? throw new \RuntimeException("Out of fake responses at call #{$this->i}");
                $this->i++;
                return new ModelResponse(
                    text: $text,
                    model_used: 'claude-haiku-4-5-20251001',
                    tokens_input: 100,
                    tokens_output: 30,
                    cost_cents: 1,
                    used_fallback: false,
                );
            }
        };
    }

    /**
     * @return array{0: OutreachOrchestrator, 1: object, 2: object}
     */
    function makeInboundOrch(ModelRouter $router, ?CostCeilingGuard $guard = null): array
    {
        $messageSender = new class extends \App\Services\WhatsApp\MessageSender {
            public array $sent = [];
            public function __construct() {}
            public function send(\App\Models\Person $person, string $body, ?string $templateName = null, array $variables = [], ?string $agentKey = null, ?\App\Models\User $sentByUser = null): void {
                $this->sent[] = ['person_id' => $person->id, 'body' => $body];
            }
        };
        $telegram = new class extends \App\Services\Notifications\TelegramNotifier {
            public array $alerts = [];
            public function __construct() {}
            public function notify(string $message, string $severity = 'info'): bool {
                $this->alerts[] = ['message' => $message, 'severity' => $severity];
                return true;
            }
            public function isReachable(): bool { return true; }
        };

        $orch = new OutreachOrchestrator(
            new DraftService($router),
            new ComplianceAgent($router),
            $guard ?? new CostCeilingGuard(),
            $messageSender,
            $telegram,
        );
        return [$orch, $messageSender, $telegram];
    }

    function makeInboundClassification(string $intent = 'acknowledgment', int $confidence = 90): InboundClassification
    {
        return new InboundClassification(
            intent:        $intent,
            confidence:    $confidence,
            model_used:    'claude-haiku-4-5-20251001',
            tokens_input:  20,
            tokens_output: 5,
            cost_cents:    0,
        );
    }

    function makeInboundMessage(Person $person, string $body): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'person_id'   => $person->id,
            'direction'   => 'INBOUND',
            'wa_message_id' => 'wamid.test_' . uniqid(),
            'body_text'   => $body,
            'status'      => 'RECEIVED',
        ]);
    }

    // ── inboundAutoReply ─────────────────────────────────────────────────────

    it('inboundAutoReply: drafts, passes compliance, sends, persists row', function () {
        $person  = Person::factory()->create();
        $message = makeInboundMessage($person, 'thanks!');
        $cls     = makeInboundClassification('acknowledgment', 90);

        // System inbound template seeded by migration.
        expect(OutreachTemplate::where('name', 'System — Inbound auto-reply')->exists())->toBeTrue();

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([
            "You're welcome, friend!",                            // draft
            '{"passed":true,"flags":[],"verdict":"clean"}',        // compliance
        ]));

        $row = $orch->inboundAutoReply($person, $message, $cls);

        expect($row)->toBeInstanceOf(OutreachInboundMessage::class);
        expect($row->routing)->toBe(OutreachInboundMessage::ROUTING_AUTO_REPLIED);
        expect($row->intent)->toBe('acknowledgment');
        expect($row->confidence)->toBe(90);
        expect($row->auto_reply_draft_id)->not->toBeNull();
        expect($row->assigned_to_user_id)->toBeNull();

        $draft = AiDraft::find($row->auto_reply_draft_id);
        expect($draft->status)->toBe(AiDraft::STATUS_SENT);
        expect($draft->mode)->toBe(AiDraft::MODE_AUTONOMOUS);
        expect($draft->triggered_by_event)->toBe('inbound_reply');
        expect($draft->final_text)->toBe("You're welcome, friend!");

        expect($sender->sent)->toHaveCount(1);
        expect($sender->sent[0]['body'])->toBe("You're welcome, friend!");

        $activity = Activity::where('person_id', $person->id)->where('type', 'WHATSAPP_SENT')->first();
        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain('AI auto-reply');
    });

    it('inboundAutoReply: when compliance blocks the AI reply, falls through to escalation', function () {
        $person  = Person::factory()->create(['account_manager_user_id' => null]);
        $message = makeInboundMessage($person, 'thanks!');
        $cls     = makeInboundClassification('acknowledgment', 90);

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([
            'We offer guaranteed returns of 10%.',                  // draft (will hit hard regex)
            '{"passed":true,"flags":[],"verdict":"clean"}',          // unreached
        ]));

        $row = $orch->inboundAutoReply($person, $message, $cls);

        expect($row->routing)->toBe(OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY);
        // The blocked draft is recorded for audit
        expect($row->auto_reply_draft_id)->not->toBeNull();
        $draft = AiDraft::find($row->auto_reply_draft_id);
        expect($draft->status)->toBe(AiDraft::STATUS_BLOCKED_COMPLIANCE);

        // Holding message was sent
        expect($sender->sent)->toHaveCount(1);
        expect($sender->sent[0]['body'])->toBe('Passing it on.');

        // Telegram alert went out
        expect($telegram->alerts)->toHaveCount(1);
        expect($telegram->alerts[0]['message'])->toContain('BLOCKED by compliance');
    });

    it('inboundAutoReply: cost guard paused → falls through to escalation', function () {
        $person  = Person::factory()->create(['account_manager_user_id' => null]);
        $message = makeInboundMessage($person, 'thanks!');
        $cls     = makeInboundClassification('acknowledgment', 90);

        // Simulate kill-switch
        Cache::forever(CostCeilingGuard::CACHE_KEY_KILL_SWITCH, true);

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([])); // no AI calls expected

        $row = $orch->inboundAutoReply($person, $message, $cls);

        expect($row->routing)->toBe(OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY);
        expect($row->auto_reply_draft_id)->toBeNull();
    });

    // ── inboundEscalation ────────────────────────────────────────────────────

    it('inboundEscalation: with assigned manager → routes to agent + alert', function () {
        $manager = User::factory()->create();
        $person  = Person::factory()->create(['account_manager_user_id' => $manager->id]);
        $message = makeInboundMessage($person, 'My account is broken!!');
        $cls     = makeInboundClassification('complaint', 88);

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([])); // no AI

        $row = $orch->inboundEscalation($person, $message, $cls);

        expect($row->routing)->toBe(OutreachInboundMessage::ROUTING_ESCALATED_TO_AGENT);
        expect($row->assigned_to_user_id)->toBe($manager->id);
        expect($row->intent)->toBe('complaint');
        expect($row->confidence)->toBe(88);
        expect($row->auto_reply_draft_id)->toBeNull();

        // Holding message uses the complaint-specific text
        expect($sender->sent)->toHaveCount(1);
        expect($sender->sent[0]['body'])->toBe("I'm sorry — escalating.");

        // Telegram alert went to the agent route
        expect($telegram->alerts)->toHaveCount(1);
        $alert = $telegram->alerts[0]['message'];
        expect($alert)->toContain('Inbound escalated to');
        expect($alert)->toContain('complaint');
        expect($alert)->toContain('88%');
    });

    it('inboundEscalation: no assigned manager → routes to Henry', function () {
        $person  = Person::factory()->create(['account_manager_user_id' => null]);
        $message = makeInboundMessage($person, 'unsub me');
        $cls     = makeInboundClassification('unsubscribe', 95);

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([]));

        $row = $orch->inboundEscalation($person, $message, $cls);

        expect($row->routing)->toBe(OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY);
        expect($row->assigned_to_user_id)->toBeNull();

        // Unsubscribe holding message
        expect($sender->sent[0]['body'])->toBe('Understood — passing it on.');
    });

    it('inboundEscalation: unknown intent falls back to default holding message', function () {
        $person  = Person::factory()->create();
        $message = makeInboundMessage($person, '???');
        $cls     = makeInboundClassification('feeling_chatty', 50); // not in map

        [$orch, $sender, $telegram] = makeInboundOrch(makeInboundQueuedRouter([]));
        $orch->inboundEscalation($person, $message, $cls);

        expect($sender->sent[0]['body'])->toBe('Passing it on.');
    });

});
