<?php

declare(strict_types=1);

use App\Exceptions\WhatsAppSendException;
use App\Services\WhatsApp\MetaCloudClient;
use App\Services\WhatsApp\SendResult;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

describe('MetaCloudClient', function () {

    function makeClient(array $responses): MetaCloudClient
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $guzzle  = new GuzzleClient(['handler' => $handler]);
        return new MetaCloudClient($guzzle);
    }

    it('returns a SendResult with waMessageId on success', function () {
        $payload = [
            'messaging_product' => 'whatsapp',
            'messages'          => [['id' => 'wamid.TEST123']],
        ];

        $client = makeClient([new Response(200, [], json_encode($payload))]);
        $result = $client->sendFreeForm('+27681234567', 'Hello!');

        expect($result)->toBeInstanceOf(SendResult::class)
            ->and($result->success)->toBeTrue()
            ->and($result->waMessageId)->toBe('wamid.TEST123');
    });

    it('sends a template message with variables', function () {
        $payload = ['messages' => [['id' => 'wamid.TPL1']]];

        $client = makeClient([new Response(200, [], json_encode($payload))]);
        $result = $client->sendTemplate('+27681234567', 'welcome_template', ['Werner', '$500']);

        expect($result->success)->toBeTrue()
            ->and($result->waMessageId)->toBe('wamid.TPL1');
    });

    it('throws WhatsAppSendException on HTTP error', function () {
        $client = makeClient([
            new Response(400, [], json_encode([
                'error' => ['code' => 100, 'message' => 'Invalid parameter', 'title' => 'Invalid parameter'],
            ])),
        ]);

        expect(fn () => $client->sendFreeForm('+27681234567', 'Hi'))
            ->toThrow(WhatsAppSendException::class);
    });

    it('throws WhatsAppSendException on network failure', function () {
        $client = makeClient([
            new ConnectException('Connection refused', new Request('POST', 'test')),
        ]);

        expect(fn () => $client->sendFreeForm('+27681234567', 'Hi'))
            ->toThrow(WhatsAppSendException::class);
    });

    it('throws WhatsAppSendException when response has no message ID', function () {
        $client = makeClient([new Response(200, [], json_encode(['messages' => []]))]);

        expect(fn () => $client->sendFreeForm('+27681234567', 'Hi'))
            ->toThrow(WhatsAppSendException::class);
    });

    it('verifies a valid webhook signature', function () {
        config(['whatsapp.app_secret' => 'mysecret']);

        $payload   = '{"test":true}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'mysecret');

        $client = new MetaCloudClient();
        expect($client->verifyWebhookSignature($payload, $signature))->toBeTrue();
    });

    it('rejects an invalid webhook signature', function () {
        config(['whatsapp.app_secret' => 'mysecret']);

        $client = new MetaCloudClient();
        expect($client->verifyWebhookSignature('{"test":true}', 'sha256=invalidsig'))->toBeFalse();
    });

});
