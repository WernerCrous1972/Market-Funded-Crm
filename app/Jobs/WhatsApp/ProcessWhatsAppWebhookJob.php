<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Models\Activity;
use App\Models\Person;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\ServiceWindowTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 5;
    public int $timeout = 30;

    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(ServiceWindowTracker $tracker): void
    {
        $entry = $this->payload['entry'][0]  ?? null;
        $change = $entry['changes'][0]        ?? null;
        $value  = $change['value']            ?? null;

        if (! $value) {
            Log::warning('ProcessWhatsAppWebhookJob: empty payload', ['payload' => $this->payload]);
            return;
        }

        // ── Status updates (delivered / read / failed) ────────────────────────
        foreach ($value['statuses'] ?? [] as $status) {
            $this->handleStatusUpdate($status);
        }

        // ── Inbound messages ─────────────────────────────────────────────────
        foreach ($value['messages'] ?? [] as $message) {
            $this->handleInboundMessage($message, $tracker);
        }
    }

    private function handleStatusUpdate(array $status): void
    {
        $waMessageId = $status['id']     ?? null;
        $newStatus   = $status['status'] ?? null;

        if (! $waMessageId || ! $newStatus) {
            return;
        }

        $statusMap = [
            'sent'      => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'failed'    => 'FAILED',
        ];

        $mapped = $statusMap[strtolower($newStatus)] ?? null;
        if (! $mapped) {
            return;
        }

        $errorCode    = $status['errors'][0]['code']  ?? null;
        $errorMessage = $status['errors'][0]['title'] ?? null;

        WhatsAppMessage::where('wa_message_id', $waMessageId)
            ->update(array_filter([
                'status'        => $mapped,
                'error_code'    => $errorCode ? (string) $errorCode : null,
                'error_message' => $errorMessage,
            ], fn ($v) => $v !== null));

        Log::debug('ProcessWhatsAppWebhookJob: status updated', [
            'wa_message_id' => $waMessageId,
            'status'        => $mapped,
        ]);
    }

    private function handleInboundMessage(array $message, ServiceWindowTracker $tracker): void
    {
        $waMessageId = $message['id']   ?? null;
        $fromPhone   = $message['from'] ?? null;
        $type        = $message['type'] ?? 'text';

        if (! $waMessageId || ! $fromPhone) {
            return;
        }

        // Idempotency — same message ID processed twice is a no-op
        if (WhatsAppMessage::where('wa_message_id', $waMessageId)->exists()) {
            Log::debug('ProcessWhatsAppWebhookJob: duplicate inbound, skipping', ['wa_message_id' => $waMessageId]);
            return;
        }

        // Normalise phone to E.164
        $e164 = '+' . ltrim($fromPhone, '+');

        $person = Person::where('phone_e164', $e164)->first();

        if (! $person) {
            // Do NOT auto-create — brand-first rule means we only message known people
            Log::warning('ProcessWhatsAppWebhookJob: inbound from unknown phone', [
                'phone'        => $e164,
                'wa_message_id' => $waMessageId,
            ]);
            return;
        }

        $bodyText = $this->extractBody($message, $type);

        $msg = WhatsAppMessage::create([
            'person_id'     => $person->id,
            'direction'     => 'INBOUND',
            'wa_message_id' => $waMessageId,
            'body_text'     => $bodyText,
            'status'        => 'RECEIVED',
            'agent_key'     => null,
        ]);

        $tracker->extendWindow($person);

        Activity::record(
            personId:    $person->id,
            type:        Activity::TYPE_WHATSAPP_SENT, // Re-using existing type; WHATSAPP_RECEIVED is a future addition
            description: 'WhatsApp inbound received',
            metadata:    ['wa_message_id' => $waMessageId, 'direction' => 'INBOUND'],
        );

        event(new WhatsAppMessageReceived($person, $msg));

        Log::info('ProcessWhatsAppWebhookJob: inbound processed', [
            'person_id'     => $person->id,
            'wa_message_id' => $waMessageId,
        ]);
    }

    private function extractBody(array $message, string $type): string
    {
        return match ($type) {
            'text'     => $message['text']['body']            ?? '',
            'image'    => '[Image]',
            'audio'    => '[Audio]',
            'video'    => '[Video]',
            'document' => '[Document: ' . ($message['document']['filename'] ?? 'file') . ']',
            'sticker'  => '[Sticker]',
            default    => "[{$type}]",
        };
    }
}
