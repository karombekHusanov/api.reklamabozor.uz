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
        // Invoice lifetime, seconds. After this Multicard cancels the invoice,
        // so we must not reuse its checkout_url — we mint a fresh invoice.
        // Independent of how long the order may stay in awaiting_payment.
        'invoice_ttl' => (int) env('MULTICARD_INVOICE_TTL', 3600),
        // How long an order may stay awaiting_payment before auto-cancel (hours).
        // Contract-tunable; default 72h = 3 days.
        'awaiting_payment_timeout_hours' => (int) env('AWAITING_PAYMENT_TIMEOUT_HOURS', 72),
        // Attach an OFD (fiscal receipt) line to invoices. Off until real MXIK
        // codes are configured — placeholder OFD breaks checkout on some stands.
        'ofd_enabled' => (bool) env('MULTICARD_OFD_ENABLED', false),
        // Source IP Multicard sends webhooks from (comma-separated allowlist).
        'callback_ips' => env('MULTICARD_CALLBACK_IPS', '195.158.26.90'),
        // Callback signature algorithm (docs.multicard.uz callback-webhooks):
        //   sha1 — sha1(uuid + invoice_id + amount + secret)  ← docs
        //   md5  — md5(store_id + invoice_id + amount + secret) ← real stand often
        //   both — accept either (default — avoids prod/dev sign mismatch)
        'callback_sign' => env('MULTICARD_CALLBACK_SIGN', 'both'),
        // How long the client has to pay once the deal is active (contract
        // accepted). Overdue orders are not cancelled — the client, the agent
        // and the ops group are reminded (orders:remind-unpaid).
        'payment_due_days' => (int) env('PAYMENT_DUE_DAYS', 3),
        // Lifetime of a shareable invoice link (QR / SMS), seconds. Longer than
        // an in-app checkout because the client pays it later, elsewhere.
        'invoice_link_ttl' => (int) env('MULTICARD_INVOICE_LINK_TTL', 259200), // 3 days
        // Platform commission on order payments, percent (deducted from payouts).
        'commission_percent' => env('MULTICARD_COMMISSION_PERCENT', 7),
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
        'model' => env('ASSISTANT_MODEL', 'minimax/minimax-m3:free'),
        // Chat needs to feel instant — a slow provider is a broken feature.
        'timeout' => (int) env('ASSISTANT_TIMEOUT', 20),
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 700),
        'temperature' => (float) env('ASSISTANT_TEMPERATURE', 0.4),
        // Per-user daily message cap on top of the per-minute route throttle.
        'daily_limit' => (int) env('ASSISTANT_DAILY_LIMIT', 60),
        // Models the `assistant:eval` command compares (comma-separated ids).
        'eval_models' => env('ASSISTANT_EVAL_MODELS', ''),
    ],

];
