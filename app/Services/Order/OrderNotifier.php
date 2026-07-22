<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderDeadline;
use App\Models\DirectChat;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\AdminNotifier;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Str;

/**
 * Telegram bot notifications around the order lifecycle: approved providers
 * hear about freshly placed orders in their categories, the client hears
 * about each incoming offer, both sides hear about the selection, and every
 * event is mirrored to the admin ops group.
 */
class OrderNotifier
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly AdminNotifier $admin,
    ) {}

    public function notifyNewOrder(Order $order): int
    {
        $order->loadMissing('category');
        Order::hydrateAttachmentFiles($order);

        $recipients = User::query()
            ->whereNotNull('telegram_id')
            ->whereHas('agentProfile', function ($query) use ($order): void {
                $query->where('status', AgentProfileStatus::Approved);

                // Broadcast order → every approved provider serving the category.
                // Directed order → only the chosen agency (filtered by id below),
                // so the category constraint is skipped here.
                if ($order->target_agent_id === null) {
                    $query->whereHas('categories', fn ($c) => $c->where('categories.id', $order->category_id));
                }
            })
            ->when($order->target_agent_id !== null, fn ($q) => $q->whereKey($order->target_agent_id))
            ->get();

        $text = $this->buildMessage($order);
        $deepLink = $this->orderDeepLink($order);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Buyurtmani ko'rish", $deepLink)
            : null;

        // Telegram fetches the document over HTTP, so it needs the absolute URL.
        $firstFile = $order->relationLoaded('attachmentFiles') ? $order->attachmentFiles->first() : null;
        $documentUrl = $firstFile?->absoluteUrl();

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                if ($documentUrl !== null) {
                    // Deliver the first attached file as a document with the order
                    // summary as caption; agents open the mini app for the full set.
                    $this->bot->sendDocument((int) $recipient->telegram_id, $documentUrl, $text, $markup);
                } else {
                    $this->bot->sendMessage((int) $recipient->telegram_id, $text, $markup);
                }
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->admin->orderPlaced($order, $sent);

        return $sent;
    }

    /**
     * Tells the client that an agent sent an offer for their order.
     * Returns false when the client has no Telegram id to reach.
     */
    public function notifyNewOffer(Offer $offer): bool
    {
        $offer->loadMissing('order.client', 'agent', 'agentProfile');

        $this->admin->offerSubmitted($offer);

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

    /**
     * Fan-out after the client picks a winning offer: congratulate the winner,
     * close the loop with the losing agents, and report the deal to the ops group.
     */
    public function notifyOfferAccepted(Offer $offer): void
    {
        $offer->loadMissing('order.client', 'agent', 'agentProfile');

        $order = $offer->order;

        if ($offer->agent?->telegram_id !== null) {
            $deepLink = $this->orderDeepLink($order);
            $markup = $deepLink !== null
                ? $this->bot->openAppInlineKeyboard("📂 Buyurtmani ko'rish", $deepLink)
                : null;

            try {
                $this->bot->sendMessage((int) $offer->agent->telegram_id, implode("\n", [
                    '🎉 <b>Taklifingiz qabul qilindi!</b>',
                    '',
                    "🔖 Buyurtma: <b>#{$order->id}</b> — ".e($order->title),
                    '💰 Kelishilgan narx: <b>'.number_format((float) $offer->price, 0, '.', ' ')." so'm</b>",
                    '',
                    'Ish boshlandi — buyurtma tafsilotlarini quyidagi tugma orqali ko\'ring.',
                ]), $markup);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Everyone else was auto-rejected in the same selection — tell them the
        // order is closed so they stop waiting for an answer.
        $losers = $order->offers()
            ->where('status', OfferStatus::Rejected)
            ->with('agent')
            ->get();

        foreach ($losers as $lost) {
            if ($lost->agent?->telegram_id === null) {
                continue;
            }

            try {
                $this->bot->sendMessage((int) $lost->agent->telegram_id, implode("\n", [
                    "Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha mijoz boshqa taklifni tanladi.',
                    'Qatnashganingiz uchun rahmat — keyingi buyurtmalarda omad! 🍀',
                ]));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->admin->dealMade($offer);
    }

    /**
     * Agent delivered the work — ask the client to review and confirm.
     */
    public function notifyWorkSubmitted(Order $order): void
    {
        $order->loadMissing('client');

        $this->sendToUser($order->client, implode("\n", [
            '🏁 <b>Ish tayyor!</b>',
            '',
            "Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha agentlik ishni topshirdi.',
            "Iltimos, natijani ko'rib chiqing: qabul qilsangiz buyurtma yakunlanadi, muammo bo'lsa shu yerdan xabar bering.",
            '',
            '⏳ 3 kun ichida javob bermasangiz, buyurtma avtomatik qabul qilinadi.',
        ]), "📂 Ko'rish va tasdiqlash", '/orders/'.$order->id);

        $this->admin->workSubmitted($order);
    }

    /**
     * Day-2 nudge: one day left before the order auto-completes.
     */
    public function notifyCompletionReminder(Order $order): void
    {
        $order->loadMissing('client');

        $this->sendToUser($order->client, implode("\n", [
            "⏳ Eslatma: buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha topshirilgan ish tasdiqlashingizni kutmoqda.',
            'Ertaga javob bo\'lmasa, buyurtma avtomatik qabul qilinadi.',
        ]), '📂 Tasdiqlash', '/orders/'.$order->id);
    }

    /**
     * Order finished — client confirmed, or the 3-day window ran out ($auto).
     */
    public function notifyOrderCompleted(Order $order, bool $auto): void
    {
        $order->loadMissing('client', 'acceptedOffer.agent');

        $agentText = $auto
            ? 'Klient 3 kun ichida javob bermagani uchun ish avtomatik qabul qilindi.'
            : 'Klient ishni qabul qildi. Hamkorlik uchun rahmat!';

        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", [
            "✅ Buyurtma <b>#{$order->id}</b> (".e($order->title).') yakunlandi.',
            $agentText,
        ]));

        if ($auto) {
            $this->sendToUser($order->client, implode("\n", [
                "✅ Buyurtma <b>#{$order->id}</b> (".e($order->title).') avtomatik yakunlandi (3 kun ichida javob bo\'lmadi).',
                "Muammo bo'lsa, biz bilan bog'laning.",
            ]));
        }

        $this->admin->orderCompleted($order, $auto);
    }

    /**
     * Client rejected the delivered work — the order went back to in_progress
     * and a human needs to step in.
     */
    public function notifyDisputeOpened(Order $order): void
    {
        $order->loadMissing('client', 'acceptedOffer.agent');

        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", [
            "⚠️ Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha klient ishni qabul qilmadi.',
            'Buyurtma yana "jarayonda" holatiga qaytdi. Administratsiya tez orada bog\'lanadi.',
        ]), "📂 Buyurtmani ko'rish", $this->agentOrderPath($order));

        $this->admin->disputeOpened($order);
    }

    /**
     * Nudge the other side of an order conversation about fresh messages.
     */
    public function notifyNewChatMessage(Order $order, User $recipient): void
    {
        $this->sendToUser($recipient, implode("\n", [
            "💬 Buyurtma <b>#{$order->id}</b> (".e($order->title).") bo'yicha yangi xabar keldi.",
        ]), '✉️ Chatni ochish', '/chat/'.$order->id);
    }

    public function notifyNewDirectChatMessage(DirectChat $chat, User $recipient): void
    {
        $sender = $chat->otherParticipant($recipient);
        $agentProfile = $sender->id === $chat->agent_id ? $chat->agentProfile : null;
        $label = $agentProfile?->company_name
            ?? trim($sender->first_name.' '.($sender->last_name ?? ''));

        $this->sendToUser($recipient, implode("\n", [
            '💬 <b>'.e($label).'</b> bilan yangi xabar keldi.',
        ]), '✉️ Chatni ochish', '/chat/direct/'.$chat->id);
    }

    /**
     * Guarded single-user send: skips users without Telegram, never throws.
     * The button is attached only when a mini-app path is given and configured.
     */
    private function sendToUser(?User $user, string $text, ?string $buttonText = null, ?string $path = null): void
    {
        if ($user?->telegram_id === null) {
            return;
        }

        $markup = null;

        if ($buttonText !== null && $path !== null) {
            $deepLink = $this->miniAppLink($path);
            $markup = $deepLink !== null
                ? $this->bot->openAppInlineKeyboard($buttonText, $deepLink)
                : null;
        }

        try {
            $this->bot->sendMessage((int) $user->telegram_id, $text, $markup);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function agentOrderPath(Order $order): string
    {
        return '/orders/'.$order->id;
    }

    private function buildOfferMessage(Offer $offer): string
    {
        $order = $offer->order;
        $agent = $offer->agent;
        $company = e($offer->agentProfile?->company_name
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
        return $this->miniAppLink($this->agentOrderPath($order));
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

        $fileCount = $order->relationLoaded('attachmentFiles') ? $order->attachmentFiles->count() : count($order->allAttachmentFileIds());
        if ($fileCount > 0) {
            $lines[] = $fileCount === 1
                ? '📎 1 ta fayl ilova qilindi.'
                : "📎 {$fileCount} ta fayl ilova qilindi.";
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
