<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index()->comment('Denormalised for query speed');
            $table->uuid('trading_account_id')->nullable()->index();
            $table->string('mtr_transaction_uuid', 64)->unique()->comment('MTR uuid field');
            $table->string('type', 10)->comment('DEPOSIT or WITHDRAWAL');
            $table->bigInteger('amount_cents')->comment('Amount × 100 — never a float');
            $table->string('currency', 3)->default('USD');
            $table->string('status', 10)->comment('DONE, PENDING, FAILED, REVERSED');
            $table->string('gateway_name', 100)->nullable()->comment('paymentGatewayDetails.name');
            $table->text('remark')->nullable()->comment('From MTR remark field');
            $table->timestampTz('occurred_at')->comment('MTR created field');
            $table->timestampTz('synced_at');
            $table->string('pipeline', 20)->nullable()->comment('Copied from trading_account for fast filtering');

            // No created_at/updated_at — transactions are immutable once synced
            $table->index('type');
            $table->index('status');
            $table->index('occurred_at');
            $table->index('pipeline');
            $table->index(['person_id', 'type', 'status']);

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('trading_account_id')->references('id')->on('trading_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
