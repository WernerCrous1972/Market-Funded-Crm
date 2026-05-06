<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram bot — outbound CRM notifications
    |--------------------------------------------------------------------------
    |
    | The CRM uses the same Telegram bot Henry uses, sending to Werner directly
    | via the Telegram Bot API. Messages are prefixed with [MFU CRM] so Werner
    | can distinguish them from Henry's analytical messages in the same chat.
    |
    | Two voices, one chat — by design.
    |
    */

    'telegram' => [
        'bot_token'    => env('TELEGRAM_BOT_TOKEN'),
        'chat_id'      => env('TELEGRAM_CHAT_ID'),
        'enabled'      => env('TELEGRAM_NOTIFY_ENABLED', true),
        'message_prefix' => '[MFU CRM]',
        'timeout_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity → emoji mapping
    |--------------------------------------------------------------------------
    |
    | Prepended to messages so urgency reads at a glance.
    |
    */

    'severities' => [
        'info'     => 'ℹ️',
        'warning'  => '⚠️',
        'alert'    => '🔔',
        'critical' => '🚨',
    ],
];
