<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Person;
use App\Services\MatchTrader\Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillPersonMtrTimestamps extends Command
{
    protected $signature = 'backfill:person-mtr-timestamps
        {--dry-run : Show what would be updated without writing}';

    protected $description = 'Populate mtr_created_at and mtr_updated_at on people from the MTR /v1/accounts endpoint.
Matches by email. Processes all accounts via the paginated generator — one API sweep, not one call per person.

Usage:
  php artisan backfill:person-mtr-timestamps
  php artisan backfill:person-mtr-timestamps --dry-run';

    public function handle(Client $mtr): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] No data will be written.');
        }

        $stats = [
            'seen'       => 0,
            'updated'    => 0,
            'no_match'   => 0,
            'no_dates'   => 0,
            'errors'     => 0,
        ];

        $this->info('Streaming all MTR accounts and matching by email…');

        foreach ($mtr->allAccounts() as $raw) {
            $stats['seen']++;

            try {
                $email = strtolower(trim($raw['email'] ?? ''));

                if (! $email) {
                    $stats['no_match']++;
                    continue;
                }

                $mtrCreated = $raw['created'] ?? null;
                $mtrUpdated = $raw['updated'] ?? null;

                if (! $mtrCreated && ! $mtrUpdated) {
                    $stats['no_dates']++;
                    continue;
                }

                $person = Person::where('email', $email)->first();

                if (! $person) {
                    Log::warning('BackfillPersonMtrTimestamps: no person for email', ['email' => $email]);
                    $stats['no_match']++;
                    continue;
                }

                if ($dryRun) {
                    $stats['updated']++;
                    continue;
                }

                $person->update([
                    'mtr_created_at' => $mtrCreated ? Carbon::parse($mtrCreated)->toIso8601String() : null,
                    'mtr_updated_at' => $mtrUpdated ? Carbon::parse($mtrUpdated)->toIso8601String() : null,
                ]);

                $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('BackfillPersonMtrTimestamps: error', [
                    'email' => $raw['email'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['MTR accounts seen',           number_format($stats['seen'])],
                ['People updated',              number_format($stats['updated'])],
                ['No matching person (warning)', number_format($stats['no_match'])],
                ['No dates in MTR response',    number_format($stats['no_dates'])],
                ['Errors',                      number_format($stats['errors'])],
            ]
        );

        return self::SUCCESS;
    }
}
