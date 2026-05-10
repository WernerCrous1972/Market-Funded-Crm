<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\LeadCreated;
use App\Models\Person;

/**
 * Observes Person inserts and dispatches LeadCreated for new LEAD records.
 *
 * Why an observer rather than firing the event from the sync job: the
 * trigger should fire for ANY new lead — manual creation in admin, sync,
 * webhook, future API. Eloquent observer captures all paths uniformly.
 *
 * The observer fires LeadCreated only on the `created` lifecycle hook,
 * and only when contact_type=LEAD at insert time. Upgrades from
 * LEAD→CLIENT later are handled by Person::upgradeToClient (which fires
 * LeadConverted, the deposit_first trigger).
 */
class PersonObserver
{
    public function created(Person $person): void
    {
        if ($person->contact_type === 'LEAD') {
            LeadCreated::dispatch($person);
        }
    }
}
