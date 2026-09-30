# AdSpace Backend

Laravel 13 API for the PRB (Reklama Bozor) ad marketplace — Telegram Mini App + admin panel.
Prod: `https://api.reklamamarket.uz`. Full project guide: root `CLAUDE.md` (setup, deploy, gotchas)
and `PROJECT_LOGIC.md` (domain, flows, full API reference).

## Stack

- PHP 8.3 (composer platform pinned to 8.3.6)
- Laravel 13 + Sanctum (Bearer tokens)
- PostgreSQL 16 (SQLite `:memory:` in tests), Redis 7 (cache/queue)
- Centrifugo v6 (WebSocket: presence, live stats, client ↔ agent chat)
- Integrations: Telegram Bot API, ATMOS (card payments), Kapitalbank OpenAPI (bank transfers),
  OpenRouter (AI assistant), dompdf (contracts, invoices, acts)

## Key subsystems

| Area | Where |
|------|-------|
| Orders, offers (otklik), contracts, amendments | `app/Services/Order`, `app/Services/Contract`, `resources/legal` |
| Order payments (bank transfer / cash, Kapitalbank auto-match), payouts | `app/Services/Payment/PaymentService.php`, `BankReconciliationService`, `PayoutService` |
| Propusk / wallet / card payments (ATMOS), refunds | `app/Services/Pass/*`, `app/Services/Payment/Atmos`, `app/Services/Payment/Gateway` |
| Realtime (Centrifugo) — presence, stats, chat push + RPC proxy | `app/Services/Realtime/*`, `deploy/centrifugo/README.md` |
| Chats (deal / direct / global), admin chat history | `app/Services/Chat/*`, `app/Services/Admin/ChatAdminService.php` |
| Telegram: user notifications, ops group, payments group, webhook | `app/Services/Telegram/*` |
| AI assistant (order drafts, category detection) | `app/Services/Assistant/*`, `php artisan assistant:eval` |

## Getting Started

```bash
cd adspace_backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

The API will be available at `http://localhost:8000`.

## Development

Run the full dev stack (server, queue, logs, Vite):

```bash
composer dev
```

Run tests:

```bash
composer test
```

Format code:

```bash
./vendor/bin/pint
```

## Project Structure

```
app/
├── Actions/          # Single-purpose business logic (e.g. AuthenticateTelegramUserAction)
├── Services/Telegram/ # Platform-specific Telegram auth verifiers
├── Http/
│   ├── Controllers/
│   │   └── Api/V1/   # Versioned API controllers
│   ├── Requests/     # Form request validation
│   └── Resources/    # JSON API transformers
├── Models/           # Eloquent models
├── Providers/        # Service providers
└── Support/          # Shared helpers (ApiResponse, etc.)

routes/
├── api.php           # API route entry (prefix: /api)
└── api/v1.php        # Version 1 endpoints
```

## API Endpoints

| Method | Endpoint                 | Auth | Description                         |
|--------|--------------------------|------|-------------------------------------|
| GET    | `/api/v1/health`         | No   | Service health check                |
| POST   | `/api/v1/auth/telegram`  | No   | Telegram auth (Mini App / Mobile)   |
| POST   | `/api/v1/auth/logout`    | Yes  | Revoke current token                |
| GET    | `/api/v1/auth/me`        | Yes  | Get authenticated user              |
| POST   | `/api/v1/telegram/webhook` | Secret | Telegram bot webhook (phone capture) |

### Phone capture (Telegram bot)

The phone number is **not** part of `initData`, so it is collected via the bot:

1. On `/start` the bot sends a reply keyboard with a `request_contact` button (and the mini
   app can trigger the same prompt via `WebApp.requestContact()`).
2. The user taps "Share phone"; Telegram POSTs the contact to `/api/v1/telegram/webhook`.
3. The webhook (guarded by the `X-Telegram-Bot-Api-Secret-Token` header) verifies the contact
   belongs to the sender, normalizes the number to `+<digits>`, and saves it to the user.

Register the webhook (after exposing the backend via a public URL / tunnel):

```bash
php artisan telegram:set-webhook https://<public-host>/api/v1/telegram/webhook
```

The mini app gates authenticated users behind a "share phone" screen until `user.phone` is set.

### Authentication

Both Telegram Mini App and Flutter mobile app authenticate through `POST /api/v1/auth/telegram`.

**Mini App (MVP):**

```json
{
  "platform": "mini_app",
  "init_data": "<Telegram.WebApp.initData>"
}
```

**Mobile (Flutter, later):**

```json
{
  "platform": "mobile",
  "auth_data": "<verified Telegram login payload>"
}
```

On first auth the user is created as B2C (`is_b2c: true`). Returning users receive a new Sanctum token.

Set `TELEGRAM_BOT_TOKEN` in `.env` before using Mini App auth.

Protected routes require a Bearer token:

```
Authorization: Bearer {token}
```

### Response Format

Success:

```json
{
  "success": true,
  "message": "Success",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

## Environment

Key variables in `.env`:

| Variable       | Description                    |
|----------------|--------------------------------|
| `APP_NAME`     | Application name (AdSpace)     |
| `APP_URL`      | Base URL                       |
| `TELEGRAM_BOT_TOKEN` | Telegram bot token for auth verification |
| `TELEGRAM_WEBHOOK_SECRET` | Secret token validating incoming bot webhook calls |
| `DB_CONNECTION`| Database driver (pgsql; sqlite/mysql also supported) |
| `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | Postgres connection |
| `QUEUE_CONNECTION` | Queue driver               |
| `TELEGRAM_ADMIN_CHAT_ID` / `TELEGRAM_PAYMENTS_CHAT_ID` | Ops group / payments feed group (empty = off) |
| `REALTIME_ENABLED`, `CENTRIFUGO_WS_URL`, `CENTRIFUGO_API_URL`, `CENTRIFUGO_API_KEY`, `CENTRIFUGO_HMAC_SECRET`, `CENTRIFUGO_PROXY_SECRET` | Centrifugo (see `deploy/centrifugo/README.md`) |
| `ASSISTANT_ENABLED`, `ASSISTANT_API_KEY`, `ASSISTANT_MODEL` | AI assistant (OpenRouter; default `google/gemini-3.5-flash-lite`) |
| `PASSES_ENFORCE`, `PASSES_WALLET_ENABLED`, `PASSES_GATEWAY` | Propusk / per-response fees; `atmos` or `fake` |
| `ATMOS_CONSUMER_KEY`, `ATMOS_CONSUMER_SECRET`, `ATMOS_STORE_ID`, `ATMOS_HTTP_PROXY` | ATMOS card payments; proxy only for local dev (ATMOS doesn't answer every network) |
| `KAPITALBANK_ENABLED`, `KAPITALBANK_*` | Bank statement reconciliation / payout queue |

## License

MIT
