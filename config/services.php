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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
        // Private ops group where the bot reports marketplace events. Empty = disabled.
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        // Comma-separated event keys to report ("*" = all):
        // order_placed, offer_submitted, deal, work_submitted, completed, dispute, review
        'admin_events' => env('TELEGRAM_ADMIN_EVENTS', '*'),
    ],

];
