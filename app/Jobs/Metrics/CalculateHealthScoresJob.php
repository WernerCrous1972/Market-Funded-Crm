<?php

declare(strict_types=1);

namespace App\Jobs\Metrics;

use App\Models\Person;
use App\Models\PersonMetric;
use App\Services\Health\HealthScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly job that calculates health scores for all CLIENT records.
 *
 * Only CLIENTs are scored — LEADs have no trading activity to evaluate.
 * Processes in chunks of 500 to stay within memory limits.
 *
 * Schedule: daily at 01:30 SAST (after metrics:refresh at 01:00)
 */
class CalculateHealthScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        private readonly ?string $personId = null, // null = all clients
    ) {}

    public function handle(HealthScorer $scorer): void
    {
        $startedAt = now();
        $processed = 0;
        $skipped   = 0;

        Log::info('CalculateHealthScoresJob starting', [
            'person_id' => $this->personId ?? 'ALL',
        ]);

        // Only score CLIENTs — LEADs have no meaningful activity
        $query = PersonMetric::query()
            ->join('people', 'people.id', '=', 'person_metrics.person_id')
            ->where('people.contact_type', 'CLIENT')
            ->select('person_metrics.*');

        if ($this->personId) {
            $query->where('person_metrics.person_id', $this->personId);
        }

        $query->chunkById(500, function ($chunk) use ($scorer, &$processed, &$skipped) {
            foreach ($chunk as $metrics) {
                try {
                    $result = $scorer->score($metrics);

                    $metrics->update([
                        'health_score'              => $result['score'],
                        'health_grade'              => $result['grade'],
                        'health_score_breakdown'    => $result['breakdown'],
                        'health_score_calculated_at' => now(),
                    ]);

                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('Health score calculation failed for person', [
                        'person_id' => $metrics->person_id,
                        'error'     => $e->getMessage(),
                    ]);
                    $skipped++;
                }
            }
        }, 'person_metrics.id', 'id');

        $duration = now()->diffInSeconds($startedAt);

        Log::info('CalculateHealthScoresJob completed', [
            'processed'  => $processed,
            'skipped'    => $skipped,
            'duration_s' => $duration,
        ]);
    }
}
