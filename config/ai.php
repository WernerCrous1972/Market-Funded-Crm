<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic API
    |--------------------------------------------------------------------------
    */

    'anthropic' => [
        'api_key'     => env('ANTHROPIC_API_KEY'),
        'base_url'    => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version'     => '2023-06-01',
        'timeout'     => (int) env('ANTHROPIC_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-task model routing
    |--------------------------------------------------------------------------
    |
    | `ModelRouter::call($task, ...)` looks up the task name here and uses the
    | mapped model. Tasks not listed fall through to `default`. Override per
    | template via `outreach_templates.model_preference`.
    |
    | Why two different defaults:
    |   - Sonnet 4.6  for individual / high-stakes drafts (quality matters,
    |                 volume low, cost trivial).
    |   - Haiku 4.5   for bulk drafts and compliance pre-checks (volume
    |                 sensitive, work is short and templated).
    |
    */

    'tasks' => [
        'outreach_draft_individual'  => 'claude-sonnet-4-6',
        'outreach_draft_bulk'        => 'claude-haiku-4-5-20251001',
        'outreach_draft_high_stakes' => 'claude-sonnet-4-6',
        'compliance_check'           => 'claude-haiku-4-5-20251001',
        'inbound_classify'           => 'claude-haiku-4-5-20251001',
        'inbound_response_draft'     => 'claude-sonnet-4-6',
        'henry_query_complex'        => 'claude-sonnet-4-6',
    ],

    'default' => 'claude-haiku-4-5-20251001',

    /*
    |--------------------------------------------------------------------------
    | Failover chain
    |--------------------------------------------------------------------------
    |
    | If a primary model errors (timeout, 429, 5xx), `ModelRouter` walks the
    | chain in order. External providers (gpt-5.5-mini, kimi-2.5) are stubbed
    | this phase — calls return a "not configured" error so it's obvious from
    | logs when fallback fired without us silently sending nothing.
    |
    */

    'fallback_chain' => [
        'claude-sonnet-4-6'         => ['claude-haiku-4-5-20251001', 'gpt-5.5-mini', 'kimi-2.5'],
        'claude-haiku-4-5-20251001' => ['gpt-5.5-mini'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic per-million-token pricing (USD cents per 1M tokens)
    |--------------------------------------------------------------------------
    |
    | Used by ModelRouter to compute `cost_cents` per call. Update if pricing
    | changes (rarely). Format: input price / output price.
    |
    | Sonnet 4.6:  $3/MTok in,  $15/MTok out  → 300 / 1500 cents per million
    | Haiku 4.5:   $1/MTok in,  $5/MTok out   → 100 / 500 cents per million
    |
    */

    'pricing' => [
        'claude-sonnet-4-6'         => ['input' => 300,  'output' => 1500],
        'claude-haiku-4-5-20251001' => ['input' => 100,  'output' => 500],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost ceilings
    |--------------------------------------------------------------------------
    |
    | Soft cap → autonomous sends pause; reviewed sends still go.
    | Hard cap → ALL AI calls pause (compliance, classify, drafts).
    | Both reset at the start of each calendar month (Africa/Johannesburg).
    |
    */

    'cost_caps' => [
        'soft_usd' => (int) env('AI_COST_SOFT_CAP_USD', 300),
        'hard_usd' => (int) env('AI_COST_HARD_CAP_USD', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual kill switch
    |--------------------------------------------------------------------------
    |
    | Mirrors a Redis cache key (`ai:autonomous_paused`). Setting this env to
    | true overrides the cache and pauses on app boot. The admin AI Ops page
    | (milestone 3) reads/writes the cache.
    |
    */

    'autonomous_paused' => env('AI_AUTONOMOUS_PAUSED', false),

    /*
    |--------------------------------------------------------------------------
    | Inbound classifier confidence threshold
    |--------------------------------------------------------------------------
    |
    | Inbound replies whose classifier-reported confidence is >= this value
    | get an AI auto-reply (subject to compliance). Below it, escalate to the
    | assigned agent or Henry. Tunable from real data once we have any.
    |
    */

    'inbound_confidence_threshold' => (int) env('AI_INBOUND_CONFIDENCE_THRESHOLD', 75),

];
