<?php

namespace App\Services\Telegram;

use App\Enums\Role;
use App\Models\User;

class TelegramWebhookHandler
{
    private const SHARE_PHONE_BUTTON = '📱 Telefon raqamni ulashish';

    private const OPEN_APP_BUTTON = '🚀 Reklama Bozor’ni ochish';

    public function __construct(
        private readonly TelegramBotService $bot,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        if (isset($message['contact'])) {
            $this->handleSharedContact($message);

            return;
        }

        $text = $message['text'] ?? null;

        if (is_string($text) && str_starts_with($text, '/start')) {
            $this->handleStart($message);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleStart(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = isset($message['from']['id']) ? (int) $message['from']['id'] : null;

        if ($chatId === null) {
            return;
        }

        $user = $fromId !== null
            ? User::query()->where('telegram_id', $fromId)->first()
            : null;

        // Returning user who already shared a phone → straight to the app.
        if ($user?->phone) {
            $this->presentApp($chatId, 'Qaytganingizdan xursandmiz! Davom etish uchun Reklama Bozor’ni oching.');

            return;
        }

        // New / phone-less user → hide the mini app launcher and require the phone first.
        $this->bot->hideMenuButton($chatId);

        $this->bot->sendMessage(
            $chatId,
            "Assalomu alaykum! Reklama Bozor’ga xush kelibsiz.\n\nDavom etish uchun telefon raqamingizni ulashing.",
            $this->bot->contactRequestKeyboard(self::SHARE_PHONE_BUTTON),
        );
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleSharedContact(array $message): void
    {
        $contact = $message['contact'];
        $from = $message['from'] ?? [];
        $chatId = $message['chat']['id'] ?? null;

        $fromId = isset($from['id']) ? (int) $from['id'] : null;
        $contactUserId = isset($contact['user_id']) ? (int) $contact['user_id'] : null;

        // Only accept a contact the user shared about *themselves*.
        if ($fromId === null || $contactUserId === null || $fromId !== $contactUserId) {
            return;
        }

        $phone = $this->normalizePhone((string) ($contact['phone_number'] ?? ''));

        if ($phone === null) {
            return;
        }

        $user = User::firstOrNew(['telegram_id' => $fromId]);
        $isNew = ! $user->exists;

        $user->phone = $phone;

        if ($isNew) {
            $user->first_name = $contact['first_name'] ?? ($from['first_name'] ?? 'Telegram User');
            $user->last_name = $contact['last_name'] ?? ($from['last_name'] ?? null);
            $user->username = $from['username'] ?? null;
            $user->role = Role::Client;
            $user->is_active = true;
        }

        $user->save();

        if ($chatId === null) {
            return;
        }

        // Clear the phone-request keyboard, then reveal the mini app.
        $this->bot->sendMessage(
            $chatId,
            'Rahmat! Telefon raqamingiz saqlandi.',
            $this->bot->removeKeyboard(),
        );

        $this->presentApp($chatId, 'Endi Reklama Bozor’dan to‘liq foydalanishingiz mumkin.');
    }

    /**
     * Reveal the mini app: set the persistent menu-button launcher and send an inline
     * "Open app" button. Falls back to a plain message if the mini app URL is unset.
     */
    private function presentApp(int|string $chatId, string $text): void
    {
        $miniAppUrl = (string) config('services.telegram.mini_app_url');

        if ($miniAppUrl === '') {
            $this->bot->sendMessage($chatId, $text);

            return;
        }

        $this->bot->setMenuButtonWebApp($chatId, 'Reklama Bozor', $miniAppUrl);

        $this->bot->sendMessage(
            $chatId,
            $text,
            $this->bot->openAppInlineKeyboard(self::OPEN_APP_BUTTON, $miniAppUrl),
        );
    }

    private function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        return '+'.$digits;
    }
}
