<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('person_id')->unique();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();

            // Deposit / withdrawal aggregates (EXTERNAL only — real cashflow)
            $table->bigInteger('total_deposits_cents')->default(0);        // EXTERNAL_DEPOSIT sum
            $table->bigInteger('total_withdrawals_cents')->default(0);     // EXTERNAL_WITHDRAWAL sum
            $table->bigInteger('net_deposits_cents')->default(0);          // deposits - withdrawals
            $table->bigInteger('total_challenge_purchases_cents')->default(0); // CHALLENGE_PURCHASE sum
            $table->integer('deposit_count')->default(0);
            $table->integer('withdrawal_count')->default(0);
            $table->integer('challenge_purchase_count')->default(0);

            // Recency
            $table->timestampTz('first_deposit_at')->nullable();
            $table->timestampTz('last_deposit_at')->nullable();
            $table->timestampTz('last_withdrawal_at')->nullable();
            $table->timestampTz('last_transaction_at')->nullable();

            // Derived recency counters (in days, updated nightly)
            $table->integer('days_since_last_deposit')->nullable();
            $table->integer('days_since_last_login')->nullable();

            // Pipeline presence flags (quick filter without joins)
            $table->boolean('has_markets')->default(false);
            $table->boolean('has_capital')->default(false);
            $table->boolean('has_academy')->default(false);

            // Month-to-date aggregates (reset/refreshed nightly)
            $table->bigInteger('deposits_mtd_cents')->default(0);
            $table->bigInteger('withdrawals_mtd_cents')->default(0);
            $table->bigInteger('challenge_purchases_mtd_cents')->default(0);

            $table->timestampTz('refreshed_at')->nullable();
            $table->timestamps();
        });

        // Indexes for the most common filter queries
        DB::statement('CREATE INDEX person_metrics_last_deposit_at_idx ON person_metrics (last_deposit_at DESC NULLS LAST)');
        DB::statement('CREATE INDEX person_metrics_net_deposits_idx ON person_metrics (net_deposits_cents DESC)');
        DB::statement('CREATE INDEX person_metrics_days_since_login_idx ON person_metrics (days_since_last_login)');
        DB::statement('CREATE INDEX person_metrics_pipeline_flags_idx ON person_metrics (has_markets, has_capital, has_academy)');
    }

    public function down(): void
    {
        Schema::dropIfExists('person_metrics');
    }
};
