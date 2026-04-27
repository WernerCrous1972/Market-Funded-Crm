<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Person;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a LEAD is upgraded to CLIENT via Person::upgradeToClient().
 * Celebrates a conversion — the person just made their first qualifying deposit.
 */
class LeadConverted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $personId;
    public readonly string $personName;
    public readonly string $personEmail;
    public readonly ?string $accountManager;

    public function __construct(Person $person)
    {
        $this->personId       = $person->id;
        $this->personName     = $person->full_name;
        $this->personEmail    = $person->email;
        $this->accountManager = $person->account_manager;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm-alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.converted';
    }
}
