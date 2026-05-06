<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Henry / OpenClaw gateway
    |--------------------------------------------------------------------------
    |
    | Werner's ops AI runs locally via the OpenClaw gateway. The CRM uses two
    | independent transports:
    |
    |  1. Direct Telegram Bot API for outbound notifications (CRM → Werner)
    |     — see config/notifications.php
    |
    |  2. The gateway's HTTP /health endpoint here, for a status widget on
    |     the admin dashboard. Henry queries the CRM via MCP tools (which call
    |     our /api/henry/* routes), so we don't need a full RPC client.
    |
    */

    'gateway_url' => env('HENRY_GATEWAY_URL', 'http://localhost:18789'),

    /*
    |--------------------------------------------------------------------------
    | Henry → CRM API token
    |--------------------------------------------------------------------------
    |
    | Token presented by Henry's MCP server when calling /api/henry/* routes.
    | Verified by the HenryApiToken middleware. Generate any random 32-byte
    | string and put it in both .env (HENRY_API_TOKEN) and the OpenClaw MCP
    | server config.
    |
    */

    'api_token' => env('HENRY_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Reachability check
    |--------------------------------------------------------------------------
    |
    | The status widget caches the /health probe result for this many seconds
    | so we don't hammer the gateway on every dashboard render.
    |
    */

    'health_cache_seconds' => 30,

    'health_timeout_seconds' => 2,
];
