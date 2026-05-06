<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_usage_log — daily-aggregated AI spend per task type / model.
 *
 * Populated by ModelRouter on every successful API call. CostCeilingGuard
 * sums month-to-date and short-circuits when soft/hard caps fire.
 *
 * Uniqueness: one row per (date, task_type, model). Increment, don't insert
 * fresh per call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->date('date');
            $table->string('task_type', 50);    // outreach_draft_individual | compliance_check | inbound_classify | ...
            $table->string('model', 50);        // claude-sonnet-4-6 | claude-haiku-4-5 | gpt-5.5-mini | ...

            $table->integer('call_count')->default(0);
            $table->bigInteger('tokens_input')->default(0);
            $table->bigInteger('tokens_output')->default(0);
            $table->integer('cost_cents')->default(0);

            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['date', 'task_type', 'model']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_log');
    }
};
