<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * outreach_inbound_messages — inbound replies routed through the AI inbound flow.
 *
 * NOT every WhatsApp inbound row gets one; only the ones we ask Claude to
 * classify. Records the routing decision (auto-replied vs escalated) so we
 * can tune the confidence threshold from real data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_inbound_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('whatsapp_message_id');
            $table->foreign('whatsapp_message_id')
                ->references('id')->on('whatsapp_messages')
                ->cascadeOnDelete();

            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();

            $table->string('intent', 50)->nullable();   // question | acknowledgment | unsubscribe | complaint | ...
            $table->integer('confidence')->nullable();   // 0..100
            $table->string('routing', 32);              // auto_replied | escalated_to_agent | escalated_to_henry

            $table->uuid('auto_reply_draft_id')->nullable();
            $table->foreign('auto_reply_draft_id')
                ->references('id')->on('ai_drafts')
                ->nullOnDelete();

            $table->uuid('assigned_to_user_id')->nullable();
            $table->foreign('assigned_to_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->timestampTz('created_at')->nullable();

            $table->index('person_id');
            $table->index('routing');
            $table->index('confidence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_inbound_messages');
    }
};
