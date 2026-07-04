<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderDeadline;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Str;

/**
 * Telegram bot notifications around the order lifecycle: approved providers
 * hear about freshly placed orders in their categories, and the client hears
 * about each incoming offer.
 */
class OrderNotifier
{
    public function __construct(
        private readonly TelegramBotService $bot,
    ) {}

    public function notifyNewOrder(Order $order): int
    {
        $order->loadMissing('category', 'tzFile');

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

        // Telegram fetches the document over HTTP, so it needs the absolute URL.
        $tzUrl = $order->tzFile?->absoluteUrl();

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                if ($tzUrl !== null) {
                    // Deliver the client's brief (TZ) as a document with the order
                    // summary as caption, so agents review it without leaving chat.
                    $this->bot->sendDocument((int) $recipient->telegram_id, $tzUrl, $text, $markup);
                } else {
                    $this->bot->sendMessage((int) $recipient->telegram_id, $text, $markup);
                }
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /**
     * Tells the client that an agent sent an offer for their order.
     * Returns false when the client has no Telegram id to reach.
     */
    public function notifyNewOffer(Offer $offer): bool
    {
        $offer->loadMissing('order.client', 'agent.agentProfile');

        $client = $offer->order->client;

        if ($client?->telegram_id === null) {
            return false;
        }

        $deepLink = $this->miniAppLink('/orders/'.$offer->order_id);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Taklifni ko'rish", $deepLink)
            : null;

        $this->bot->sendMessage((int) $client->telegram_id, $this->buildOfferMessage($offer), $markup);

        return true;
    }

    private function buildOfferMessage(Offer $offer): string
    {
        $order = $offer->order;
        $agent = $offer->agent;
        $company = e($agent?->agentProfile?->company_name
            ?? trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')));
        $price = number_format((float) $offer->price, 0, '.', ' ');
        $comment = e(Str::limit($offer->comment, 300));

        return implode("\n", [
            '💼 <b>Yangi taklif!</b>',
            '',
            "🔖 Buyurtma: <b>#{$order->id}</b> — ".e($order->title),
            "🏢 Agentlik: <b>{$company}</b>",
            "💰 Narx: <b>{$price} so'm</b>",
            "💬 Izoh: {$comment}",
            '',
            "Taklifni ko'rish va tanlash uchun quyidagi tugmani bosing.",
        ]);
    }

    /**
     * Mini-app deep link that lands an agent directly on the order so they can
     * review it and send an offer. Null when no mini app URL is configured.
     */
    private function orderDeepLink(Order $order): ?string
    {
        // The agent workspace lives on the profile page's "offers" tab.
        return $this->miniAppLink('/profile?tab=offers&order='.$order->id);
    }

    private function miniAppLink(string $pathWithQuery): ?string
    {
        $miniAppUrl = (string) config('services.telegram.mini_app_url');

        if ($miniAppUrl === '') {
            return null;
        }

        return rtrim($miniAppUrl, '/').$pathWithQuery;
    }

    private function buildMessage(Order $order): string
    {
        $category = e($order->category?->name_uz ?? '');
        $description = e(Str::limit($order->description, 300));

        $lines = [
            "🆕 <b>Yangi buyurtma — #{$order->id}</b>",
            '',
            "📁 Yo'nalish: <b>{$category}</b>",
        ];

        $deadline = $this->deadlineLabel($order);
        if ($deadline !== null) {
            $lines[] = "⏱ Muddat: <b>{$deadline}</b>";
        }

        $lines[] = "📝 Izoh: {$description}";

        if ($order->tz_file_id !== null) {
            $lines[] = '📎 Texnik topshiriq (TZ) ilova qilindi.';
        }

        $lines[] = '';
        $lines[] = "Buyurtma siz uchun qiziq bo'lsa, quyidagi tugma orqali uni ochib, taklif (shartnoma) yuboring.";

        return implode("\n", $lines);
    }

    /**
     * Human-readable Uzbek label for the order's urgency preset, or null when unset.
     */
    private function deadlineLabel(Order $order): ?string
    {
        return match ($order->deadline) {
            OrderDeadline::TodayTomorrow => 'Bugun-erta',
            OrderDeadline::ThisWeek => 'Shu hafta',
            default => null,
        };
    }
}
