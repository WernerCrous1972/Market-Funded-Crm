<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Metrics\RefreshPersonMetricsJob;
use Illuminate\Console\Command;

/**
 * Refresh the person_metrics cache table.
 *
 * Usage:
 *   php artisan metrics:refresh                    # all people (queued)
 *   php artisan metrics:refresh --person=<uuid>    # single person (queued)
 *   php artisan metrics:refresh --sync             # all people, run synchronously (no queue)
 */
class RefreshMetrics extends Command
{
    protected $signature = 'metrics:refresh
        {--person= : UUID of a single person to refresh}
        {--sync    : Run synchronously instead of dispatching to the queue}';

    protected $description = 'Refresh the person_metrics cache table (all people or a single person)';

    public function handle(): int
    {
        $personId = $this->option('person');
        $sync     = $this->option('sync');

        if ($personId) {
            $this->info("Refreshing metrics for person {$personId}...");
        } else {
            $this->info('Refreshing metrics for all people...');
        }

        $job = new RefreshPersonMetricsJob($personId ?: null);

        if ($sync) {
            $job->handle();
            $this->info('Done (synchronous).');
        } else {
            dispatch($job);
            $this->info('Job dispatched to queue.');
        }

        return self::SUCCESS;
    }
}
