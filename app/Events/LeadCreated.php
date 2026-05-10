<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Person;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the first time a Person is inserted with contact_type=LEAD.
 *
 * Dispatched from the Person Eloquent observer (PersonObserver::created).
 * Drives the `lead_created` autonomous outreach trigger.
 *
 * NOT broadcast — this event is for AI listeners only, not Reverb toasts
 * to the operations dashboard. (LeadConverted is the broadcast version
 * for "we got a real conversion to celebrate".)
 */
class LeadCreated
{
    use Dispatchable, SerializesModels;

    public readonly string $personId;
    public readonly string $personEmail;

    public function __construct(Person $person)
    {
        $this->personId    = $person->id;
        $this->personEmail = (string) ($person->email ?? '');
    }
}
