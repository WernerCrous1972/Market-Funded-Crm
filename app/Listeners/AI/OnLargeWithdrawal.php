<?php

declare(strict_types=1);

namespace App\Listeners\AI;

use App\Events\LargeWithdrawalReceived;

/**
 * Fires when a withdrawal of $5k+ is detected. Outreach template
 * `large_withdrawal` should be a retention check-in, not a question.
 */
class OnLargeWithdrawal extends DispatchAutonomousOutreach
{
    protected function triggerEvent(): string
    {
        return 'large_withdrawal';
    }

    protected function personIdFromEvent(object $event): ?string
    {
        return $event instanceof LargeWithdrawalReceived ? $event->personId : null;
    }
}
