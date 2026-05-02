<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->after('branch');
            $table->uuid('account_manager_user_id')->nullable()->after('account_manager');

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('account_manager_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('branch_id');
            $table->index('account_manager_user_id');
        });

        // ── Retroactive backfill ──────────────────────────────────────────────
        // Populate branch_id by matching the existing denormalised branch name
        // string against branches.name. No-match rows remain null (fail-safe:
        // null branch_id = invisible to scoped users until next sync resolves).
        DB::statement(<<<'SQL'
            UPDATE people p
            SET    branch_id = b.id
            FROM   branches b
            WHERE  p.branch    = b.name
              AND  p.branch    IS NOT NULL
              AND  p.branch_id IS NULL
        SQL);

        // Populate account_manager_user_id by matching the existing free-text
        // account_manager name against users.name (best-effort; will resolve
        // reliably on the next sync or on explicit reassignment via the UI).
        DB::statement(<<<'SQL'
            UPDATE people p
            SET    account_manager_user_id = u.id
            FROM   users u
            WHERE  p.account_manager            = u.name
              AND  p.account_manager            IS NOT NULL
              AND  p.account_manager_user_id    IS NULL
        SQL);

        // ── Data-step reporting ───────────────────────────────────────────────
        $total      = DB::table('people')->count();
        $withBranch = DB::table('people')->whereNotNull('branch_id')->count();
        $withAm     = DB::table('people')->whereNotNull('account_manager_user_id')->count();
        $orphanAm   = DB::table('people')
            ->whereNotNull('account_manager')
            ->whereNull('account_manager_user_id')
            ->count();

        $branchPct = $total > 0 ? round($withBranch / $total * 100) : 0;
        $amPct     = $total > 0 ? round($withAm / $total * 100) : 0;

        if (isset($this->command)) {
            $this->command->info("Phase C migration — backfill counts:");
            $this->command->info("  Total people:                   {$total}");
            $this->command->info("  branch_id populated:            {$withBranch} ({$branchPct}%)");
            $this->command->info("  account_manager_user_id set:    {$withAm} ({$amPct}%)");
            $this->command->warn("  Orphan account_manager (name set, no user match): {$orphanAm}");
        }
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['account_manager_user_id']);
            $table->dropIndex(['branch_id']);
            $table->dropIndex(['account_manager_user_id']);
            $table->dropColumn(['branch_id', 'account_manager_user_id']);
        });
    }
};
