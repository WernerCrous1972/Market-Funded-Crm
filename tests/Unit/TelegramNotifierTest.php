<?php

declare(strict_types=1);

use App\Services\Notifications\TelegramNotifier;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

describe('TelegramNotifier', function () {

    beforeEach(function () {
        config()->set('notifications.telegram.bot_token', 'fake-token');
        config()->set('notifications.telegram.chat_id', '12345');
        config()->set('notifications.telegram.enabled', true);
        config()->set('notifications.telegram.message_prefix', '[MFU CRM]');
        config()->set('notifications.telegram.timeout_seconds', 5);
        config()->set('notifications.severities', [
            'info'     => 'I',
            'warning'  => 'W',
            'alert'    => 'A',
            'critical' => 'C',
        ]);
    });

    $makeNotifier = function (array $responses, ?array &$capturedRequests = null): TelegramNotifier {
        $capturedRequests = [];
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $stack->push(function ($handler) use (&$capturedRequests) {
            return function ($request, $options) use ($handler, &$capturedRequests) {
                $capturedRequests[] = $request;
                return $handler($request, $options);
            };
        });
        $guzzle = new GuzzleClient(['handler' => $stack]);
        return new TelegramNotifier($guzzle);
    };

    it('sends a notification with severity emoji and prefix', function () use ($makeNotifier) {
        $notifier = $makeNotifier(
            [new Response(200, [], json_encode(['ok' => true, 'result' => ['message_id' => 99]]))],
            $captured,
        );

        $result = $notifier->notify('Sync failed', 'alert');

        expect($result)->toBeTrue();
        expect($captured)->toHaveCount(1);

        $body = json_decode((string) $captured[0]->getBody(), true);
        expect($body['chat_id'])->toBe('12345');
        expect($body['text'])->toBe('A [MFU CRM] Sync failed');
        expect($body['parse_mode'])->toBe('HTML');
    });

    it('defaults to info severity when an invalid one is passed', function () use ($makeNotifier) {
        $notifier = $makeNotifier(
            [new Response(200, [], json_encode(['ok' => true]))],
            $captured,
        );

        $notifier->notify('Hello', 'unknown-level');

        $body = json_decode((string) $captured[0]->getBody(), true);
        expect($body['text'])->toStartWith('I [MFU CRM]');
    });

    it('returns false when not configured', function () {
        config()->set('notifications.telegram.bot_token', null);
        $notifier = new TelegramNotifier();

        expect($notifier->notify('test'))->toBeFalse();
    });

    it('returns false when notifications are disabled', function () use ($makeNotifier) {
        config()->set('notifications.telegram.enabled', false);
        $notifier = $makeNotifier([new Response(200, [], '{}')], $captured);

        expect($notifier->notify('test'))->toBeFalse();
        expect($captured)->toHaveCount(0);
    });

    it('returns false on connection failure without throwing', function () use ($makeNotifier) {
        $exception = new ConnectException('boom', new Request('POST', '/'));
        $notifier  = $makeNotifier([$exception], $captured);

        expect($notifier->notify('test'))->toBeFalse();
    });

    it('returns false when the API responds not-ok', function () use ($makeNotifier) {
        $notifier = $makeNotifier(
            [new Response(200, [], json_encode(['ok' => false, 'description' => 'chat not found']))],
            $captured,
        );

        expect($notifier->notify('test'))->toBeFalse();
    });

    it('isReachable returns true when getMe succeeds', function () use ($makeNotifier) {
        $notifier = $makeNotifier(
            [new Response(200, [], json_encode(['ok' => true, 'result' => ['username' => 'henrybot']]))],
            $captured,
        );

        expect($notifier->isReachable())->toBeTrue();
    });

    it('isReachable returns false on connection failure', function () use ($makeNotifier) {
        $exception = new ConnectException('boom', new Request('GET', '/'));
        $notifier  = $makeNotifier([$exception], $captured);

        expect($notifier->isReachable())->toBeFalse();
    });

    it('isReachable returns false when not configured', function () {
        config()->set('notifications.telegram.bot_token', null);
        $notifier = new TelegramNotifier();

        expect($notifier->isReachable())->toBeFalse();
    });

});
