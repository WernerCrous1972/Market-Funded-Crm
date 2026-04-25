<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Persists the offer name at insert time so category backfills are
            // idempotent without requiring a live join to trading_accounts → offers.
            // Critical for TurboTrade Challenge withdrawals, which have no
            // trading_account_id but do carry offer data in the MTR API response.
            $table->string('offer_name')->nullable()->after('gateway_name');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('offer_name');
        });
    }
};
