<?php

declare(strict_types=1);

namespace App\Listeners\AI;

use App\Events\LeadCreated;

/**
 * Fires when a new Person is inserted as a LEAD. Dispatched by the
 * Person observer — see EventServiceProvider / AppServiceProvider boot.
 */
class OnLeadCreated extends DispatchAutonomousOutreach
{
    protected function triggerEvent(): string
    {
        return 'lead_created';
    }

    protected function personIdFromEvent(object $event): ?string
    {
        return $event instanceof LeadCreated ? $event->personId : null;
    }
}
