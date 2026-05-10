<?php

declare(strict_types=1);

namespace App\Listeners\AI;

use App\Events\LeadConverted;

/**
 * Fires when an existing LEAD is upgraded to CLIENT — that's the moment
 * of "first qualifying deposit", which is what the outreach template
 * `deposit_first` is designed for.
 */
class OnDepositFirst extends DispatchAutonomousOutreach
{
    protected function triggerEvent(): string
    {
        return 'deposit_first';
    }

    protected function personIdFromEvent(object $event): ?string
    {
        return $event instanceof LeadConverted ? $event->personId : null;
    }
}
