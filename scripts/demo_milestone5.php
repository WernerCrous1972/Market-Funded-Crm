<?php

declare(strict_types=1);

/*
 * Phase 4a milestone 5 live demo.
 *
 * Run with:  php artisan tinker --execute="require 'scripts/demo_milestone5.php';"
 *
 * Creates a synthetic CLIENT, fires two WhatsAppMessageReceived events,
 * and reports what happened (classifier verdict, routing, draft, Activity).
 */

use App\Events\WhatsApp\WhatsAppMessageReceived;
use App\Models\Activity;
use App\Models\AiDraft;
use App\Models\AiUsageLog;
use App\Models\OutreachInboundMessage;
use App\Models\Person;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Event;

echo "\n=== Milestone 5 live demo ===\n\n";

// ── 1. Spend baseline ────────────────────────────────────────────────────────
$spendBefore = (int) AiUsageLog::sum('cost_cents');
echo "AI spend (cents) before: {$spendBefore}\n";

// ── 2. Create synthetic CLIENT ───────────────────────────────────────────────
$person = Person::factory()->create([
    'first_name'    => 'Demo',
    'last_name'     => 'Inbound-' . substr((string) now()->timestamp, -4),
    'email'         => 'demo-inbound+' . uniqid() . '@example.com',
    'contact_type'  => 'CLIENT',
    'country'       => 'ZA',
    'account_manager_user_id' => null,  // forces ESCALATED_TO_HENRY on the complaint
]);
echo "Person: {$person->first_name} {$person->last_name} ({$person->id})\n";
echo "Account manager: " . ($person->account_manager_user_id ?? 'none — escalations go to Henry') . "\n\n";

// ── 3. Path A — high-confidence acknowledgement ───────────────────────────────
echo "── PATH A: 'thanks for the welcome!' ──\n";
$msgA = WhatsAppMessage::create([
    'person_id'     => $person->id,
    'direction'     => 'INBOUND',
    'wa_message_id' => 'wamid.demo_' . uniqid(),
    'body_text'     => 'thanks for the welcome message, looking forward to getting started!',
    'status'        => 'RECEIVED',
]);
Event::dispatch(new WhatsAppMessageReceived($person, $msgA));

$rowA = OutreachInboundMessage::where('whatsapp_message_id', $msgA->id)->first();
if ($rowA) {
    echo "  intent:     {$rowA->intent}\n";
    echo "  confidence: {$rowA->confidence}%\n";
    echo "  routing:    {$rowA->routing}\n";
    if ($rowA->auto_reply_draft_id) {
        $draftA = AiDraft::find($rowA->auto_reply_draft_id);
        echo "  draft status: {$draftA->status}\n";
        echo "  draft text:   " . trim($draftA->draft_text) . "\n";
        echo "  draft cost:   {$draftA->cost_cents}¢\n";
    }
} else {
    echo "  ❌ no OutreachInboundMessage row created\n";
}
echo "\n";

// ── 4. Path B — complaint, escalation ────────────────────────────────────────
echo "── PATH B: 'my account was suspended yesterday and I lost three trades' ──\n";
$msgB = WhatsAppMessage::create([
    'person_id'     => $person->id,
    'direction'     => 'INBOUND',
    'wa_message_id' => 'wamid.demo_' . uniqid(),
    'body_text'     => 'my account was suspended yesterday and I lost three trades, what is going on?? this is unacceptable',
    'status'        => 'RECEIVED',
]);
Event::dispatch(new WhatsAppMessageReceived($person, $msgB));

$rowB = OutreachInboundMessage::where('whatsapp_message_id', $msgB->id)->first();
if ($rowB) {
    echo "  intent:     {$rowB->intent}\n";
    echo "  confidence: {$rowB->confidence}%\n";
    echo "  routing:    {$rowB->routing}\n";
    echo "  assigned_to: " . ($rowB->assigned_to_user_id ?? 'Henry (no manager)') . "\n";
    echo "  auto_reply_draft_id: " . ($rowB->auto_reply_draft_id ?? '(none — pure escalation)') . "\n";
}
echo "\n";

// ── 5. Activity rows ─────────────────────────────────────────────────────────
echo "── Activity rows for this person ──\n";
$activities = Activity::where('person_id', $person->id)->orderBy('occurred_at')->get();
foreach ($activities as $a) {
    echo "  [{$a->type}] {$a->description}\n";
}
echo "\n";

// ── 6. Spend delta ───────────────────────────────────────────────────────────
$spendAfter = (int) AiUsageLog::sum('cost_cents');
$delta = $spendAfter - $spendBefore;
echo "AI spend after:  {$spendAfter}¢ (delta {$delta}¢)\n";

echo "\n=== Demo complete ===\n";
echo "If Telegram is wired, you should have received:\n";
echo "  • One holding-reply confirmation in CRM logs (Path B holding message)\n";
echo "  • One [MFU CRM] alert about the complaint escalation to Henry\n";
echo "Synthetic person id (delete with: Person::where('id','{$person->id}')->delete()): {$person->id}\n";
