<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── agents ────────────────────────────────────────────────────────────
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('key', 50)->unique();           // e.g. 'deposits', 'retention'
            $table->string('name', 100);                   // Display name
            $table->enum('department', [
                'EDUCATION', 'DEPOSITS', 'CHALLENGES',
                'SUPPORT', 'ONBOARDING', 'RETENTION',
                'NURTURING', 'GENERAL',
            ]);
            $table->text('system_prompt')->nullable();     // Werner fills in later
            $table->boolean('is_active')->default(true);
            $table->jsonb('escalation_rules')->nullable(); // Placeholder for future
            $table->timestamps();
        });

        // ── whatsapp_templates ────────────────────────────────────────────────
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 100)->unique();         // Matches Meta template name exactly
            $table->enum('category', [
                'MARKETING', 'UTILITY', 'AUTHENTICATION', 'SERVICE',
            ]);
            $table->string('language_code', 10)->default('en');
            $table->text('body_text');                     // Full body with {{1}} placeholders
            $table->jsonb('variables')->nullable();        // [{name, description, example}]
            $table->enum('department', [
                'EDUCATION', 'DEPOSITS', 'CHALLENGES',
                'SUPPORT', 'ONBOARDING', 'RETENTION',
                'NURTURING', 'GENERAL',
            ]);
            $table->enum('status', [
                'DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'PAUSED',
            ])->default('DRAFT');
            $table->string('meta_template_id', 100)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX wa_templates_status_idx ON whatsapp_templates (status)');
        DB::statement('CREATE INDEX wa_templates_department_idx ON whatsapp_templates (department)');

        // ── whatsapp_messages ─────────────────────────────────────────────────
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->enum('direction', ['OUTBOUND', 'INBOUND']);
            $table->string('wa_message_id', 100)->nullable()->unique(); // Meta's message ID
            $table->uuid('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('whatsapp_templates')->nullOnDelete();
            $table->text('body_text');
            $table->text('media_url')->nullable();
            $table->enum('status', [
                'PENDING', 'SENT', 'DELIVERED', 'READ', 'FAILED', 'RECEIVED',
            ])->default('PENDING');
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->string('agent_key', 50)->nullable();   // Which internal agent sent it
            $table->uuid('sent_by_user_id')->nullable();   // null = autonomous, set = manual
            $table->foreign('sent_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->timestampTz('conversation_window_expires_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX wa_messages_person_dir_idx ON whatsapp_messages (person_id, direction, created_at DESC)');
        DB::statement('CREATE INDEX wa_messages_status_idx ON whatsapp_messages (status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('agents');
    }
};
