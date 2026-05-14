<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-transaction account manager attribution.
 *
 * The CRM previously attributed transactions to whichever agent currently
 * owned the person (via people.account_manager_user_id). This is incorrect
 * for sales-performance reporting — if a lead is reassigned to a new agent,
 * the new agent shouldn't get credit for the old agent's deposits.
 *
 * MTR's API exposes `accountInfo.accountManager` per transaction row and
 * snapshots it at the time of the transaction (verified live 2026-05-14
 * against ntahlimahao4@gmail.com — same person, different manager names
 * across consecutive deposits, matching the CSV export).
 *
 * This migration adds the column. The sync jobs are updated to populate it
 * on every new transaction. Existing rows stay NULL until backfilled by
 * `mtr:sync --backfill-tx-managers`, after which all KpiQuery per-agent
 * queries read this column instead of people.account_manager_user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignUuid('account_manager_user_id')
                ->nullable()
                ->after('person_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('account_manager_user_id', 'transactions_acct_mgr_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_acct_mgr_idx');
            $table->dropForeign(['account_manager_user_id']);
            $table->dropColumn('account_manager_user_id');
        });
    }
};
