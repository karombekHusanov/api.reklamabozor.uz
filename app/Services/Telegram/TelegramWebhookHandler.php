<?php

namespace App\Services\Telegram;

use App\Enums\Role;
use App\Models\User;
use App\Services\Agent\AgentAccountLinker;
use Illuminate\Support\Facades\Log;

class TelegramWebhookHandler
{
    private const SHARE_PHONE_BUTTON = '📱 Telefon raqamni ulashish';

    private const OPEN_APP_BUTTON = '🚀 PRB’ni ochish';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AgentAccountLinker $agentLinker,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $this->logGroupMembership($update);

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
     * The bot being added to / removed from a group, or a group upgrading to a
     * supergroup (new id), is logged with the chat id — that's how ops finds the
     * id for TELEGRAM_ADMIN_CHAT_ID / TELEGRAM_PAYMENTS_CHAT_ID.
     *
     * @param  array<string, mixed>  $update
     */
    private function logGroupMembership(array $update): void
    {
        $member = $update['my_chat_member'] ?? null;
        if (is_array($member) && in_array($member['chat']['type'] ?? null, ['group', 'supergroup'], true)) {
            Log::info('telegram.bot_group_membership', [
                'chat_id' => $member['chat']['id'] ?? null,
                'title' => $member['chat']['title'] ?? null,
                'status' => $member['new_chat_member']['status'] ?? null,
                'by' => $member['from']['id'] ?? null,
            ]);
        }

        $migrateTo = $update['message']['migrate_to_chat_id'] ?? null;
        if ($migrateTo !== null) {
            Log::info('telegram.group_migrated', [
                'from_chat_id' => $update['message']['chat']['id'] ?? null,
                'to_chat_id' => $migrateTo,
                'title' => $update['message']['chat']['title'] ?? null,
            ]);
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
            $this->presentApp($chatId, 'Qaytganingizdan xursandmiz! Davom etish uchun PRB’ni oching.');

            return;
        }

        // New / phone-less user → hide the mini app launcher and require the phone first.
        $this->bot->hideMenuButton($chatId);

        $this->bot->sendMessage(
            $chatId,
            "Assalomu alaykum! PRB’ga xush kelibsiz.\n\nDavom etish uchun telefon raqamingizni ulashing.",
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

        // A manager may have pre-created this agent by phone — adopt that
        // account so the person lands on their approved agency profile.
        $this->agentLinker->linkByPhone($user);

        if ($chatId === null) {
            return;
        }

        // Clear the phone-request keyboard, then reveal the mini app.
        $this->bot->sendMessage(
            $chatId,
            'Rahmat! Telefon raqamingiz saqlandi.',
            $this->bot->removeKeyboard(),
        );

        $this->presentApp($chatId, 'Endi PRB’dan to‘liq foydalanishingiz mumkin.');
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

        $this->bot->setMenuButtonWebApp($chatId, 'PRB', $miniAppUrl);

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
