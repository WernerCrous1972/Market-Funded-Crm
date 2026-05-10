<?php

declare(strict_types=1);

use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Listeners\WhatsApp\RouteToAgentListener;
use App\Models\OutreachInboundMessage;
use App\Models\Person;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\OutreachOrchestrator;
use App\Services\Inbound\InboundClassification;
use App\Services\Inbound\InboundClassifier;
use Illuminate\Support\Facades\Cache;

describe('RouteToAgentListener (inbound routing)', function () {

    beforeEach(function () {
        config()->set('outreach_inbound.intents', [
            'acknowledgment', 'simple_question', 'complex_question',
            'complaint', 'unsubscribe', 'sensitive_request', 'unclear',
        ]);
        config()->set('outreach_inbound.safe_intents', ['acknowledgment', 'simple_question']);
        config()->set('outreach_inbound.holding_messages', [
            'complaint' => 'Sorry — escalating.',
            'default'   => 'Passing it on.',
        ]);
        config()->set('ai.inbound_confidence_threshold', 75);
        Cache::flush();
    });

    function makeListenerWith(InboundClassification $verdict, ?CostCeilingGuard $guard = null): array
    {
        $classifier = new class($verdict) extends InboundClassifier {
            public int $callCount = 0;
            public function __construct(private readonly InboundClassification $verdict) {}
            public function classify(string $messageText, array $personContext = []): InboundClassification {
                $this->callCount++;
                return $this->verdict;
            }
        };

        $orch = new class extends OutreachOrchestrator {
            public array $autoReplied = [];
            public array $escalated   = [];
            public function __construct() {} // skip parent ctor
            public function inboundAutoReply(\App\Models\Person $person, \App\Models\WhatsAppMessage $msg, InboundClassification $c): OutreachInboundMessage {
                $this->autoReplied[] = ['person_id' => $person->id, 'message_id' => $msg->id, 'intent' => $c->intent];
                return OutreachInboundMessage::create([
                    'whatsapp_message_id' => $msg->id,
                    'person_id'           => $person->id,
                    'intent'              => $c->intent,
                    'confidence'          => $c->confidence,
                    'routing'             => OutreachInboundMessage::ROUTING_AUTO_REPLIED,
                    'created_at'          => now(),
                ]);
            }
            public function inboundEscalation(\App\Models\Person $person, \App\Models\WhatsAppMessage $msg, InboundClassification $c, ?\App\Models\AiDraft $blockedDraft = null): OutreachInboundMessage {
                $this->escalated[] = ['person_id' => $person->id, 'message_id' => $msg->id, 'intent' => $c->intent];
                return OutreachInboundMessage::create([
                    'whatsapp_message_id' => $msg->id,
                    'person_id'           => $person->id,
                    'intent'              => $c->intent,
                    'confidence'          => $c->confidence,
                    'routing'             => OutreachInboundMessage::ROUTING_ESCALATED_TO_HENRY,
                    'created_at'          => now(),
                ]);
            }
        };

        $listener = new RouteToAgentListener($classifier, $orch, $guard ?? new CostCeilingGuard());

        return [$listener, $classifier, $orch];
    }

    function makeWhatsAppInbound(Person $person, string $body): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'person_id'     => $person->id,
            'direction'     => 'INBOUND',
            'wa_message_id' => 'wamid.test_' . uniqid(),
            'body_text'     => $body,
            'status'        => 'RECEIVED',
        ]);
    }

    it('routes high-confidence safe intent → inboundAutoReply', function () {
        $person  = Person::factory()->create();
        $message = makeWhatsAppInbound($person, 'thanks!');
        $verdict = new InboundClassification(intent: 'acknowledgment', confidence: 90, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        [$listener, $classifier, $orch] = makeListenerWith($verdict);

        $listener->handle(new WhatsAppMessageReceived($person, $message));

        expect($classifier->callCount)->toBe(1);
        expect($orch->autoReplied)->toHaveCount(1);
        expect($orch->escalated)->toHaveCount(0);
    });

    it('routes low-confidence safe intent → inboundEscalation', function () {
        $person  = Person::factory()->create();
        $message = makeWhatsAppInbound($person, 'huh?');
        $verdict = new InboundClassification(intent: 'acknowledgment', confidence: 50, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        [$listener, $classifier, $orch] = makeListenerWith($verdict);

        $listener->handle(new WhatsAppMessageReceived($person, $message));

        expect($orch->autoReplied)->toHaveCount(0);
        expect($orch->escalated)->toHaveCount(1);
        expect($orch->escalated[0]['intent'])->toBe('acknowledgment');
    });

    it('routes high-confidence UNsafe intent → inboundEscalation', function () {
        $person  = Person::factory()->create();
        $message = makeWhatsAppInbound($person, 'this is broken!!');
        $verdict = new InboundClassification(intent: 'complaint', confidence: 95, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        [$listener, $classifier, $orch] = makeListenerWith($verdict);

        $listener->handle(new WhatsAppMessageReceived($person, $message));

        expect($orch->autoReplied)->toHaveCount(0);
        expect($orch->escalated)->toHaveCount(1);
        expect($orch->escalated[0]['intent'])->toBe('complaint');
    });

    it('skips entirely for outbound messages', function () {
        $person  = Person::factory()->create();
        $outbound = WhatsAppMessage::create([
            'person_id'     => $person->id,
            'direction'     => 'OUTBOUND',
            'wa_message_id' => 'wamid.test_' . uniqid(),
            'body_text'     => 'we sent this',
            'status'        => 'SENT',
        ]);
        $verdict = new InboundClassification(intent: 'acknowledgment', confidence: 90, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        [$listener, $classifier, $orch] = makeListenerWith($verdict);

        $listener->handle(new WhatsAppMessageReceived($person, $outbound));

        expect($classifier->callCount)->toBe(0);
        expect($orch->autoReplied)->toHaveCount(0);
        expect($orch->escalated)->toHaveCount(0);
    });

    it('skips classification for empty body', function () {
        $person  = Person::factory()->create();
        $message = makeWhatsAppInbound($person, '   ');
        $verdict = new InboundClassification(intent: 'acknowledgment', confidence: 90, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        [$listener, $classifier, $orch] = makeListenerWith($verdict);

        $listener->handle(new WhatsAppMessageReceived($person, $message));

        expect($classifier->callCount)->toBe(0);
    });

    it('escalates without classifying when AI is paused', function () {
        $person  = Person::factory()->create();
        $message = makeWhatsAppInbound($person, 'hello');
        $verdict = new InboundClassification(intent: 'acknowledgment', confidence: 99, model_used: 'h', tokens_input: 0, tokens_output: 0, cost_cents: 0);

        // Activate kill switch
        Cache::forever(CostCeilingGuard::CACHE_KEY_KILL_SWITCH, true);

        [$listener, $classifier, $orch] = makeListenerWith($verdict, new CostCeilingGuard());

        $listener->handle(new WhatsAppMessageReceived($person, $message));

        expect($classifier->callCount)->toBe(0);
        expect($orch->autoReplied)->toHaveCount(0);
        expect($orch->escalated)->toHaveCount(1);
        expect($orch->escalated[0]['intent'])->toBe('unclear');
    });

});
