<?php

/*
|--------------------------------------------------------------------------
| Realtime (Centrifugo)
|--------------------------------------------------------------------------
|
| Presence ("who is online") runs on a Centrifugo WebSocket server: each mini
| app keeps one connection, Centrifugo holds presence in memory, and a
| scheduled job publishes the live stats to every client. No per-client
| polling, no DB writes. Off → the old Sanctum `last_used_at` heuristic.
|
*/

return [

    'enabled' => (bool) env('REALTIME_ENABLED', false),

    // Public WebSocket endpoint the mini app connects to.
    'ws_url' => env('CENTRIFUGO_WS_URL', 'ws://localhost:8001/connection/websocket'),

    // Server-side HTTP API (publish / presence_stats) — keep it internal.
    'api_url' => env('CENTRIFUGO_API_URL', 'http://127.0.0.1:8001/api'),
    'api_key' => env('CENTRIFUGO_API_KEY'),

    // Must equal Centrifugo `client.token.hmac_secret_key`.
    'hmac_secret' => env('CENTRIFUGO_HMAC_SECRET'),

    // Connection token lifetime; the client silently refreshes on expiry.
    'token_ttl_minutes' => (int) env('CENTRIFUGO_TOKEN_TTL_MINUTES', 60),

    'timeout_seconds' => 3,

    // Server-side subscriptions baked into the connection token. Namespace
    // `live` must have `presence: true` in the Centrifugo config.
    'channels' => [
        // Everyone: presence for `users_online` + live stats publications.
        'pulse' => 'live:pulse',
        // Approved agencies only: presence for `agents_online`.
        'agents' => 'live:agents',
    ],

    // How long a published snapshot is trusted by GET /stats/live.
    'snapshot_ttl_seconds' => 60,

];
