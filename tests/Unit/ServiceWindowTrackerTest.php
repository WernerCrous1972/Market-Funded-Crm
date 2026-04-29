<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\ServiceWindowTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ServiceWindowTracker', function () {

    it('returns true when an inbound message exists within 24 hours', function () {
        $person = Person::factory()->create();
        WhatsAppMessage::factory()->create([
            'person_id'  => $person->id,
            'direction'  => 'INBOUND',
            'status'     => 'RECEIVED',
            'created_at' => now()->subHours(12),
        ]);

        $tracker = new ServiceWindowTracker();
        expect($tracker->isInsideWindow($person))->toBeTrue()
            ->and($tracker->requiresTemplate($person))->toBeFalse();
    });

    it('returns false when the last inbound message was more than 24 hours ago', function () {
        $person = Person::factory()->create();
        WhatsAppMessage::factory()->create([
            'person_id'  => $person->id,
            'direction'  => 'INBOUND',
            'status'     => 'RECEIVED',
            'created_at' => now()->subHours(25),
        ]);

        $tracker = new ServiceWindowTracker();
        expect($tracker->isInsideWindow($person))->toBeFalse()
            ->and($tracker->requiresTemplate($person))->toBeTrue();
    });

    it('returns false when there are no inbound messages at all', function () {
        $person = Person::factory()->create();

        $tracker = new ServiceWindowTracker();
        expect($tracker->isInsideWindow($person))->toBeFalse();
    });

    it('does not count outbound messages as window openers', function () {
        $person = Person::factory()->create();
        WhatsAppMessage::factory()->create([
            'person_id'  => $person->id,
            'direction'  => 'OUTBOUND',
            'status'     => 'SENT',
            'created_at' => now()->subHours(1),
        ]);

        $tracker = new ServiceWindowTracker();
        expect($tracker->isInsideWindow($person))->toBeFalse();
    });

    it('extends window expiry on outbound messages when inbound arrives', function () {
        $person = Person::factory()->create();
        $msg = WhatsAppMessage::factory()->create([
            'person_id'                      => $person->id,
            'direction'                      => 'OUTBOUND',
            'status'                         => 'DELIVERED',
            'conversation_window_expires_at' => now()->subHour(),
        ]);

        $tracker = new ServiceWindowTracker();
        $tracker->extendWindow($person);

        $msg->refresh();
        expect($msg->conversation_window_expires_at)->not->toBeNull()
            ->and($msg->conversation_window_expires_at->isFuture())->toBeTrue();
    });

});
