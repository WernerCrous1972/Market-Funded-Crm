<?php

declare(strict_types=1);

namespace App\Services\Henry;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;

/**
 * Henry's OpenClaw gateway runs locally and exposes most of its surface over
 * WebSocket RPC. PHP isn't a great fit for long-lived WS clients, and Phase 4a
 * doesn't need to invoke Henry's reasoning from Laravel.
 *
 * What we do need: a quick reachability probe for the dashboard status widget.
 * If the gateway is up, Henry can answer Werner's questions via the MCP tools
 * we expose. If it's down, the CRM still works — we just notify Werner via
 * Telegram directly.
 */
class GatewayClient
{
    public function __construct(
        private readonly ?GuzzleClient $http = null,
    ) {}

    /**
     * Returns one of:
     *   'online'       — /health responded 200
     *   'offline'      — connection refused / timeout
     *   'unknown'      — config missing
     *
     * Cached for config('henry.health_cache_seconds') so dashboard renders are cheap.
     */
    public function status(): string
    {
        $url = (string) config('henry.gateway_url');
        if ($url === '') {
            return 'unknown';
        }

        $ttl = (int) config('henry.health_cache_seconds', 30);

        return Cache::remember('henry:gateway:status', $ttl, function () use ($url): string {
            try {
                $response = $this->client()->get(rtrim($url, '/') . '/health');
                return $response->getStatusCode() === 200 ? 'online' : 'offline';
            } catch (GuzzleException) {
                return 'offline';
            }
        });
    }

    public function isReachable(): bool
    {
        return $this->status() === 'online';
    }

    private function client(): GuzzleClient
    {
        return $this->http ?? new GuzzleClient([
            'timeout' => (int) config('henry.health_timeout_seconds', 2),
        ]);
    }
}
