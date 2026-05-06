<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_compliance_checks — result of running the compliance agent against a draft.
 *
 * Hard banned phrases (regex blocklist, runs first) and AI-driven softer rules
 * (model self-check, runs second) both feed into the same `flags` jsonb. A
 * single `passed = false` blocks the draft from sending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_compliance_checks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('draft_id');
            $table->foreign('draft_id')->references('id')->on('ai_drafts')->cascadeOnDelete();

            $table->string('model_used', 50);
            $table->boolean('passed');
            $table->jsonb('flags')->default('[]');         // [{rule, severity, excerpt}, ...]
            $table->text('verdict_text')->nullable();

            $table->integer('tokens_input')->default(0);
            $table->integer('tokens_output')->default(0);
            $table->integer('cost_cents')->default(0);

            $table->timestampTz('created_at')->nullable();

            $table->index('draft_id');
            $table->index(['passed', 'created_at']);
        });

        // Now that the table exists, link it back from ai_drafts
        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->foreign('compliance_check_id')
                ->references('id')->on('ai_compliance_checks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_drafts', function (Blueprint $table) {
            $table->dropForeign(['compliance_check_id']);
        });
        Schema::dropIfExists('ai_compliance_checks');
    }
};
