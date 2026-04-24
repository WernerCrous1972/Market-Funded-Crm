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
];
