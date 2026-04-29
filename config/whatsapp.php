<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flag
    |--------------------------------------------------------------------------
    | Set to true ONLY after Meta approval is received and credentials are
    | configured. While false, MessageSender short-circuits with a log message
    | and no messages are sent — safe to deploy to production.
    */
    'feature_enabled' => (bool) env('WA_FEATURE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Meta Cloud API credentials
    |--------------------------------------------------------------------------
    */
    'phone_number_id'       => env('WA_PHONE_NUMBER_ID'),
    'business_account_id'   => env('WA_BUSINESS_ACCOUNT_ID'),
    'access_token'          => env('WA_ACCESS_TOKEN'),
    'webhook_verify_token'  => env('WA_WEBHOOK_VERIFY_TOKEN'),
    'app_secret'            => env('WA_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Graph API base URL
    |--------------------------------------------------------------------------
    */
    'graph_api_url' => env('WA_GRAPH_API_URL', 'https://graph.facebook.com/v19.0'),

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    | Conservative ceiling well below Meta's 500 msg/sec per-number limit.
    */
    'rate_limit_per_second' => (int) env('WA_RATE_LIMIT_PER_SECOND', 80),

];
