<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Sync\SyncAccountsJob;
use App\Jobs\Sync\SyncBranchesJob;
use App\Jobs\Sync\SyncDepositsJob;
use App\Jobs\Sync\SyncOffersJob;
use App\Jobs\Sync\SyncOurChallengeBuyersJob;
use App\Jobs\Sync\SyncWithdrawalsJob;
use App\Services\MatchTrader\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MtrSync extends Command
{
    protected $signature = 'mtr:sync
        {--full                    : Sync all records from the beginning of time}
        {--incremental             : Sync only records from the last 24 hours}
        {--dry-run                 : Log what would happen without writing to the database}
        {--offers-only             : Only sync offers and branches}
        {--accounts-only           : Only sync accounts (people + trading accounts)}
        {--deposits-only           : Only sync deposits}
        {--withdrawals-only        : Only sync withdrawals}
        {--challenge-buyers-only   : Only run the cross-branch challenge buyer import}';

    protected $description = 'Sync data from the Match-Trader Broker API.

Usage:
  php artisan mtr:sync --full              Full sync (all 91k+ accounts)
  php artisan mtr:sync --incremental       Last 24 hours only
  php artisan mtr:sync --dry-run --full    Preview what would be imported
  php artisan mtr:sync --offers-only       Refresh offer/pipeline catalog only
  php artisan mtr:sync --accounts-only     Sync accounts only
  php artisan mtr:sync --deposits-only     Sync deposits only
  php artisan mtr:sync --withdrawals-only  Sync withdrawals only';

    public function handle(Client $mtr): int
    {
        $dryRun      = (bool) $this->option('dry-run');
        $full        = (bool) $this->option('full');
        $incremental = (bool) $this->option('incremental');
        $since       = $incremental ? now()->subDay()->toIso8601String() : null;

        $onlyOffers          = (bool) $this->option('offers-only');
        $onlyAccounts        = (bool) $this->option('accounts-only');
        $onlyDeposits        = (bool) $this->option('deposits-only');
        $onlyWithdrawals     = (bool) $this->option('withdrawals-only');
        $onlyChallengeBuyers = (bool) $this->option('challenge-buyers-only');
        $runAll              = ! $onlyOffers && ! $onlyAccounts && ! $onlyDeposits
            && ! $onlyWithdrawals && ! $onlyChallengeBuyers;

        if (! $full && ! $incremental && $runAll) {
            $this->error('Specify --full, --incremental, or a specific --*-only flag.');
            return self::INVALID;
        }

        if ($dryRun) {
            $this->warn('[DRY-RUN] No data will be written to the database.');
        }

        $startedAt = now();
        $this->info("MTR Sync started at {$startedAt->toDateTimeString()}");

        if ($runAll || $onlyOffers) {
            $this->runJob('Branches', new SyncBranchesJob($dryRun), $mtr);
            $this->runJob('Offers',   new SyncOffersJob($dryRun),   $mtr);
        }

        if ($runAll || $onlyAccounts) {
            $this->runJob('Accounts', new SyncAccountsJob($dryRun, $incremental), $mtr);
        }

        // Challenge buyers runs after accounts so newly created people are already present.
        // Brand code in challengeName is the ownership signal — branch is intentionally ignored.
        if ($runAll || $onlyChallengeBuyers) {
            $this->runJob('Challenge Buyers', new SyncOurChallengeBuyersJob($dryRun), $mtr);
        }

        if ($runAll || $onlyDeposits) {
            $this->runJob('Deposits', new SyncDepositsJob($dryRun, $since), $mtr);
        }

        if ($runAll || $onlyWithdrawals) {
            $this->runJob('Withdrawals', new SyncWithdrawalsJob($dryRun, $since), $mtr);
        }

        $duration = $startedAt->diffInSeconds(now());
        $this->newLine();
        $this->info("Sync complete in {$duration}s");

        if (! $dryRun) {
            $this->writeSummary($startedAt, $duration);
        }

        return self::SUCCESS;
    }

    private function runJob(string $label, object $job, Client $mtr): void
    {
        $this->line("  → {$label}...");
        $job->handle($mtr);
        $this->line("    <fg=green>done</>");
    }

    private function writeSummary(\Carbon\Carbon $startedAt, int|float $duration): void
    {
        $summary = [
            'started_at'  => $startedAt->toIso8601String(),
            'duration_s'  => $duration,
            'people'      => \App\Models\Person::count(),
            'leads'       => \App\Models\Person::where('contact_type', 'LEAD')->count(),
            'clients'     => \App\Models\Person::where('contact_type', 'CLIENT')->count(),
            'accounts'    => \App\Models\TradingAccount::count(),
            'deposits'    => \App\Models\Transaction::where('type', 'DEPOSIT')->count(),
            'withdrawals' => \App\Models\Transaction::where('type', 'WITHDRAWAL')->count(),
        ];

        $filename = 'mtr-sync-summaries/' . $startedAt->format('Y-m-d') . '.json';
        Storage::put($filename, json_encode($summary, JSON_PRETTY_PRINT));

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($summary)->except(['started_at', 'duration_s'])
                ->map(fn ($v, $k) => [$k, number_format((int) $v)])
                ->values()->toArray()
        );
    }
}
