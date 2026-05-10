<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inbound reply routing
|--------------------------------------------------------------------------
|
| Configures how the AI handles WhatsApp replies. Two routes:
|
|   1. Auto-reply  — confidence >= threshold AND intent is "safe".
|                    Drafts a personalised reply via Sonnet, runs compliance,
|                    sends. Subject to cost guard + kill switch.
|
|   2. Escalate    — everything else. Sends a short, pre-written holding
|                    message so the client doesn't sit in silence, then
|                    fires a Telegram alert to the assigned account manager
|                    or to Henry. Holding message picked by classified
|                    intent below.
|
| Threshold + Sonnet auto-reply task name are configured in `config/ai.php`.
| This file owns the intent vocabulary and the holding-message strings.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Classifier intents (closed vocabulary)
    |--------------------------------------------------------------------------
    |
    | The classifier is told to pick exactly one of these. Anything outside
    | the list is coerced to `unclear` so downstream code never sees a
    | surprise string.
    |
    */

    'intents' => [
        'acknowledgment',         // "thanks", "ok", "got it"
        'simple_question',         // a short question we can answer from public KB
        'complex_question',        // needs a human
        'complaint',               // negative tone, dissatisfaction
        'unsubscribe',             // wants out
        'sensitive_request',       // refunds, account closure, dispute
        'unclear',                 // can't tell — fallback
    ],

    /*
    |--------------------------------------------------------------------------
    | Safe intents — eligible for AI auto-reply
    |--------------------------------------------------------------------------
    |
    | Only these intents go down the auto-reply path. Everything else is
    | escalated even when confidence is high — a confident classifier does
    | not make a complaint suitable for an AI to handle.
    |
    */

    'safe_intents' => [
        'acknowledgment',
        'simple_question',
    ],

    /*
    |--------------------------------------------------------------------------
    | Holding messages
    |--------------------------------------------------------------------------
    |
    | Sent to the client on the escalation path so they get an immediate
    | acknowledgement while a human takes over. Keep them short, tone-
    | neutral, and free of any specific promise. Compliance still runs on
    | them in case a future edit slips a banned phrase through.
    |
    | If an intent isn't in this list, `default` is used.
    |
    */

    'holding_messages' => [
        'complaint'         => "I'm sorry for the inconvenience. I've escalated this to the right team and someone will be in touch shortly.",
        'unsubscribe'       => "Understood — I've passed your request to our team to action.",
        'sensitive_request' => "Thanks for your message — let me get the right person to handle this. They'll be in touch shortly.",
        'complex_question'  => "Thanks for the question — let me get the right person to answer. They'll come back to you shortly.",
        'unclear'           => "Thanks for your message — I've passed it to our team and someone will get back to you shortly.",
        'default'           => "Thanks for your message — I've passed it to our team and someone will get back to you shortly.",
    ],

    /*
    |--------------------------------------------------------------------------
    | System inbound auto-reply template slug
    |--------------------------------------------------------------------------
    |
    | The OutreachOrchestrator looks up the OutreachTemplate by this exact
    | `name` for the auto-reply path. Seeded once via SystemTemplatesSeeder.
    | Admins can edit the system_prompt from the Filament UI but should not
    | rename it (the lookup breaks).
    |
    */

    'auto_reply_template_name' => 'System — Inbound auto-reply',

];
