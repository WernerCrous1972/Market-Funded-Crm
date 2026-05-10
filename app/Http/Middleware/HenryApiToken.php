<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the bearer token Henry's MCP server presents when calling /api/henry/*.
 *
 * Token lives in HENRY_API_TOKEN env var on the CRM side, and is registered
 * in ~/.openclaw/openclaw.json on Henry's side. Both must match.
 *
 * If the token isn't configured at all the middleware refuses every request —
 * we never want this to silently allow unauthenticated traffic.
 */
class HenryApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('henry.api_token');

        if ($expected === '') {
            return response()->json([
                'error' => 'henry_api_token_not_configured',
            ], 503);
        }

        $presented = $request->bearerToken() ?? '';

        if (! hash_equals($expected, $presented)) {
            return response()->json([
                'error' => 'unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
