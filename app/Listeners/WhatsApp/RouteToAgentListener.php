<?php

declare(strict_types=1);

namespace App\Listeners\WhatsApp;

use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\OutreachOrchestrator;
use App\Services\Inbound\InboundClassifier;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4a milestone 5 — inbound reply routing.
 *
 * For each inbound WhatsApp message:
 *
 *   1. If AI calls are paused (hard cap or kill switch) → escalate with a
 *      generic holding reply. Skip classification — we can't classify either.
 *   2. Otherwise: classify intent + confidence via Haiku.
 *   3. If confidence >= threshold AND intent ∈ safe set → AI auto-reply.
 *   4. Otherwise → escalate with intent-specific holding reply.
 *
 * Either path persists an `outreach_inbound_messages` row so the threshold
 * can be tuned from real data, and writes Activity rows + Telegram alerts
 * via the orchestrator.
 *
 * NOT ShouldQueue — runs synchronously inline. The Anthropic call is the
 * slow bit (~1-2s) but doing it inline keeps the test suite simple (no
 * Queue::assertNothingPushed regressions) and matches the autonomous
 * outbound listeners. If inbound volume becomes a bottleneck we move to
 * a queue then.
 */
class RouteToAgentListener
{
    public function __construct(
        private readonly InboundClassifier $classifier,
        private readonly OutreachOrchestrator $orchestrator,
        private readonly CostCeilingGuard $guard,
    ) {}

    public function handle(WhatsAppMessageReceived $event): void
    {
        $person  = $event->person;
        $message = $event->message;

        // Defensive: only route true inbound messages with body text.
        if (($message->direction ?? null) !== 'INBOUND') {
            return;
        }
        $body = (string) ($message->body_text ?? '');
        if (trim($body) === '') {
            Log::debug('RouteToAgentListener: empty body, skipping', [
                'message_id' => $message->id,
            ]);
            return;
        }

        // If ALL AI calls are paused, we still want the client to see a
        // response and a human to know about it. Escalate with the default
        // holding message and a synthetic "unclear" classification.
        if (! $this->guard->allowsAnyCall()) {
            Log::info('RouteToAgentListener: AI paused, escalating without classification', [
                'message_id' => $message->id,
            ]);
            $this->orchestrator->inboundEscalation(
                $person,
                $message,
                new \App\Services\Inbound\InboundClassification(
                    intent:        'unclear',
                    confidence:    0,
                    model_used:    '(ai_paused)',
                    tokens_input:  0,
                    tokens_output: 0,
                    cost_cents:    0,
                ),
            );
            return;
        }

        $personContext = [
            'contact_type' => $person->contact_type,
            'first_name'   => $person->first_name,
        ];

        $classification = $this->classifier->classify($body, $personContext);

        if ($classification->shouldAutoReply()) {
            $this->orchestrator->inboundAutoReply($person, $message, $classification);
            return;
        }

        $this->orchestrator->inboundEscalation($person, $message, $classification);
    }
}
