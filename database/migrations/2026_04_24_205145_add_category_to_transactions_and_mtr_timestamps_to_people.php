<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add category to transactions as a plain varchar — avoids PostgreSQL
        // enum DDL lock and makes adding values in future a simple ALTER.
        // Valid values: EXTERNAL_DEPOSIT, EXTERNAL_WITHDRAWAL, CHALLENGE_PURCHASE,
        //               CHALLENGE_REFUND, INTERNAL_TRANSFER, UNCLASSIFIED
        DB::statement("
            ALTER TABLE transactions
            ADD COLUMN category VARCHAR(25) NOT NULL DEFAULT 'UNCLASSIFIED'
        ");
        DB::statement('CREATE INDEX transactions_category_index ON transactions (category)');

        // Add MTR-side created/updated timestamps to people
        Schema::table('people', function (Blueprint $table) {
            $table->timestampTz('mtr_created_at')->nullable()->after('mtr_last_synced_at');
            $table->timestampTz('mtr_updated_at')->nullable()->after('mtr_created_at');
            $table->index('mtr_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['mtr_created_at']);
            $table->dropColumn(['mtr_created_at', 'mtr_updated_at']);
        });

        DB::statement('DROP INDEX IF EXISTS transactions_category_index');
        DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS category');
    }
};
