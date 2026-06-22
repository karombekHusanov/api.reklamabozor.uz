<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Str;

/**
 * Notifies the approved providers (agents/designers) that serve an order's
 * category about a freshly placed order, via the Telegram bot.
 */
class OrderNotifier
{
    public function __construct(
        private readonly TelegramBotService $bot,
    ) {}

    public function notifyNewOrder(Order $order): int
    {
        $order->loadMissing('category');

        $recipients = User::query()
            ->whereNotNull('telegram_id')
            ->whereHas('agentProfile', function ($query) use ($order): void {
                $query
                    ->where('status', AgentProfileStatus::Approved)
                    ->whereHas('categories', fn ($c) => $c->where('categories.id', $order->category_id));
            })
            ->get();

        $text = $this->buildMessage($order);
        $deepLink = $this->orderDeepLink($order);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Buyurtmani ko'rish", $deepLink)
            : null;

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                $this->bot->sendMessage((int) $recipient->telegram_id, $text, $markup);
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Mini-app deep link that lands an agent directly on the order so they can
     * review it and send an offer. Null when no mini app URL is configured.
     */
    private function orderDeepLink(Order $order): ?string
    {
        $miniAppUrl = (string) config('services.telegram.mini_app_url');

        if ($miniAppUrl === '') {
            return null;
        }

        return rtrim($miniAppUrl, '/').'/agent?order='.$order->id;
    }

    private function buildMessage(Order $order): string
    {
        $category = e($order->category?->name_uz ?? '');
        $description = e(Str::limit($order->description, 300));

        return "🆕 <b>Yangi buyurtma!</b>\n\n"
            ."📁 Yo'nalish: <b>{$category}</b>\n"
            ."📝 {$description}\n\n"
            ."Agar buyurtma siz uchun qiziq bo'lsa, mini app sahifangiz orqali taklif (shartnoma) yuborishingiz mumkin.";
    }
}
