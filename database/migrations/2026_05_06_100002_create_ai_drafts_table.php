<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_drafts — every AI-generated message before/after send.
 *
 * Three modes:
 *   AUTONOMOUS      — event-driven; no human review; full prompt NOT stored (compress)
 *   REVIEWED        — agent clicked "Draft with AI"; full prompt stored
 *   BULK_REVIEWED   — agent ran a bulk-draft action; full prompt stored
 *
 * Once status leaves pending_review, the row is effectively immutable —
 * compliance + cost data is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();

            $table->uuid('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('outreach_templates')->nullOnDelete();

            $table->string('mode', 24);            // AUTONOMOUS | REVIEWED | BULK_REVIEWED
            $table->string('channel', 16);         // WHATSAPP | EMAIL
            $table->string('model_used', 50);

            $table->string('prompt_hash', 64);     // SHA256 — always populated
            $table->text('prompt_full')->nullable(); // null for AUTONOMOUS, populated for REVIEWED/BULK

            $table->text('draft_text');            // what the model produced
            $table->text('final_text')->nullable(); // post-edit (REVIEWED) or copy of draft_text (AUTONOMOUS)

            $table->string('status', 32);          // pending_review | approved | rejected | sent | failed | blocked_compliance

            $table->uuid('compliance_check_id')->nullable();
            // FK added separately after ai_compliance_checks table exists

            $table->uuid('triggered_by_user_id')->nullable();
            $table->foreign('triggered_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('triggered_by_event', 100)->nullable();

            $table->integer('tokens_input')->default(0);
            $table->integer('tokens_output')->default(0);
            $table->integer('cost_cents')->default(0);

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['person_id', 'status']);
            $table->index(['mode', 'status']);
            $table->index('created_at');
            $table->index('triggered_by_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
    }
};
