<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * outreach_templates — reusable AI prompt templates for outbound messages.
 *
 * One template per (trigger_event, channel) pairing. Admin enables `autonomous_enabled`
 * before any event-driven send fires; new templates always start disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->string('name', 100);
            $table->string('trigger_event', 100)->nullable();
            $table->string('channel', 16);                  // WHATSAPP | EMAIL
            $table->text('system_prompt');
            $table->text('compliance_rules')->nullable();
            $table->string('model_preference', 50)->nullable();

            $table->boolean('autonomous_enabled')->default(false);
            $table->boolean('is_active')->default(true);

            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['trigger_event', 'channel', 'is_active'], 'outreach_templates_trigger_idx');
            $table->index('autonomous_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_templates');
    }
};
