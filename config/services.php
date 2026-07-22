<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'multicard' => [
        // Master switch. When false the marketplace keeps its offline flow
        // (accept → in_progress directly, no invoice). Enable once credentials
        // are configured.
        'enabled' => env('MULTICARD_ENABLED', false),
        // dev: https://dev-mesh.multicard.uz  •  prod: https://mesh.multicard.uz
        'base_url' => env('MULTICARD_BASE_URL', 'https://dev-mesh.multicard.uz'),
        'application_id' => env('MULTICARD_APPLICATION_ID'),
        'secret' => env('MULTICARD_SECRET'),
        'store_id' => env('MULTICARD_STORE_ID'),
        // Where Multicard POSTs payment status changes. Must be the public API URL.
        'callback_url' => env('MULTICARD_CALLBACK_URL'),
        // Source IP Multicard sends webhooks from (comma-separated allowlist).
        'callback_ips' => env('MULTICARD_CALLBACK_IPS', '195.158.26.90'),
        // Platform commission on order payments, percent (deducted from payouts).
        'commission_percent' => env('MULTICARD_COMMISSION_PERCENT', 0),
        // Default advance slice of an agent payout, percent (final = remainder).
        // A manager can override the amount per payout at release time.
        'payout_advance_percent' => env('PAYOUT_ADVANCE_PERCENT', 40),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // HMAC verification of Mini App initData on login. Only disable in
        // local/dev environments without a real Telegram client.
        'verify_init_data' => env('TELEGRAM_VERIFY_INIT_DATA', true),
        'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
        // Private ops group where the bot reports marketplace events. Empty = disabled.
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        // Comma-separated event keys to report ("*" = all):
        // order_placed, offer_submitted, deal, payment_success, work_submitted, completed, dispute, review
        'admin_events' => env('TELEGRAM_ADMIN_EVENTS', '*'),
    ],

];
