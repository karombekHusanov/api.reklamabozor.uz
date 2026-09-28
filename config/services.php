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
        // HMAC verification of Mini App initData on login. Only disable in
        // local/dev environments without a real Telegram client.
        'verify_init_data' => env('TELEGRAM_VERIFY_INIT_DATA', true),
        'init_data_max_age' => env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL'),
        // Private ops group where the bot reports marketplace events. Empty = disabled.
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
        // Separate group that gets every payment / refund (PaymentFeedNotifier). Empty = off.
        'payments_chat_id' => env('TELEGRAM_PAYMENTS_CHAT_ID'),
        // New-order broadcast pace: messages released per second (Telegram allows ~30/s).
        'broadcast_per_second' => (int) env('TELEGRAM_BROADCAST_PER_SECOND', 20),
        // Comma-separated event keys to report ("*" = all):
        // order_placed, offer_submitted, deal, payment_success, payment_refunded,
        // payment_timeout, payment_awaiting_cancelled, work_submitted, completed, dispute, review, order_cancelled
        'admin_events' => env('TELEGRAM_ADMIN_EVENTS', '*'),
    ],

    /*
     * AI assistant. Any OpenAI-compatible chat-completions endpoint works
     * (OpenRouter, Gemini's compat layer, DeepSeek, Zhipu/GLM, Groq, ...), so
     * switching provider is a .env change, not a code change. The key never
     * leaves the server: the mini app talks only to our own endpoint.
     */
    'assistant' => [
        'enabled' => env('ASSISTANT_ENABLED', false),
        'base_url' => env('ASSISTANT_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('ASSISTANT_API_KEY'),
        'model' => env('ASSISTANT_MODEL', 'google/gemini-3.5-flash-lite'),
        // Chat needs to feel instant — a slow provider is a broken feature.
        'timeout' => (int) env('ASSISTANT_TIMEOUT', 20),
        // Order-category auto-detection runs synchronously inside POST /orders,
        // so it gets its own, much shorter budget (seconds).
        'classify_timeout' => (int) env('ASSISTANT_CLASSIFY_TIMEOUT', 6),
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 700),
        'temperature' => (float) env('ASSISTANT_TEMPERATURE', 0.4),
        // Per-user daily message cap on top of the per-minute route throttle.
        'daily_limit' => (int) env('ASSISTANT_DAILY_LIMIT', 60),
        // Models the `assistant:eval` command compares (comma-separated ids).
        'eval_models' => env('ASSISTANT_EVAL_MODELS', ''),
    ],

];
