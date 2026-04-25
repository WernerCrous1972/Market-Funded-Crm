<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Groups all rows from a single command invocation for rollback
            $table->string('import_batch', 50)->index();

            $table->string('mtr_transaction_uuid', 36)->index();

            // reclassified | skipped | not_found
            $table->string('action', 20);

            $table->string('old_category', 25)->nullable();
            $table->string('new_category', 25)->nullable();

            // Values read from the CSV row — kept for audit / reconciliation
            $table->string('csv_offer_name')->nullable();
            $table->bigInteger('csv_amount_cents')->nullable();
            $table->string('csv_email')->nullable();

            $table->text('notes')->nullable();

            // Audit log is append-only — no updated_at
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_audit_log');
    }
};
