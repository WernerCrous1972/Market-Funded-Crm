<?php

declare(strict_types=1);

use App\Jobs\WhatsApp\ProcessWhatsAppWebhookJob;
use App\Models\Person;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Queue;

describe('WhatsApp webhook', function () {

    // ── GET verification challenge ─────────────────────────────────────────────

    it('responds to Meta verification challenge with hub.challenge', function () {
        config(['whatsapp.webhook_verify_token' => 'my_verify_token']);

        $this->get('/webhooks/whatsapp?' . http_build_query([
            'hub.mode'         => 'subscribe',
            'hub.verify_token' => 'my_verify_token',
            'hub.challenge'    => 'CHALLENGE_STRING_123',
        ]))->assertOk()
           ->assertSee('CHALLENGE_STRING_123');
    });

    it('returns 403 when verify token does not match', function () {
        config(['whatsapp.webhook_verify_token' => 'correct_token']);

        $this->get('/webhooks/whatsapp?' . http_build_query([
            'hub.mode'         => 'subscribe',
            'hub.verify_token' => 'wrong_token',
            'hub.challenge'    => 'CHALLENGE',
        ]))->assertStatus(403);
    });

    it('returns 403 when hub.mode is not subscribe', function () {
        config(['whatsapp.webhook_verify_token' => 'token']);

        $this->get('/webhooks/whatsapp?' . http_build_query([
            'hub.mode'         => 'unsubscribe',
            'hub.verify_token' => 'token',
            'hub.challenge'    => 'CHALLENGE',
        ]))->assertStatus(403);
    });

    // ── POST signature verification ────────────────────────────────────────────

    it('returns 401 when X-Hub-Signature-256 is missing or invalid', function () {
        config(['whatsapp.app_secret' => 'secret']);

        $body = json_encode(['object' => 'whatsapp_business_account']);

        $this->postJson('/webhooks/whatsapp', json_decode($body, true), [
            'X-Hub-Signature-256' => 'sha256=invalidsignature',
        ])->assertStatus(401);
    });

    it('accepts a valid signature and dispatches ProcessWhatsAppWebhookJob', function () {
        Queue::fake();
        config(['whatsapp.app_secret' => 'test_secret']);

        $body      = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'test_secret');

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE'             => 'application/json',
        ], $body)->assertOk();

        Queue::assertPushed(ProcessWhatsAppWebhookJob::class);
    });

    // ── ProcessWhatsAppWebhookJob — inbound message parsing ────────────────────

    it('inserts an inbound WhatsAppMessage row when a known person sends a message', function () {
        $person = Person::factory()->create(['phone_e164' => '+27681234567']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id'        => 'wamid.INBOUND001',
                            'from'      => '27681234567',
                            'type'      => 'text',
                            'text'      => ['body' => 'Hello Market Funded'],
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        ProcessWhatsAppWebhookJob::dispatchSync($payload);

        $this->assertDatabaseHas('whatsapp_messages', [
            'person_id'     => $person->id,
            'direction'     => 'INBOUND',
            'wa_message_id' => 'wamid.INBOUND001',
            'body_text'     => 'Hello Market Funded',
            'status'        => 'RECEIVED',
        ]);
    });

    it('is idempotent — duplicate inbound message ID is a no-op', function () {
        $person = Person::factory()->create(['phone_e164' => '+27681111111']);

        WhatsAppMessage::factory()->create([
            'person_id'     => $person->id,
            'direction'     => 'INBOUND',
            'wa_message_id' => 'wamid.DUP001',
            'status'        => 'RECEIVED',
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id'   => 'wamid.DUP001',
                            'from' => '27681111111',
                            'type' => 'text',
                            'text' => ['body' => 'Duplicate'],
                        ]],
                    ],
                ]],
            ]],
        ];

        ProcessWhatsAppWebhookJob::dispatchSync($payload);

        expect(
            WhatsAppMessage::where('wa_message_id', 'wamid.DUP001')->count()
        )->toBe(1);
    });

    it('updates message status on a delivery receipt', function () {
        $person = Person::factory()->create(['phone_e164' => '+27682222222']);

        $msg = WhatsAppMessage::factory()->create([
            'person_id'     => $person->id,
            'direction'     => 'OUTBOUND',
            'wa_message_id' => 'wamid.OUT001',
            'status'        => 'SENT',
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'statuses' => [[
                            'id'        => 'wamid.OUT001',
                            'status'    => 'delivered',
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];

        ProcessWhatsAppWebhookJob::dispatchSync($payload);

        expect($msg->fresh()->status)->toBe('DELIVERED');
    });

    it('does not create a person when an inbound arrives from an unknown number', function () {
        $countBefore = Person::count();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id'   => 'wamid.UNKNOWN',
                            'from' => '27699999999',
                            'type' => 'text',
                            'text' => ['body' => 'Who are you?'],
                        ]],
                    ],
                ]],
            ]],
        ];

        ProcessWhatsAppWebhookJob::dispatchSync($payload);

        expect(Person::count())->toBe($countBefore);
        expect(WhatsAppMessage::where('wa_message_id', 'wamid.UNKNOWN')->count())->toBe(0);
    });

});
