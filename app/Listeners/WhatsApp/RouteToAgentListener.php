<?php

declare(strict_types=1);

namespace App\Listeners\WhatsApp;

use App\Events\WhatsApp\WhatsAppMessageReceived;
use Illuminate\Support\Facades\Log;

/**
 * TODO: Route inbound WhatsApp messages to the appropriate AI agent.
 *
 * This stub is intentionally empty. When AI integration is ready:
 * 1. Determine the correct agent from $event->person's pipeline and recent context.
 * 2. Build a conversation thread and call the Claude API.
 * 3. Dispatch SendWhatsAppMessageJob with the agent's response and the agent key.
 *
 * See BRAIN.md for agent department definitions.
 */
class RouteToAgentListener
{
    public function handle(WhatsAppMessageReceived $event): void
    {
        Log::info('RouteToAgentListener: TODO — route to agent', [
            'person_id'     => $event->person->id,
            'wa_message_id' => $event->message->wa_message_id,
        ]);
    }
}
