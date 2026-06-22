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
