<?php

declare(strict_types=1);

namespace App\Jobs\Sync;

use App\Models\Branch;
use App\Services\MatchTrader\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncBranchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(public readonly bool $dryRun = false) {}

    public function handle(Client $mtr): void
    {
        Log::info('SyncBranchesJob: starting');

        $branches = $mtr->branches();
        $included = array_map('strtolower', (array) config('matchtrader.included_branches'));

        $created = 0;
        $updated = 0;

        foreach ($branches as $raw) {
            $uuid = $raw['uuid'] ?? $raw['id'] ?? null;
            $name = trim($raw['name'] ?? '');

            if (! $uuid || ! $name) {
                continue;
            }

            $isIncluded = in_array(strtolower($name), $included, true);

            if (! $this->dryRun) {
                $branch = Branch::updateOrCreate(
                    ['mtr_branch_uuid' => $uuid],
                    ['name' => $name, 'is_included' => $isIncluded]
                );
                $branch->wasRecentlyCreated ? $created++ : $updated++;
            } else {
                Log::info("DRY-RUN branch: {$name} (included: " . ($isIncluded ? 'yes' : 'no') . ')');
                $created++;
            }
        }

        Log::info("SyncBranchesJob: done — created={$created} updated={$updated}");
    }
}
