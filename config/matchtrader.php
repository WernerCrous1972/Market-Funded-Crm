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
        'TTR',   // QuickTrade / TurboTrade
        'MFU',   // Market Funded
    ],
];
