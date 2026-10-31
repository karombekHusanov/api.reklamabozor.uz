<?php

namespace App\Services\Telegram;

use App\Enums\Role;
use App\Models\User;
use App\Services\Agent\AgentAccountLinker;
use App\Services\Auth\TelegramNativeLoginService;
use Illuminate\Support\Facades\Log;

class TelegramWebhookHandler
{
    private const SHARE_PHONE_BUTTON = '📱 Telefon raqamni ulashish';

    private const OPEN_APP_BUTTON = '🚀 PRB’ni ochish';

    private const LOGIN_PREFIX = 'login_';

    private const LOGIN_OK = 'login_ok:';

    private const LOGIN_NO = 'login_no:';

    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AgentAccountLinker $agentLinker,
        private readonly TelegramNativeLoginService $nativeLogin,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $this->logGroupMembership($update);

        if (is_array($update['callback_query'] ?? null)) {
            $this->handleCallback($update['callback_query']);

            return;
        }

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

        // Deep link from the mobile app: /start login_<code>.
        $payload = trim(substr((string) ($message['text'] ?? ''), strlen('/start')));
        if (str_starts_with($payload, self::LOGIN_PREFIX) && $fromId !== null) {
            $this->handleLoginStart($chatId, $fromId, $user, substr($payload, strlen(self::LOGIN_PREFIX)));

            return;
        }

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

        // They came from the mobile app's login link: sharing the phone is the
        // confirmation — sign the app in instead of offering the mini app.
        $loginCode = $this->nativeLogin->pullAwaitingContact($fromId);
        if ($loginCode !== null) {
            $user = User::query()->where('telegram_id', $fromId)->first() ?? $user;
            $confirmed = $this->nativeLogin->confirm($loginCode, $user);
            $this->bot->sendMessage(
                $chatId,
                $confirmed ? self::loginDoneText() : self::loginExpiredText(),
                $this->bot->removeKeyboard(),
            );

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
     * Mobile app login link opened. Known user with a phone → ask to confirm
     * (an inline button, so a link someone else sent can't sign their phone in
     * silently). No phone yet → collect it first; sharing it confirms.
     */
    private function handleLoginStart(int|string $chatId, int $fromId, ?User $user, string $code): void
    {
        if ($code === '' || ! $this->nativeLogin->isPending($code)) {
            $this->bot->sendMessage($chatId, self::loginExpiredText());

            return;
        }

        if ($user !== null && ! $user->is_active) {
            $this->bot->sendMessage($chatId, 'Hisobingiz faol emas. Yordam uchun qo‘llab-quvvatlash xizmatiga yozing.');

            return;
        }

        if ($user?->phone) {
            $this->bot->sendMessage(
                $chatId,
                "<b>PRB mobil ilovasiga kirish</b>\n\nHozir telefoningizda PRB ilovasiga kirmoqchimisiz? Agar bu siz bo‘lmasangiz, «Bekor qilish»ni bosing.",
                $this->bot->callbackInlineKeyboard([
                    ['✅ Ha, kirish', self::LOGIN_OK.$code],
                    ['Bekor qilish', self::LOGIN_NO.$code],
                ]),
            );

            return;
        }

        $this->nativeLogin->awaitContact($fromId, $code);
        $this->bot->sendMessage(
            $chatId,
            'PRB ilovasiga kirish uchun telefon raqamingizni ulashing.',
            $this->bot->contactRequestKeyboard(self::SHARE_PHONE_BUTTON),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function handleCallback(array $query): void
    {
        $data = (string) ($query['data'] ?? '');
        $id = (string) ($query['id'] ?? '');
        $fromId = isset($query['from']['id']) ? (int) $query['from']['id'] : null;
        $chatId = $query['message']['chat']['id'] ?? null;
        $messageId = isset($query['message']['message_id']) ? (int) $query['message']['message_id'] : null;

        $ok = str_starts_with($data, self::LOGIN_OK);
        if (! $ok && ! str_starts_with($data, self::LOGIN_NO)) {
            return;
        }
        $code = substr($data, strlen($ok ? self::LOGIN_OK : self::LOGIN_NO));

        if ($ok) {
            $user = $fromId !== null ? User::query()->where('telegram_id', $fromId)->first() : null;
            $done = $user !== null && $user->phone && $this->nativeLogin->confirm($code, $user);
            $text = $done ? self::loginDoneText() : self::loginExpiredText();
        } else {
            $this->nativeLogin->cancel($code);
            $text = 'Kirish bekor qilindi.';
        }

        if ($id !== '') {
            $this->bot->answerCallbackQuery($id);
        }
        if ($chatId !== null && $messageId !== null) {
            $this->bot->editMessageText($chatId, $messageId, $text);
        }
    }

    private static function loginDoneText(): string
    {
        return '✅ Kirish tasdiqlandi. PRB ilovasiga qayting.';
    }

    private static function loginExpiredText(): string
    {
        return 'Kirish havolasi eskirgan. Ilovada «Telegram orqali kirish»ni qaytadan bosing.';
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
