<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\Person;
use App\Services\MatchTrader\Client;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the most recent LOGIN timeline event for each CLIENT from the MTR API
 * and updates people.last_online_at accordingly.
 *
 * Only processes CLIENTs with a known mtr_account_uuid (populated by SyncAccountsJob).
 * Skips the update if the stored timestamp is already newer than or equal to the API value.
 *
 * Rate: ~1 API call per client. At 500 req/min, 1,277 clients ≈ 3 minutes.
 * Run after SyncAccountsJob in a full sync, or via --login-timestamps-only.
 */
class SyncLoginTimestampsJob
{
    public bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    public function handle(Client $mtr): void
    {
        Log::info('SyncLoginTimestampsJob: starting', ['dry_run' => $this->dryRun]);

        $stats = ['total' => 0, 'updated' => 0, 'no_history' => 0, 'skipped' => 0, 'errors' => 0];

        Person::where('contact_type', 'CLIENT')
            ->whereNotNull('mtr_account_uuid')
            ->select(['id', 'mtr_account_uuid', 'last_online_at'])
            ->each(function (Person $person) use ($mtr, &$stats) {
                $stats['total']++;

                try {
                    $event = $mtr->latestLoginEvent($person->mtr_account_uuid);

                    if (! $event || empty($event['created'])) {
                        $stats['no_history']++;
                        return;
                    }

                    $loginAt = \Carbon\Carbon::parse($event['created']);

                    // Only update if we have a newer timestamp than what's stored
                    if ($person->last_online_at && $person->last_online_at->gte($loginAt)) {
                        $stats['skipped']++;
                        return;
                    }

                    if (! $this->dryRun) {
                        $person->updateQuietly(['last_online_at' => $loginAt]);
                    }

                    $stats['updated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning('SyncLoginTimestampsJob: error fetching login event', [
                        'mtr_account_uuid' => $person->mtr_account_uuid,
                        'error'            => $e->getMessage(),
                    ]);
                }
            });

        Log::info('SyncLoginTimestampsJob: complete', $stats);
    }
}
