<?php

declare(strict_types=1);

namespace App\Jobs\WhatsApp;

use App\Exceptions\WhatsAppSendException;
use App\Models\Activity;
use App\Models\Person;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\MetaCloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;
    public int $timeout = 60;

    public function __construct(
        public readonly string  $personId,
        public readonly string  $body,
        public readonly ?string $templateName  = null,
        public readonly array   $variables     = [],
        public readonly ?string $agentKey      = null,
        public readonly ?string $sentByUserId  = null,
    ) {}

    public function handle(MetaCloudClient $client): void
    {
        $person = Person::find($this->personId);

        if (! $person) {
            Log::warning('SendWhatsAppMessageJob: person not found', ['person_id' => $this->personId]);
            return;
        }

        if (! $person->phone_e164) {
            Log::warning('SendWhatsAppMessageJob: person has no phone', ['person_id' => $this->personId]);
            $this->insertMessage($person, 'FAILED', null, 'NO_PHONE', 'Person has no E.164 phone number');
            return;
        }

        $templateId = null;
        if ($this->templateName !== null) {
            $tpl = WhatsAppTemplate::where('name', $this->templateName)
                ->where('status', 'APPROVED')
                ->first();

            if (! $tpl) {
                Log::error('SendWhatsAppMessageJob: template not found or not approved', ['name' => $this->templateName]);
                $this->insertMessage($person, 'FAILED', null, 'TEMPLATE_NOT_FOUND', "Template [{$this->templateName}] not found or not APPROVED");
                return;
            }

            $templateId = $tpl->id;
        }

        try {
            if ($this->templateName !== null) {
                $result = $client->sendTemplate(
                    $person->phone_e164,
                    $this->templateName,
                    $this->variables,
                );
            } else {
                $result = $client->sendFreeForm($person->phone_e164, $this->body);
            }

            $msg = $this->insertMessage($person, 'SENT', $templateId, null, null, $result->waMessageId);

            Activity::record(
                personId:    $person->id,
                type:        Activity::TYPE_WHATSAPP_SENT,
                description: 'WhatsApp ' . ($this->templateName ? "template [{$this->templateName}]" : 'free-form') . ' sent',
                metadata:    [
                    'wa_message_id' => $result->waMessageId,
                    'agent_key'     => $this->agentKey,
                    'template'      => $this->templateName,
                ],
                userId: $this->sentByUserId,
            );

            Log::info('SendWhatsAppMessageJob: sent', ['wa_message_id' => $result->waMessageId]);

        } catch (WhatsAppSendException $e) {
            Log::error('SendWhatsAppMessageJob: send failed', [
                'person_id'  => $this->personId,
                'error'      => $e->getMessage(),
                'error_code' => $e->metaErrorCode,
            ]);

            $this->insertMessage($person, 'FAILED', $templateId, $e->metaErrorCode ?? 'SEND_ERROR', $e->getMessage());

            // Re-throw so the queue retries
            throw $e;
        }
    }

    private function insertMessage(
        Person  $person,
        string  $status,
        ?string $templateId,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $waMessageId = null,
    ): WhatsAppMessage {
        return WhatsAppMessage::create([
            'person_id'      => $person->id,
            'direction'      => 'OUTBOUND',
            'wa_message_id'  => $waMessageId,
            'template_id'    => $templateId,
            'body_text'      => $this->body,
            'status'         => $status,
            'error_code'     => $errorCode,
            'error_message'  => $errorMessage,
            'agent_key'      => $this->agentKey,
            'sent_by_user_id' => $this->sentByUserId,
        ]);
    }
}
