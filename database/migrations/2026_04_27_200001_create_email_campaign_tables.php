<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── email_templates ───────────────────────────────────────────────────
        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->string('name', 255);                  // Internal name e.g. "Re-engagement — dormant clients"
            $table->string('subject', 255);               // Email subject (supports merge tags)
            $table->longText('body_html');                // Full HTML body (from rich text editor)
            $table->longText('body_text')->nullable();    // Plain text fallback (auto-generated)
            $table->jsonb('merge_tags')->nullable();      // Available tags: [{tag, description, example}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── email_unsubscribes ────────────────────────────────────────────────
        Schema::create('email_unsubscribes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('email', 255)->unique();
            $table->string('reason', 100)->nullable();    // 'unsubscribe_link', 'bounce', 'complaint', 'manual'
            $table->uuid('person_id')->nullable();
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
            $table->timestampTz('unsubscribed_at');
            $table->timestamps();
        });

        DB::statement('CREATE INDEX email_unsubscribes_email_idx ON email_unsubscribes (email)');

        // ── email_campaigns ───────────────────────────────────────────────────
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->uuid('email_template_id');
            $table->foreign('email_template_id')->references('id')->on('email_templates');

            $table->string('name', 255);                  // Internal campaign name
            $table->string('subject_override', 255)->nullable(); // Override template subject if set
            $table->enum('status', [
                'DRAFT',        // Being built
                'SCHEDULED',    // Queued for future send
                'SENDING',      // Currently dispatching
                'SENT',         // All recipients processed
                'CANCELLED',    // Manually cancelled
                'FAILED',       // Hard failure
            ])->default('DRAFT');

            // Recipient selection
            $table->enum('recipient_mode', [
                'FILTER',       // Use a saved filter key
                'MANUAL',       // Manually selected individuals
                'COMBINED',     // Filter + manual additions
            ])->default('FILTER');
            $table->string('recipient_filter_key', 100)->nullable(); // e.g. 'at_risk', 'unconverted_leads'
            $table->jsonb('recipient_manual_ids')->nullable();        // array of person UUIDs

            // Scheduling
            $table->timestampTz('scheduled_at')->nullable();          // null = send immediately on approval
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // Stats (denormalised for fast dashboard display)
            $table->integer('recipient_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->integer('clicked_count')->default(0);
            $table->integer('bounced_count')->default(0);
            $table->integer('unsubscribed_count')->default(0);

            $table->string('from_name', 100)->default('Market Funded');
            $table->string('from_email', 255)->default('info@market-funded.com');

            $table->timestamps();
        });

        DB::statement('CREATE INDEX email_campaigns_status_idx ON email_campaigns (status)');
        DB::statement('CREATE INDEX email_campaigns_scheduled_at_idx ON email_campaigns (scheduled_at)');

        // ── email_campaign_recipients ─────────────────────────────────────────
        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('campaign_id');
            $table->foreign('campaign_id')->references('id')->on('email_campaigns')->cascadeOnDelete();
            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();

            $table->string('email', 255);                 // Snapshot of email at send time
            $table->string('first_name', 100)->nullable();// Snapshot for merge tags

            $table->enum('status', [
                'PENDING',
                'SENT',
                'BOUNCED',
                'FAILED',
                'SKIPPED',      // Unsubscribed / invalid email
            ])->default('PENDING');

            $table->string('message_id', 255)->nullable(); // Brevo message ID for tracking
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->unique(['campaign_id', 'person_id']);
            $table->timestamps();
        });

        DB::statement('CREATE INDEX ecr_campaign_status_idx ON email_campaign_recipients (campaign_id, status)');
        DB::statement('CREATE INDEX ecr_person_idx ON email_campaign_recipients (person_id)');

        // ── email_events ──────────────────────────────────────────────────────
        Schema::create('email_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('campaign_id')->nullable();
            $table->foreign('campaign_id')->references('id')->on('email_campaigns')->nullOnDelete();
            $table->uuid('recipient_id')->nullable();
            $table->foreign('recipient_id')->references('id')->on('email_campaign_recipients')->nullOnDelete();
            $table->uuid('person_id')->nullable();
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();

            $table->enum('type', ['SENT', 'OPENED', 'CLICKED', 'BOUNCED', 'UNSUBSCRIBED', 'COMPLAINED']);
            $table->string('url_clicked', 500)->nullable();  // For CLICKED events
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();
        });

        DB::statement('CREATE INDEX email_events_campaign_type_idx ON email_events (campaign_id, type)');
        DB::statement('CREATE INDEX email_events_person_idx ON email_events (person_id)');
        DB::statement('CREATE INDEX email_events_occurred_at_idx ON email_events (occurred_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_events');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_unsubscribes');
        Schema::dropIfExists('email_templates');
    }
};
