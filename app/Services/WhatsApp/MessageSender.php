<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Exceptions\TemplateRequiredException;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MessageSender
{
    public function __construct(
        private readonly ServiceWindowTracker $windowTracker,
    ) {}

    /**
     * Dispatch a WhatsApp message for the given person.
     *
     * This method validates preconditions then dispatches SendWhatsAppMessageJob.
     * The job handles the actual API call, DB write, and Activity log.
     *
     * @param  array<string, string>  $variables  Template variable values in order
     * @throws TemplateRequiredException  when outside service window and no template provided
     */
    public function send(
        Person  $person,
        string  $body,
        ?string $templateName = null,
        array   $variables    = [],
        ?string $agentKey     = null,
        ?User   $sentByUser   = null,
    ): void {
        if (! config('whatsapp.feature_enabled')) {
            Log::info('WhatsApp feature disabled — message not sent', [
                'person_id' => $person->id,
                'template'  => $templateName,
            ]);
            return;
        }

        if ($this->windowTracker->requiresTemplate($person) && $templateName === null) {
            throw new TemplateRequiredException($person->id);
        }

        SendWhatsAppMessageJob::dispatch(
            personId:     $person->id,
            body:         $body,
            templateName: $templateName,
            variables:    $variables,
            agentKey:     $agentKey,
            sentByUserId: $sentByUser?->id,
        );
    }
}
