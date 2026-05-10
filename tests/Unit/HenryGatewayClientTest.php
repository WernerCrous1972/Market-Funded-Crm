<?php

declare(strict_types=1);

use App\Services\Henry\GatewayClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

describe('HenryGatewayClient', function () {

    beforeEach(function () {
        config()->set('henry.gateway_url', 'http://localhost:18789');
        config()->set('henry.health_cache_seconds', 30);
        config()->set('henry.health_timeout_seconds', 2);
        Cache::flush();
    });

    $makeHenryClient = function (array $responses): GatewayClient {
        $mock   = new MockHandler($responses);
        $stack  = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);
        return new GatewayClient($guzzle);
    };

    it('reports online when /health returns 200', function () use ($makeHenryClient) {
        $client = $makeHenryClient([new Response(200, [], '{"status":"ok"}')]);

        expect($client->status())->toBe('online');
        expect($client->isReachable())->toBeTrue();
    });

    it('reports offline on connection failure', function () use ($makeHenryClient) {
        $exception = new ConnectException('refused', new Request('GET', '/health'));
        $client    = $makeHenryClient([$exception]);

        expect($client->status())->toBe('offline');
        expect($client->isReachable())->toBeFalse();
    });

    it('reports offline on non-200 response', function () use ($makeHenryClient) {
        $client = $makeHenryClient([new Response(503, [], 'unavailable')]);

        expect($client->status())->toBe('offline');
    });

    it('reports unknown when gateway_url is missing', function () {
        config()->set('henry.gateway_url', '');
        $client = new GatewayClient();

        expect($client->status())->toBe('unknown');
        expect($client->isReachable())->toBeFalse();
    });

    it('caches the result so a second call does not re-probe', function () use ($makeHenryClient) {
        // Only one response queued — second call would error if it actually re-probed
        $client = $makeHenryClient([new Response(200, [], '{"status":"ok"}')]);

        expect($client->status())->toBe('online');
        expect($client->status())->toBe('online');
    });

});
