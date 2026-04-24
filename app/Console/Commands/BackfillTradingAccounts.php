<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Offer;
use App\Models\TradingAccount;
use App\Services\MatchTrader\Client;
use App\Services\Pipeline\Classifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillTradingAccounts extends Command
{
    protected $signature = 'mtr:backfill-trading-accounts
        {--dry-run : Show what would be updated without writing}';

    protected $description = 'Backfill trading account records (login, offer, pipeline) from the MTR deposits and withdrawals APIs.

The /v1/accounts endpoint returns CRM contacts only — no MT5 login or offer data.
Real trading account data comes from deposits/withdrawals via accountInfo.tradingAccount.
This command re-fetches all deposits and withdrawals to upsert correct trading account records.

Usage:
  php -d memory_limit=512M artisan mtr:backfill-trading-accounts
  php -d memory_limit=512M artisan mtr:backfill-trading-accounts --dry-run';

    public function handle(Client $mtr): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] No data will be written.');
        }

        $this->info('Loading offer lookup and prop challenge UUIDs…');
        $offerLookup = Offer::all()->keyBy('mtr_offer_uuid');
        $propUuids   = Offer::where('is_prop_challenge', true)->pluck('mtr_offer_uuid')->toArray();
        Classifier::setPropOfferUuids($propUuids);

        $stats = ['seen' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0];

        $this->info('Processing deposits…');
        $this->processTransactions($mtr->allDeposits(), $offerLookup, $dryRun, $stats);

        $this->info('Processing withdrawals…');
        $this->processTransactions($mtr->allWithdrawals(), $offerLookup, $dryRun, $stats);

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Transaction rows seen',    number_format($stats['seen'])],
                ['Trading accounts upserted', number_format($stats['upserted'])],
                ['Skipped (no taUuid)',       number_format($stats['skipped'])],
                ['Errors',                   number_format($stats['errors'])],
            ]
        );

        if (! $dryRun) {
            $classified = TradingAccount::where('pipeline', '!=', 'UNCLASSIFIED')->count();
            $total      = TradingAccount::count();
            $this->info("Trading accounts with real pipeline: {$classified} / {$total}");
        }

        return self::SUCCESS;
    }

    private function processTransactions(
        \Generator $rows,
        \Illuminate\Support\Collection $offerLookup,
        bool $dryRun,
        array &$stats,
    ): void {
        // Track UUIDs we've already upserted this run to avoid redundant queries
        $seen = [];

        foreach ($rows as $raw) {
            $stats['seen']++;

            try {
                $accountInfo = $raw['accountInfo'] ?? [];
                $taInfo      = $accountInfo['tradingAccount'] ?? [];
                $taUuid      = $taInfo['uuid'] ?? null;

                if (! $taUuid) {
                    $stats['skipped']++;
                    continue;
                }

                if (isset($seen[$taUuid])) {
                    continue; // already processed this trading account in this run
                }
                $seen[$taUuid] = true;

                $mtrLogin  = (string) ($taInfo['login'] ?? '');
                $offerUuid = $taInfo['offerUuid'] ?? null;
                $offer     = $offerUuid ? $offerLookup->get($offerUuid) : null;
                $pipeline  = $offer?->pipeline ?? Classifier::classify(
                    $offer?->name ?? $offerUuid,
                    $offerUuid
                );

                if ($dryRun) {
                    $this->line("  DRY-RUN: {$taUuid} login={$mtrLogin} pipeline={$pipeline}");
                    $stats['upserted']++;
                    continue;
                }

                // Find the person via the contact email
                $email  = strtolower(trim($accountInfo['email'] ?? ''));
                $person = $email ? \App\Models\Person::where('email', $email)->first() : null;

                if (! $person) {
                    $stats['skipped']++;
                    continue;
                }

                TradingAccount::updateOrCreate(
                    ['mtr_account_uuid' => $taUuid],
                    [
                        'person_id'  => $person->id,
                        'mtr_login'  => $mtrLogin ?: null,
                        'offer_id'   => $offer?->id,
                        'pipeline'   => $pipeline,
                        'is_demo'    => false,
                        'is_active'  => true,
                        'opened_at'  => null,
                    ]
                );

                $stats['upserted']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('BackfillTradingAccounts: error', [
                    'uuid'  => $raw['uuid'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
