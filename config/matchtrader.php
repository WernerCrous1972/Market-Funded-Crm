<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Match-Trader Broker API
    |--------------------------------------------------------------------------
    */

    'base_url' => env('MTR_BASE_URL'),

    'token' => env('MTR_TOKEN'),

    'rate_limit_per_minute' => (int) env('MTR_RATE_LIMIT_PER_MINUTE', 500),

    'retry_attempts' => (int) env('MTR_RETRY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Sync schedule gate
    |--------------------------------------------------------------------------
    |
    | Controls whether the `mtr:sync` cron schedules in routes/console.php
    | fire on this host. Production is set to false while its IP is blocked
    | at the Cloudflare layer from reaching the MTR API — sync runs from
    | Werner's Mac instead, via SSH tunnel into production's Postgres
    | (see scripts/sync_to_production.sh).
    |
    | Defaults true so local dev + the Mac-as-relay path keep working
    | unchanged. Set MTR_SYNC_ENABLED=false in .env to disable.
    |
    | Reads through config(), not env(), so config:cache stays effective.
    |
    */

    'sync_enabled' => filter_var(env('MTR_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Branches included in sync (all others are excluded)
    |--------------------------------------------------------------------------
    */

    'included_branches' => [
        'Market Funded',
        'QuickTrade',
    ],

    'excluded_branches' => [
        'ATY Markets',
        'Africa Markets',
        'EarniMax',
        'Global Forex Brokers',
        'Imali Markets',
        'The Magasa Group',
        'Infinity Funded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead sources excluded from sync
    |--------------------------------------------------------------------------
    */

    'excluded_lead_sources' => [
        'DISTRIBUTOR',
        'STAFF',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction filters
    |--------------------------------------------------------------------------
    */

    'valid_transaction_statuses' => ['DONE'],

    'excluded_gateways' => [
        'correction',
        'stock market college commission',
    ],

    'excluded_remarks' => [
        'correction',
        'mt5 transfer',
        'commission',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction category classification
    |--------------------------------------------------------------------------
    |
    | challenge_keywords — case-insensitive substrings matched against the
    |   offer name. A match is necessary but not sufficient for CHALLENGE_PURCHASE.
    |
    | our_brand_codes — case-sensitive whole-word tokens (space-bounded) that
    |   must ALSO appear in the offer name for a transaction to count as OUR
    |   challenge revenue. Challenges under any other brand code belong to
    |   affiliate brokers and must NOT classify as CHALLENGE_PURCHASE — they
    |   fall through to EXTERNAL_DEPOSIT or INTERNAL_TRANSFER per gateway.
    |   To add a new brand (e.g. after a rebrand), append here. Do NOT modify
    |   the classifier class.
    |
    */

    'challenge_keywords' => [
        'Instant Funded',
        'Evaluation',
        'Verification',
        'Consistency',
    ],

    'our_brand_codes' => [
        'TTR',   // QuickTrade / TurboTrade (current naming, post-rebrand)
        'QT',    // QuickTrade (legacy naming, pre-rebrand — same broker as TTR)
        'MFU',   // Market Funded
    ],
];
