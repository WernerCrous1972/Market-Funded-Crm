<?php

declare(strict_types=1);

namespace App\Listeners\AI;

use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Services\AI\OutreachOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Generic listener that turns a domain event into an autonomous AI send.
 *
 * For each (trigger_event, channel) pair we look up matching templates
 * where `autonomous_enabled = true` AND `is_active = true`, and call
 * OutreachOrchestrator::autonomousSend() for the named person.
 *
 * The orchestrator handles cost-cap, compliance, dispatch, Activity log,
 * and Telegram alerts internally. This listener only does the lookup +
 * fan-out; it never decides whether to send.
 *
 * Listener subclasses provide `$triggerEvent` + `personIdFromEvent()` —
 * Laravel resolves them via the event-listener map registered in
 * AppServiceProvider.
 */
abstract class DispatchAutonomousOutreach
{
    // Intentionally NOT ShouldQueue — runs synchronously inline with the
    // dispatching event. The listener does one indexed DB lookup and a
    // no-op return when no enabled templates match (the common path). When
    // a template DOES match, the orchestrator's downstream work
    // (ModelRouter HTTP calls, MessageSender's job dispatch) provides
    // the queueing boundary, not this listener.
    //
    // Why not ShouldQueue: queuing would dispatch a Bus::dispatch() PER
    // Person::factory()->create() in the test suite, breaking unrelated
    // tests that assert Queue::assertNothingPushed(). Inline is also
    // simpler to reason about — the autonomous-template gate inside
    // autonomousSend() is already what stops everything when the system
    // isn't configured to fire.

    abstract protected function triggerEvent(): string;

    abstract protected function personIdFromEvent(object $event): ?string;

    /**
     * Channel filter for templates. Default: WHATSAPP. Override for email
     * triggers when those land.
     */
    protected function channel(): string
    {
        return 'WHATSAPP';
    }

    public function handle(object $event): void
    {
        $personId = $this->personIdFromEvent($event);
        if (! $personId) {
            return;
        }

        $person = Person::with('metrics')->find($personId);
        if (! $person) {
            Log::warning('DispatchAutonomousOutreach: person not found', [
                'trigger_event' => $this->triggerEvent(),
                'person_id'     => $personId,
            ]);
            return;
        }

        $templates = OutreachTemplate::where('trigger_event', $this->triggerEvent())
            ->where('channel', $this->channel())
            ->where('autonomous_enabled', true)
            ->where('is_active', true)
            ->get();

        if ($templates->isEmpty()) {
            Log::debug('DispatchAutonomousOutreach: no matching enabled templates', [
                'trigger_event' => $this->triggerEvent(),
                'channel'       => $this->channel(),
            ]);
            return;
        }

        $orch = app(OutreachOrchestrator::class);

        // If multiple templates match the same trigger, all of them fire.
        // In practice we expect 0 or 1 per trigger; let the data guide us.
        foreach ($templates as $template) {
            try {
                $orch->autonomousSend($person, $template, $this->triggerEvent());
            } catch (\Throwable $e) {
                Log::error('DispatchAutonomousOutreach: orchestrator threw', [
                    'trigger_event' => $this->triggerEvent(),
                    'template_id'   => $template->id,
                    'person_id'     => $person->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
