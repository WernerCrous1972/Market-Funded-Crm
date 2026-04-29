<?php

declare(strict_types=1);

namespace App\Events\WhatsApp;

use App\Models\Person;
use App\Models\WhatsAppMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a new inbound WhatsApp message is processed.
 *
 * This is the integration point for future AI agent routing.
 * RouteToAgentListener is a TODO stub — AI logic plugs in here.
 */
class WhatsAppMessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Person         $person,
        public readonly WhatsAppMessage $message,
    ) {}
}
