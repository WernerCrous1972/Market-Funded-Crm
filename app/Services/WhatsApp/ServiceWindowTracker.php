<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\Person;
use App\Models\WhatsAppMessage;

class ServiceWindowTracker
{
    /**
     * Returns true if the person sent an inbound message within the last 24 hours,
     * meaning we are inside the Meta service window and can send free-form messages.
     */
    public function isInsideWindow(Person $person): bool
    {
        return WhatsAppMessage::where('person_id', $person->id)
            ->where('direction', 'INBOUND')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Returns true when a template is required (i.e. outside the service window).
     */
    public function requiresTemplate(Person $person): bool
    {
        return ! $this->isInsideWindow($person);
    }

    /**
     * Called when a new inbound message arrives. Updates the conversation window
     * expiry on all OUTBOUND messages for this person that are still in SENT/DELIVERED/READ.
     */
    public function extendWindow(Person $person): void
    {
        $expiresAt = now()->addHours(24);

        WhatsAppMessage::where('person_id', $person->id)
            ->where('direction', 'OUTBOUND')
            ->whereIn('status', ['SENT', 'DELIVERED', 'READ'])
            ->update(['conversation_window_expires_at' => $expiresAt]);
    }
}
