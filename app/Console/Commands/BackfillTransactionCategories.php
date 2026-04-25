<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Transaction\CategoryClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionCategories extends Command
{
    protected $signature = 'backfill:transaction-categories
        {--chunk=500 : Rows to process per batch}
        {--dry-run   : Classify and report without writing}';

    protected $description = 'Classify all existing transactions and populate the category column.
Idempotent — safe to re-run. Chunks through all rows to avoid memory exhaustion.

Usage:
  php artisan backfill:transaction-categories
  php artisan backfill:transaction-categories --dry-run';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        if ($dryRun) {
            $this->warn('[DRY-RUN] No data will be written.');
        }

        $this->info('Loading offer name lookup…');
        // Build trading_account_id → offer name fallback for rows that have
        // a trading account link but no persisted offer_name column.
        $offerNameByTradingAccountId = DB::table('trading_accounts')
            ->join('offers', 'offers.id', '=', 'trading_accounts.offer_id')
            ->pluck('offers.name', 'trading_accounts.id')
            ->toArray();

        $counts = [
            'EXTERNAL_DEPOSIT'    => 0,
            'EXTERNAL_WITHDRAWAL' => 0,
            'CHALLENGE_PURCHASE'  => 0,
            'CHALLENGE_REFUND'    => 0,
            'INTERNAL_TRANSFER'   => 0,
            'UNCLASSIFIED'        => 0,
        ];

        $total     = Transaction::count();
        $processed = 0;

        $this->info("Processing {$total} transactions in chunks of {$chunkSize}…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Transaction::select(['id', 'type', 'status', 'gateway_name', 'offer_name', 'trading_account_id', 'occurred_at'])
            ->chunkById($chunkSize, function ($chunk) use (
                $dryRun, $offerNameByTradingAccountId, &$counts, &$processed, $bar
            ) {
                $updates = [];

                foreach ($chunk as $tx) {
                    // Use the persisted offer_name first (set at insert time from API).
                    // Fall back to the trading_account → offer join for older rows.
                    $offerName = $tx->offer_name
                        ?? (isset($tx->trading_account_id)
                            ? ($offerNameByTradingAccountId[$tx->trading_account_id] ?? null)
                            : null);

                    $category = CategoryClassifier::classify(
                        type:        $tx->type,
                        status:      $tx->status,
                        gatewayName: $tx->gateway_name,
                        offerName:   $offerName,
                        occurredAt:  $tx->occurred_at ? (string) $tx->occurred_at : null,
                    );

                    $counts[$category]++;

                    if (! $dryRun) {
                        $updates[] = ['id' => $tx->id, 'category' => $category];
                    }
                }

                if (! $dryRun && $updates !== []) {
                    // Bulk update using a single query per chunk
                    foreach ($updates as $row) {
                        DB::table('transactions')
                            ->where('id', $row['id'])
                            ->update(['category' => $row['category']]);
                    }
                }

                $processed += count($chunk);
                $bar->advance(count($chunk));
            });

        $bar->finish();
        $this->newLine(2);

        // ── Summary table ────────────────────────────────────────────────────
        $this->table(
            ['Category', 'Count', '% of Total'],
            collect($counts)->map(fn ($cnt, $cat) => [
                $cat,
                number_format($cnt),
                $total > 0 ? number_format($cnt / $total * 100, 1) . '%' : '—',
            ])->values()->toArray()
        );

        $this->newLine();

        // ── UNCLASSIFIED breakdown ───────────────────────────────────────────
        if ($counts['UNCLASSIFIED'] > 0) {
            $this->warn("UNCLASSIFIED breakdown by status:");
            $breakdown = DB::table('transactions')
                ->where('category', 'UNCLASSIFIED')
                ->selectRaw('status, count(*) as cnt')
                ->groupBy('status')
                ->orderByDesc('cnt')
                ->get();

            foreach ($breakdown as $row) {
                $this->line("  {$row->status}: {$row->cnt}");
            }

            // Surface DONE rows if any — these indicate a gap in the rules
            $doneUnclassified = DB::table('transactions')
                ->where('category', 'UNCLASSIFIED')
                ->where('status', 'DONE')
                ->count();

            if ($doneUnclassified > 0) {
                $this->newLine();
                $this->error("⚠  {$doneUnclassified} DONE transactions are UNCLASSIFIED — sample rows:");

                DB::table('transactions')
                    ->where('category', 'UNCLASSIFIED')
                    ->where('status', 'DONE')
                    ->select(['id', 'type', 'status', 'gateway_name', 'occurred_at'])
                    ->limit(10)
                    ->get()
                    ->each(function ($row) {
                        $this->line(
                            "  {$row->type} | gateway={$row->gateway_name} | {$row->occurred_at}"
                        );
                    });
            } else {
                $this->info('✓ All UNCLASSIFIED rows are non-DONE (PENDING/FAILED/REVERSED) — expected.');
            }
        }

        return self::SUCCESS;
    }
}
