<?php

declare(strict_types=1);

namespace App\Jobs\Metrics;

use App\Models\Person;
use App\Models\PersonMetric;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nightly job that refreshes the person_metrics cache table for all people.
 *
 * Runs as a single job using chunked SQL aggregation — does NOT load Eloquent
 * models in a loop. Should complete in < 60 seconds for ~30k records.
 *
 * Dispatched from: App\Console\Commands\RefreshMetrics
 * Schedule:        daily at 01:00 SAST (after nightly MTR sync at 00:05)
 */
class RefreshPersonMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        private readonly ?string $personId = null, // null = refresh all
    ) {}

    public function handle(): void
    {
        $startedAt = now();
        $mtdStart  = now()->startOfMonth()->toDateTimeString();
        $today     = now()->toDateString();

        Log::info('RefreshPersonMetricsJob starting', [
            'person_id' => $this->personId ?? 'ALL',
        ]);

        // ── Step 1: Bulk-upsert metrics via a single SQL INSERT … ON CONFLICT ──
        // This avoids N+1 queries completely. We aggregate in Postgres and upsert.

        $personFilter = $this->personId
            ? "AND p.id = '{$this->personId}'"
            : '';

        $sql = <<<SQL
        INSERT INTO person_metrics (
            id,
            person_id,
            total_deposits_cents,
            total_withdrawals_cents,
            net_deposits_cents,
            total_challenge_purchases_cents,
            deposit_count,
            withdrawal_count,
            challenge_purchase_count,
            first_deposit_at,
            last_deposit_at,
            last_withdrawal_at,
            last_transaction_at,
            days_since_last_deposit,
            days_since_last_login,
            has_markets,
            has_capital,
            has_academy,
            deposits_mtd_cents,
            withdrawals_mtd_cents,
            challenge_purchases_mtd_cents,
            refreshed_at,
            created_at,
            updated_at
        )
        SELECT
            gen_random_uuid(),
            p.id                                                            AS person_id,

            COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                              THEN t.amount_cents END), 0)                 AS total_deposits_cents,

            COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_WITHDRAWAL'
                              THEN t.amount_cents END), 0)                 AS total_withdrawals_cents,

            COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                              THEN t.amount_cents END), 0)
            - COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_WITHDRAWAL'
                               THEN t.amount_cents END), 0)                AS net_deposits_cents,

            COALESCE(SUM(CASE WHEN t.category = 'CHALLENGE_PURCHASE'
                              THEN t.amount_cents END), 0)                 AS total_challenge_purchases_cents,

            COUNT(CASE WHEN t.category = 'EXTERNAL_DEPOSIT' THEN 1 END)   AS deposit_count,
            COUNT(CASE WHEN t.category = 'EXTERNAL_WITHDRAWAL' THEN 1 END)AS withdrawal_count,
            COUNT(CASE WHEN t.category = 'CHALLENGE_PURCHASE' THEN 1 END) AS challenge_purchase_count,

            MIN(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                     THEN t.occurred_at END)                               AS first_deposit_at,

            MAX(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                     THEN t.occurred_at END)                               AS last_deposit_at,

            MAX(CASE WHEN t.category = 'EXTERNAL_WITHDRAWAL'
                     THEN t.occurred_at END)                               AS last_withdrawal_at,

            MAX(t.occurred_at)                                             AS last_transaction_at,

            CASE WHEN MAX(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                               THEN t.occurred_at END) IS NOT NULL
                 THEN DATE_PART('day', NOW() - MAX(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                                                        THEN t.occurred_at END))::int
                 ELSE NULL END                                             AS days_since_last_deposit,

            CASE WHEN p.last_online_at IS NOT NULL
                 THEN DATE_PART('day', NOW() - p.last_online_at)::int
                 ELSE NULL END                                             AS days_since_last_login,

            -- Subqueries avoid a Cartesian product between transactions and trading_accounts.
            -- A direct LEFT JOIN on trading_accounts multiplies transaction rows by account count,
            -- inflating all SUM() aggregates.
            EXISTS (SELECT 1 FROM trading_accounts ta
                    WHERE ta.person_id = p.id AND ta.pipeline = 'MFU_MARKETS') AS has_markets,
            EXISTS (SELECT 1 FROM trading_accounts ta
                    WHERE ta.person_id = p.id AND ta.pipeline = 'MFU_CAPITAL') AS has_capital,
            EXISTS (SELECT 1 FROM trading_accounts ta
                    WHERE ta.person_id = p.id AND ta.pipeline = 'MFU_ACADEMY') AS has_academy,

            -- Month-to-date aggregates
            COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_DEPOSIT'
                               AND t.occurred_at >= '{$mtdStart}'
                              THEN t.amount_cents END), 0)                 AS deposits_mtd_cents,

            COALESCE(SUM(CASE WHEN t.category = 'EXTERNAL_WITHDRAWAL'
                               AND t.occurred_at >= '{$mtdStart}'
                              THEN t.amount_cents END), 0)                 AS withdrawals_mtd_cents,

            COALESCE(SUM(CASE WHEN t.category = 'CHALLENGE_PURCHASE'
                               AND t.occurred_at >= '{$mtdStart}'
                              THEN t.amount_cents END), 0)                 AS challenge_purchases_mtd_cents,

            NOW()                                                           AS refreshed_at,
            NOW()                                                           AS created_at,
            NOW()                                                           AS updated_at

        FROM people p
        LEFT JOIN transactions t
            ON t.person_id = p.id
            AND t.status   = 'DONE'

        WHERE 1=1 {$personFilter}

        GROUP BY p.id, p.last_online_at

        ON CONFLICT (person_id) DO UPDATE SET
            total_deposits_cents            = EXCLUDED.total_deposits_cents,
            total_withdrawals_cents         = EXCLUDED.total_withdrawals_cents,
            net_deposits_cents              = EXCLUDED.net_deposits_cents,
            total_challenge_purchases_cents = EXCLUDED.total_challenge_purchases_cents,
            deposit_count                   = EXCLUDED.deposit_count,
            withdrawal_count                = EXCLUDED.withdrawal_count,
            challenge_purchase_count        = EXCLUDED.challenge_purchase_count,
            first_deposit_at                = EXCLUDED.first_deposit_at,
            last_deposit_at                 = EXCLUDED.last_deposit_at,
            last_withdrawal_at              = EXCLUDED.last_withdrawal_at,
            last_transaction_at             = EXCLUDED.last_transaction_at,
            days_since_last_deposit         = EXCLUDED.days_since_last_deposit,
            days_since_last_login           = EXCLUDED.days_since_last_login,
            has_markets                     = EXCLUDED.has_markets,
            has_capital                     = EXCLUDED.has_capital,
            has_academy                     = EXCLUDED.has_academy,
            deposits_mtd_cents              = EXCLUDED.deposits_mtd_cents,
            withdrawals_mtd_cents           = EXCLUDED.withdrawals_mtd_cents,
            challenge_purchases_mtd_cents   = EXCLUDED.challenge_purchases_mtd_cents,
            refreshed_at                    = EXCLUDED.refreshed_at,
            updated_at                      = EXCLUDED.updated_at
        SQL;

        DB::statement($sql);

        $duration = now()->diffInSeconds($startedAt);
        $count    = PersonMetric::count();

        Log::info('RefreshPersonMetricsJob completed', [
            'metrics_rows' => $count,
            'duration_s'   => $duration,
        ]);
    }
}
