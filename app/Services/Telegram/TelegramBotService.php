<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TelegramBotService
{
    private function endpoint(string $method): string
    {
        $base = rtrim((string) config('services.telegram.api_url'), '/');
        $token = (string) config('services.telegram.bot_token');

        return "{$base}/bot{$token}/{$method}";
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): Response
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return Http::asJson()->post($this->endpoint('sendMessage'), $payload);
    }

    /**
     * Send a document by public URL — Telegram fetches the file from the URL,
     * so it must be an absolute, publicly reachable address (see File::absoluteUrl()).
     *
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendDocument(int|string $chatId, string $documentUrl, ?string $caption = null, ?array $replyMarkup = null): Response
    {
        $payload = [
            'chat_id' => $chatId,
            'document' => $documentUrl,
        ];

        if ($caption !== null) {
            $payload['caption'] = $caption;
            $payload['parse_mode'] = 'HTML';
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return Http::asJson()->post($this->endpoint('sendDocument'), $payload);
    }

    /**
     * Reply keyboard with a single "share phone" button.
     *
     * @return array<string, mixed>
     */
    public function contactRequestKeyboard(string $buttonText): array
    {
        return [
            'keyboard' => [[
                ['text' => $buttonText, 'request_contact' => true],
            ]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * Inline keyboard with a single button that launches the mini app.
     *
     * @return array<string, mixed>
     */
    public function openAppInlineKeyboard(string $buttonText, string $miniAppUrl): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => $buttonText, 'web_app' => ['url' => $miniAppUrl]],
            ]],
        ];
    }

    /**
     * Inline buttons that post `callback_data` back to the webhook.
     *
     * @param  array<int, array{0: string, 1: string}>  $buttons  [text, callback_data] pairs, one row
     * @return array<string, mixed>
     */
    public function callbackInlineKeyboard(array $buttons): array
    {
        return [
            'inline_keyboard' => [array_map(
                fn (array $b): array => ['text' => $b[0], 'callback_data' => $b[1]],
                $buttons,
            )],
        ];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): Response
    {
        return Http::asJson()->post($this->endpoint('answerCallbackQuery'), array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], fn ($v) => $v !== null));
    }

    /** Replace a message's text and drop its inline keyboard. */
    public function editMessageText(int|string $chatId, int $messageId, string $text): Response
    {
        return Http::asJson()->post($this->endpoint('editMessageText'), [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Show the mini app launcher next to the message input (the "menu button").
     */
    public function setMenuButtonWebApp(int|string $chatId, string $buttonText, string $miniAppUrl): Response
    {
        return Http::asJson()->post($this->endpoint('setChatMenuButton'), [
            'chat_id' => $chatId,
            'menu_button' => [
                'type' => 'web_app',
                'text' => $buttonText,
                'web_app' => ['url' => $miniAppUrl],
            ],
        ]);
    }

    /**
     * Hide the mini app launcher — fall back to the default (commands) menu button.
     */
    public function hideMenuButton(int|string $chatId): Response
    {
        return Http::asJson()->post($this->endpoint('setChatMenuButton'), [
            'chat_id' => $chatId,
            'menu_button' => ['type' => 'default'],
        ]);
    }

    public function setWebhook(string $url, string $secretToken): Response
    {
        return Http::asJson()->post($this->endpoint('setWebhook'), [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message'],
        ]);
    }

    public function deleteWebhook(): Response
    {
        return Http::asJson()->post($this->endpoint('deleteWebhook'));
    }
}
