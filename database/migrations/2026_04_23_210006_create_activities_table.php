<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index();
            $table->string('type', 50)->comment(
                'DEPOSIT, WITHDRAWAL, LOGIN, TRADE_OPENED, NOTE_ADDED, EMAIL_SENT, EMAIL_OPENED, ' .
                'CALL_LOG, WHATSAPP_SENT, TASK_CREATED, TASK_COMPLETED, STATUS_CHANGED, DUPLICATE_DETECTED'
            );
            $table->text('description')->comment('Human-readable summary');
            $table->jsonb('metadata')->nullable()->comment('Type-specific data: amount, trade details, etc.');
            $table->uuid('user_id')->nullable()->index()->comment('The agent or system who triggered it');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index('type');
            $table->index('occurred_at');

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
