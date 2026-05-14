<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\MatchTrader\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot backfill for transactions.account_manager_user_id.
 *
 * Walks the MTR deposits + withdrawals APIs and patches each existing
 * transaction's account_manager_user_id from the API's
 * `accountInfo.accountManager.name` — which MTR snapshots at the time of
 * the transaction. Verified live 2026-05-14 that the API gives historical
 * attribution per row, not current ownership.
 *
 * Safe to re-run — only updates rows where account_manager_user_id is NULL
 * OR differs from the API value. Skips transactions that don't exist in our
 * DB (they'd be picked up by a regular sync first).
 *
 * Usage:
 *   php artisan mtr:backfill-tx-managers
 *   php artisan mtr:backfill-tx-managers --since=2026-05-01
 *   php artisan mtr:backfill-tx-managers --dry-run
 */
final class BackfillTransactionManagers extends Command
{
    protected $signature = 'mtr:backfill-tx-managers
        {--since=         : ISO date — only backfill transactions occurring on or after this date}
        {--dry-run        : Show what would be changed without writing}';

    protected $description = 'Backfill per-transaction account_manager_user_id from the MTR API.';

    public function handle(Client $mtr): int
    {
        ini_set('memory_limit', '1G');

        $since  = $this->option('since') ?: null;
        $dryRun = (bool) $this->option('dry-run');

        // MTR's API requires full ISO 8601. Bare YYYY-MM-DD is rejected.
        if ($since && ! str_contains($since, 'T')) {
            $since = \Carbon\Carbon::parse($since)->startOfDay()->toIso8601String();
        }

        $userLookup = User::pluck('id', 'name')->all();
        $this->info('Loaded ' . count($userLookup) . ' users for name → id lookup.');

        $stats = ['walked' => 0, 'updated' => 0, 'unchanged' => 0, 'no_tx' => 0, 'no_user' => 0];

        foreach (['deposits', 'withdrawals'] as $kind) {
            $this->line("Walking MTR /{$kind}…");
            $iter = $kind === 'deposits'
                ? $mtr->allDeposits($since)
                : $mtr->allWithdrawals($since);

            foreach ($iter as $raw) {
                $stats['walked']++;
                $uuid = $raw['uuid'] ?? null;
                if (! $uuid) continue;

                $tx = Transaction::where('mtr_transaction_uuid', $uuid)->first();
                if (! $tx) { $stats['no_tx']++; continue; }

                $am = $raw['accountInfo']['accountManager'] ?? null;
                $name = is_array($am) ? ($am['name'] ?? null) : ($am ?: null);
                $userId = $name ? ($userLookup[$name] ?? null) : null;

                if ($name && ! $userId) {
                    $stats['no_user']++;
                    Log::debug('backfill-tx-managers: unknown manager name', [
                        'tx_uuid' => $uuid, 'name' => $name,
                    ]);
                }

                if ($tx->account_manager_user_id === $userId) {
                    $stats['unchanged']++;
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  WOULD UPDATE %s  was=%s  now=%s (%s)',
                        $uuid,
                        $tx->account_manager_user_id ?? 'null',
                        $userId ?? 'null',
                        $name ?? '(no name)'
                    ));
                } else {
                    $tx->account_manager_user_id = $userId;
                    $tx->save();
                }
                $stats['updated']++;

                if ($stats['walked'] % 500 === 0) {
                    $this->line(sprintf('  …%d walked, %d updated', $stats['walked'], $stats['updated']));
                }
            }
        }

        $this->newLine();
        $this->info('Backfill complete:');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-12s %d', $k, $v));
        }

        return self::SUCCESS;
    }
}
