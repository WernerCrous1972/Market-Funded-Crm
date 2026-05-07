<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Services\AI\OutreachOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily cron — finds clients whose dormancy thresholds (14d / 30d) have
 * just been crossed and dispatches OutreachOrchestrator::autonomousSend
 * for each matching enabled template.
 *
 * Dedup: skips any (person, trigger_event) where a draft already exists
 * within `dedup_days` (defaults to 30). Stops dormant_14d firing every
 * single day for the same person and stops dormant_14d + dormant_30d
 * stomping each other for someone who just crossed both.
 *
 * Schedule: 09:00 Africa/Johannesburg, registered in routes/console.php.
 */
class DetectDormantClientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function __construct(
        private readonly int $dedupDays = 30,
    ) {}

    public function handle(): void
    {
        $stats = ['scanned' => 0, 'dispatched' => 0, 'skipped_dedup' => 0, 'errors' => 0];

        $orch = app(OutreachOrchestrator::class);

        // Triggers and their threshold lower bounds. dormant_30d takes
        // precedence over dormant_14d when both match — we only fire
        // dormant_14d for clients in [14, 30) days.
        $triggers = [
            'dormant_30d' => ['min_days' => 30, 'max_days' => null],
            'dormant_14d' => ['min_days' => 14, 'max_days' => 29],
        ];

        foreach ($triggers as $triggerEvent => $window) {
            $templates = OutreachTemplate::where('trigger_event', $triggerEvent)
                ->where('autonomous_enabled', true)
                ->where('is_active', true)
                ->get();

            if ($templates->isEmpty()) {
                Log::debug("DetectDormantClientsJob: no enabled templates for {$triggerEvent}");
                continue;
            }

            $query = PersonMetric::query()->where('days_since_last_login', '>=', $window['min_days']);
            if ($window['max_days'] !== null) {
                $query->where('days_since_last_login', '<=', $window['max_days']);
            }

            $query->chunkById(200, function ($metrics) use ($templates, $triggerEvent, $orch, &$stats) {
                $personIds = $metrics->pluck('person_id')->all();
                $people    = Person::with('metrics')
                    ->whereIn('id', $personIds)
                    ->where('contact_type', 'CLIENT')
                    ->get()
                    ->keyBy('id');

                foreach ($metrics as $metric) {
                    $stats['scanned']++;
                    $person = $people->get($metric->person_id);
                    if (! $person) {
                        continue; // not a CLIENT or already deleted
                    }

                    if ($this->recentlyDispatched($person->id, $triggerEvent)) {
                        $stats['skipped_dedup']++;
                        continue;
                    }

                    foreach ($templates as $template) {
                        try {
                            $draft = $orch->autonomousSend($person, $template, $triggerEvent);
                            if ($draft) {
                                $stats['dispatched']++;
                            }
                        } catch (\Throwable $e) {
                            $stats['errors']++;
                            Log::error('DetectDormantClientsJob: per-person error', [
                                'trigger_event' => $triggerEvent,
                                'person_id'     => $person->id,
                                'template_id'   => $template->id,
                                'error'         => $e->getMessage(),
                            ]);
                        }
                    }
                }
            });
        }

        Log::info('DetectDormantClientsJob complete', $stats);
    }

    private function recentlyDispatched(string $personId, string $triggerEvent): bool
    {
        return AiDraft::where('person_id', $personId)
            ->where('triggered_by_event', $triggerEvent)
            ->where('created_at', '>=', now()->subDays($this->dedupDays))
            ->exists();
    }
}
