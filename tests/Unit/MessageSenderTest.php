<?php

declare(strict_types=1);

use App\Exceptions\TemplateRequiredException;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Person;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\MessageSender;
use App\Services\WhatsApp\ServiceWindowTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

describe('MessageSender', function () {

    beforeEach(function () {
        Queue::fake();
        config(['whatsapp.feature_enabled' => true]);
    });

    it('dispatches SendWhatsAppMessageJob for free-form inside window', function () {
        $person = Person::factory()->create(['phone_e164' => '+27681234567']);

        // Simulate inside service window
        WhatsAppMessage::factory()->create([
            'person_id'  => $person->id,
            'direction'  => 'INBOUND',
            'status'     => 'RECEIVED',
            'created_at' => now()->subHours(1),
        ]);

        $sender = new MessageSender(new ServiceWindowTracker());
        $sender->send($person, 'Hello!');

        Queue::assertPushed(SendWhatsAppMessageJob::class, function ($job) use ($person) {
            return $job->personId === $person->id
                && $job->body === 'Hello!'
                && $job->templateName === null;
        });
    });

    it('dispatches SendWhatsAppMessageJob with template outside window', function () {
        $person = Person::factory()->create(['phone_e164' => '+27681234567']);

        $sender = new MessageSender(new ServiceWindowTracker());
        $sender->send($person, 'Preview text', 'welcome_en');

        Queue::assertPushed(SendWhatsAppMessageJob::class, function ($job) use ($person) {
            return $job->personId === $person->id
                && $job->templateName === 'welcome_en';
        });
    });

    it('throws TemplateRequiredException when outside window and no template given', function () {
        $person = Person::factory()->create(['phone_e164' => '+27681234567']);

        // No inbound messages — outside window
        $sender = new MessageSender(new ServiceWindowTracker());

        expect(fn () => $sender->send($person, 'Hello without template'))
            ->toThrow(TemplateRequiredException::class);

        Queue::assertNothingPushed();
    });

    it('short-circuits and logs when feature_enabled is false', function () {
        config(['whatsapp.feature_enabled' => false]);

        $person = Person::factory()->create(['phone_e164' => '+27681234567']);
        $sender = new MessageSender(new ServiceWindowTracker());

        // Should not throw, should not push job
        $sender->send($person, 'Should not send');

        Queue::assertNothingPushed();
    });

});
