<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the "System — Inbound auto-reply" OutreachTemplate that the inbound
 * routing listener uses for AI-drafted replies. Idempotent: re-running has
 * no effect once the row exists.
 *
 * The template name is the lookup key — see config/outreach_inbound.php.
 * Edit the system_prompt from the Filament UI; do not rename.
 *
 * down() removes the row only if its system_prompt hasn't been edited away
 * from the seeded default. This avoids destroying admin edits on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = (string) config('outreach_inbound.auto_reply_template_name', 'System — Inbound auto-reply');

        $exists = DB::table('outreach_templates')->where('name', $name)->exists();
        if ($exists) {
            return;
        }

        DB::table('outreach_templates')->insert([
            'id'                 => (string) Str::uuid(),
            'name'               => $name,
            'trigger_event'      => null,
            'channel'            => 'WHATSAPP',
            'system_prompt'      => $this->defaultSystemPrompt(),
            'compliance_rules'   => 'Reply must be short (1–3 sentences). Do not make any specific promise about returns, timelines, or outcomes. Do not impersonate a regulator. If the inbound message asks anything beyond simple acknowledgement or basic information, defer to a human.',
            'model_preference'   => null,
            'autonomous_enabled' => true,  // safe — only used for inbound, gated by classifier
            'is_active'          => true,
            'created_by_user_id' => null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function down(): void
    {
        $name = (string) config('outreach_inbound.auto_reply_template_name', 'System — Inbound auto-reply');
        DB::table('outreach_templates')
            ->where('name', $name)
            ->where('system_prompt', $this->defaultSystemPrompt())
            ->delete();
    }

    private function defaultSystemPrompt(): string
    {
        return <<<PROMPT
You are a polite, concise assistant for Market Funded, a brokerage CRM. A
client has replied to one of our WhatsApp messages and a classifier has
already decided their reply is either a simple acknowledgement or a simple
question we can answer.

Write a SHORT plain-text reply (1–3 sentences max). Use the recipient's
first name if you have it. Be warm but professional. Do NOT:
  - Make any specific promise about returns, performance, timelines, or outcomes.
  - Quote prices or fees (you may have stale data).
  - Use marketing language ("guaranteed", "risk-free", "easy money", etc).
  - Sign off with a fake name. Do not sign off at all — the channel speaks for itself.

If the inbound message turns out to be more complex than the classifier
suggested (e.g. a hidden complaint, a regulatory question, a specific
financial figure request), reply with a single short sentence saying
you'll get the right person back to them — that's safer than guessing.
PROMPT;
    }
};
