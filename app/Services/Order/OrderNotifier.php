<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderDeadline;
use App\Enums\PayoutTranche;
use App\Jobs\SendNewOrderNotification;
use App\Models\AgentPass;
use App\Models\DirectChat;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Chat\DirectChatService;
use App\Services\Telegram\AdminNotifier;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Response;
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

        // No category at all, or the catch-all "Boshqa" → everyone approved.
        $broadcast = $order->target_agent_id === null
            && ($order->category === null || $order->category->shouldBroadcastToAllProviders());

        $recipients = User::query()
            ->whereNotNull('telegram_id')
            ->whereHas('profile', function ($query) use ($order, $broadcast): void {
                $query->where('status', AgentProfileStatus::Approved);

                // Directed order → only the chosen agency (filtered by id below).
                // Broadcast ("Other" or empty category) → every approved provider.
                // Otherwise → only providers who listed this category.
                if ($order->target_agent_id === null && ! $broadcast) {
                    $query->whereHas('categories', fn ($c) => $c->where('categories.id', $order->category_id));
                }
            })
            ->when($order->target_agent_id !== null, fn ($q) => $q->whereKey($order->target_agent_id))
            ->get();

        // Never send inline: a broadcast to many agencies would stall the
        // request and trip Telegram's flood limit. One retryable job per
        // recipient, released in per-second waves so the whole audience is
        // reached at a safe pace and one failure cannot lose the others.
        $perSecond = max(1, (int) config('services.telegram.broadcast_per_second', 20));
        $sent = 0;

        foreach ($recipients->values() as $index => $recipient) {
            SendNewOrderNotification::dispatch($order->id, $recipient->id)
                ->delay(now()->addSeconds(intdiv($index, $perSecond)));
            $sent++;
        }

        $this->admin->orderPlaced($order, $sent);

        return $sent;
    }

    /**
     * Send one agent the "new order" message. Called from the queued
     * {@see SendNewOrderNotification}; returns the Telegram response so the
     * job can tell a retryable failure (429/5xx) from a dead chat (403).
     * A failed document upload falls back to the plain text message.
     */
    public function deliverNewOrder(Order $order, User $recipient): ?Response
    {
        if ($recipient->telegram_id === null) {
            return null;
        }

        $order->loadMissing('category');
        Order::hydrateAttachmentFiles($order);

        $text = $this->buildMessage($order);
        $deepLink = $this->orderDeepLink($order);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Buyurtmani ko'rish", $deepLink)
            : null;

        // Telegram fetches the document over HTTP, so it needs the absolute URL.
        $firstFile = $order->relationLoaded('attachmentFiles') ? $order->attachmentFiles->first() : null;
        $documentUrl = $firstFile?->absoluteUrl();
        $chatId = (int) $recipient->telegram_id;

        if ($documentUrl !== null) {
            $response = $this->bot->sendDocument($chatId, $documentUrl, $text, $markup);

            if ($response->successful() || in_array($response->status(), [403, 429], true)) {
                return $response;
            }
        }

        return $this->bot->sendMessage($chatId, $text, $markup);
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

        $buttonLabel = "📂 Taklifni ko'rish";
        $deepLink = $this->miniAppLink('/orders/'.$offer->order_id);

        if (! $offer->hasPrice()) {
            $chat = app(DirectChatService::class)->findForOffer($offer);
            if ($chat !== null) {
                $deepLink = $this->miniAppLink('/chat/direct/'.$chat->id);
                $buttonLabel = '💬 Suhbatni ochish';
            }
        }

        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard($buttonLabel, $deepLink)
            : null;

        $this->bot->sendMessage((int) $client->telegram_id, $this->buildOfferMessage($offer), $markup);

        return true;
    }

    /**
     * Client: agent changed their pending offer price during negotiation.
     */
    public function notifyOfferPriceChanged(Offer $offer): bool
    {
        $offer->loadMissing('order.client', 'agentProfile');

        $client = $offer->order?->client;

        if ($client?->telegram_id === null) {
            return false;
        }

        $company = $offer->agentProfile?->company_name ?? 'Agentlik';
        $price = number_format((float) $offer->price, 0, '.', ' ');
        $text = "💰 {$company} taklif narxini yangiladi.\n"
            ."Buyurtma #{$offer->order_id}\n"
            ."Yangi narx: {$price} so'm";

        $deepLink = $this->miniAppLink('/orders/'.$offer->order_id);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Taklifni ko'rish", $deepLink)
            : null;

        $this->bot->sendMessage((int) $client->telegram_id, $text, $markup);

        return true;
    }

    /**
     * Client: agent sent a pricelist (priced contract) on their offer — the
     * client can now review the lines and accept.
     */
    public function notifyOfferPricelistSent(Offer $offer): bool
    {
        $offer->loadMissing('order.client', 'agentProfile');

        $client = $offer->order?->client;

        if ($client?->telegram_id === null) {
            return false;
        }

        $company = $offer->agentProfile?->company_name ?? 'Agentlik';
        $price = number_format((float) $offer->price, 0, '.', ' ');
        $text = implode("\n", [
            '📋 <b>Narxlar ro\'yxati keldi!</b>',
            '',
            "🔖 Buyurtma: <b>#{$offer->order_id}</b>",
            '🏢 Agentlik: <b>'.e($company).'</b>',
            "💰 Jami: <b>{$price} so'm</b>",
            '',
            'Narxlarni ko\'rib chiqing va mos bo\'lsa tanlang.',
        ]);

        $deepLink = $this->miniAppLink('/orders/'.$offer->order_id);
        $markup = $deepLink !== null
            ? $this->bot->openAppInlineKeyboard("📂 Ko'rish va tanlash", $deepLink)
            : null;

        $this->bot->sendMessage((int) $client->telegram_id, $text, $markup);

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
     * Someone proposed an additional agreement — tell the other party so they
     * can read and answer it.
     */
    public function notifyAmendmentProposed(OrderAmendment $amendment): void
    {
        $amendment->loadMissing(['order.client', 'offer.agent']);

        $recipient = $amendment->initiator_role === OrderAmendment::ROLE_CLIENT
            ? $amendment->offer?->agent
            : $amendment->order?->client;

        $delta = $this->deltaLabel($amendment);

        $this->sendToUser($recipient, implode("\n", array_filter([
            "📝 <b>Qo'shimcha kelishuv taklif qilindi</b>",
            '',
            "🔖 Buyurtma: <b>#{$amendment->order_id}</b>",
            "📄 Hujjat: {$amendment->number}",
            $delta !== null ? "💰 O'zgarish: <b>{$delta}</b>" : null,
            $amendment->reason ? '💬 Sabab: '.e($amendment->reason) : null,
            '',
            'Shartlarni ko\'rib chiqing va tasdiqlang.',
        ])), '📄 Kelishuvni ko\'rish', $this->amendmentPath($amendment, $recipient));
    }

    /**
     * The agreement took effect (or was refused) — tell both sides.
     */
    public function notifyAmendmentDecided(OrderAmendment $amendment, bool $applied): void
    {
        $amendment->loadMissing(['order.client', 'offer.agent']);

        $delta = $this->deltaLabel($amendment);
        $money = null;

        if ($applied && $amendment->refundIsDue()) {
            $money = '↩️ Sizga '.number_format((float) $amendment->refund_amount, 0, '.', ' ')
                ." so'm qaytariladi — operator bog'lanadi.";
        } elseif ($applied && (float) $amendment->extra_amount > 0) {
            $money = "💳 Qo'shimcha summa buyurtma hisobiga qo'shildi — ilovadan to'lang.";
        }

        foreach ([$amendment->order?->client, $amendment->offer?->agent] as $recipient) {
            $this->sendToUser($recipient, implode("\n", array_filter([
                $applied
                    ? "✅ <b>Qo'shimcha kelishuv kuchga kirdi</b>"
                    : "🚫 <b>Qo'shimcha kelishuv rad etildi</b>",
                '',
                "🔖 Buyurtma: <b>#{$amendment->order_id}</b> · {$amendment->number}",
                $delta !== null ? "💰 O'zgarish: <b>{$delta}</b>" : null,
                ! $applied && $amendment->rejection_reason ? '💬 Sabab: '.e($amendment->rejection_reason) : null,
                $recipient?->id === $amendment->order?->client_id ? $money : null,
            ])), '📂 Buyurtmani ko\'rish', $this->amendmentPath($amendment, $recipient));
        }
    }

    /**
     * Nobody answered in time — the proposal is closed.
     */
    public function notifyAmendmentExpired(OrderAmendment $amendment): void
    {
        $amendment->loadMissing(['order.client', 'offer.agent']);

        foreach ([$amendment->order?->client, $amendment->offer?->agent] as $recipient) {
            $this->sendToUser($recipient, implode("\n", [
                "⏳ <b>Qo'shimcha kelishuv muddati tugadi</b>",
                '',
                "🔖 Buyurtma: <b>#{$amendment->order_id}</b> · {$amendment->number}",
                'Kelishuv yopildi. Kerak bo\'lsa yangisini taklif qiling.',
            ]), '📂 Buyurtmani ko\'rish', $this->amendmentPath($amendment, $recipient));
        }
    }

    /**
     * A proposal is about to expire and the recipient has not answered yet.
     */
    public function notifyAmendmentReminder(OrderAmendment $amendment): void
    {
        $amendment->loadMissing(['order.client', 'offer.agent']);

        $recipient = match ($amendment->awaitingParty()) {
            OrderAmendment::ROLE_CLIENT => $amendment->order?->client,
            OrderAmendment::ROLE_AGENT => $amendment->offer?->agent,
            default => null,
        };

        if ($recipient === null) {
            return;
        }

        $this->sendToUser($recipient, implode("\n", [
            "⏰ <b>Qo'shimcha kelishuv javob kutmoqda</b>",
            '',
            "🔖 Buyurtma: <b>#{$amendment->order_id}</b> · {$amendment->number}",
            '⌛ Muddat: '.($amendment->expires_at?->format('d.m.Y H:i') ?? '—'),
        ]), '📄 Kelishuvni ko\'rish', $this->amendmentPath($amendment, $recipient));
    }

    /** Signed money label for an amendment (+/− so'm), or null when unchanged. */
    private function deltaLabel(OrderAmendment $amendment): ?string
    {
        $delta = (float) $amendment->extra_amount;

        if ($delta === 0.0) {
            return null;
        }

        return ($delta > 0 ? '+' : '−').number_format(abs($delta), 0, '.', ' ')." so'm";
    }

    /** Deep-link target: the agent works from their offer page, the client from the order. */
    private function amendmentPath(OrderAmendment $amendment, ?User $recipient): string
    {
        $isAgent = $recipient !== null && $recipient->id === $amendment->offer?->agent_id;

        return $isAgent
            ? "/offers/{$amendment->offer_id}"
            : "/orders/{$amendment->order_id}";
    }

    /**
     * Payment due date passed while the deal is already running: remind the
     * client (and let the agent know the money has not arrived).
     */
    public function notifyPaymentOverdue(Order $order): void
    {
        $order->loadMissing(['client', 'acceptedOffer.agent']);

        $amount = $order->acceptedOffer?->price;
        $amountLabel = $amount !== null
            ? number_format((float) $amount, 0, '.', ' ')." so'm"
            : null;

        $this->sendToUser($order->client, implode("\n", array_filter([
            "⏰ <b>To'lov muddati o'tdi</b>",
            '',
            "🔖 Buyurtma: <b>#{$order->id}</b> — ".e((string) $order->title),
            $amountLabel !== null ? "💰 To'lanadigan summa: <b>{$amountLabel}</b>" : null,
            '',
            "Ish davom etmoqda. To'lovni ilovadan amalga oshiring: karta orqali online, hisob (QR) yoki naqd/bank o'tkazmasi.",
        ])), "💳 To'lash", "/orders/{$order->id}");

        $agent = $order->acceptedOffer?->agent;

        if ($agent?->telegram_id !== null) {
            try {
                $this->bot->sendMessage((int) $agent->telegram_id, implode("\n", [
                    "⏰ <b>Buyurtma #{$order->id}</b> bo'yicha mijozning to'lovi hali kelmadi.",
                    'Operator mijoz bilan bog\'lanmoqda.',
                ]));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->admin->paymentOverdue($order);
    }

    /**
     * The order sat open-for-offers with zero offers for too long — a single
     * one-time nudge to the client. No automatic re-broadcast or cancellation.
     */
    public function notifyOrderStale(Order $order): void
    {
        $order->loadMissing('client');

        $body = $order->target_agent_id !== null
            ? "Siz tanlagan agentlik hali javob bermadi. Kuting, chatda so'rang, yoki qo'llab-quvvatlashga murojaat qiling."
            : "Hali birorta agentlik/dizayner javob bermadi. TZ'ni aniqlashtirib ko'ring yoki qo'llab-quvvatlashga murojaat qiling.";

        $this->sendToUser($order->client, implode("\n", [
            "\u{1F634} <b>Buyurtma #{$order->id}</b> (".e((string) $order->title).') hali javob olmadi.',
            $body,
        ]), "📂 Buyurtmani ko'rish", "/orders/{$order->id}");

        $this->admin->orderStale($order);
    }

    /**
     * A claimed Tezkor request sat with the agent for too long — one nudge to
     * the client to close it as agreed or reopen it.
     */
    public function notifyClaimStale(Order $order): void
    {
        $order->loadMissing('client');

        $this->sendToUser($order->client, implode("\n", [
            "\u{23F3} <b>So'rov #{$order->id}</b> (".e((string) $order->title).') hali band.',
            "Kelishgan bo'lsangiz — so'rovni yoping, kelisha olmagan bo'lsangiz — uni qayta oching.",
        ]), "📂 So'rovni ko'rish", "/orders/{$order->id}");

        $this->admin->orderStale($order);
    }

    /**
     * A Tezkor claim was let go. The other side hears about it: the agent when
     * the client released, the client when the agent did.
     */
    public function notifyClaimReleased(Order $order, bool $byClient, ?int $agentId): void
    {
        $order->loadMissing('client');

        if ($byClient) {
            $this->sendToUser($agentId !== null ? User::find($agentId) : null, implode("\n", [
                "🔓 So'rov <b>#{$order->id}</b> (".e((string) $order->title).') mijoz tomonidan bo\'shatildi.',
                'So\'rov yana hamma uchun ochiq.',
            ]));

            return;
        }

        $this->sendToUser($order->client, implode("\n", [
            "🔓 So'rov <b>#{$order->id}</b> (".e((string) $order->title).") bo'yicha agent ishtirokdan voz kechdi.",
            "So'rovingiz yana hamma agentlar uchun ochiq.",
        ]), "📂 So'rovni ko'rish", "/orders/{$order->id}");
    }

    /** Either side closed the claimed Tezkor request as agreed — tell the other one. */
    public function notifyClaimClosed(Order $order, bool $byAgent = false): void
    {
        if ($byAgent) {
            $order->loadMissing('client');

            $this->sendToUser($order->client, implode("\n", [
                "✅ So'rov <b>#{$order->id}</b> (".e((string) $order->title).') ijrochi tomonidan kelishilgan deb yopildi.',
            ]));

            return;
        }

        $agent = $order->claimed_agent_id !== null ? User::find($order->claimed_agent_id) : null;

        $this->sendToUser($agent, implode("\n", [
            "✅ So'rov <b>#{$order->id}</b> (".e((string) $order->title).') mijoz tomonidan kelishilgan deb yopildi.',
        ]));
    }

    /** A Propusk became active (paid via the gateway/wallet or granted by a manager). */
    public function notifyPassActivated(User $agent, AgentPass $pass): void
    {
        $this->sendToUser($agent, implode("\n", [
            "\u{1F39F} <b>Propusk faollashtirildi.</b>",
            'Amal qilish muddati: <b>'.$pass->expires_at->timezone(config('app.timezone'))->format('d.m.Y H:i').'</b> gacha.',
        ]), "📂 So'rovlarni ko'rish", '/business?tab=orders');
    }

    /** Manager decision on the account's Tender access. */
    public function notifyTenderAccess(User $user, bool $granted): void
    {
        $this->sendToUser($user, $granted
            ? "✅ Sizning hisobingizga <b>Tender</b> yaratish ruxsati berildi. Endi tender so'rovlarini yarata olasiz."
            : 'ℹ️ Hisobingizdagi <b>Tender</b> yaratish ruxsati bekor qilindi. Jarayondagi tenderlar davom etadi.');
    }

    /**
     * The manager transferred an agent's tranche to their bank account. The
     * transfer happens outside the platform, so this is the only moment the
     * agent hears about it.
     */
    public function notifyPayoutReleased(Payout $payout): void
    {
        $payout->loadMissing(['agent', 'order']);

        $amount = number_format($payout->amount / 100, 0, '.', ' ');
        $tranche = $payout->tranche === PayoutTranche::Advance
            ? 'Boshlang\'ich (avans)'
            : ($payout->tranche === PayoutTranche::Final ? 'Yakuniy' : 'Tuzatish');

        $this->sendToUser($payout->agent, implode("\n", array_filter([
            '🏦 <b>Pul bank hisobingizga o\'tkazildi</b>',
            '',
            "💰 Summa: <b>{$amount} so'm</b>",
            "📦 To'lov turi: {$tranche}",
            $payout->order !== null
                ? "🔖 Buyurtma: <b>#{$payout->order_id}</b> — ".e((string) $payout->order->title)
                : null,
            $payout->reference !== null
                ? "🧾 To'lov topshirig'i: <code>".e($payout->reference).'</code>'
                : null,
            '',
            'Bank o\'tkazmasi hisobingizga tushishi bir necha soat olishi mumkin.',
        ])), '💼 Daromadlarim', '/earnings');
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

        $deadlineLine = $order->correction_deadline_at
            ? "Tuzatish uchun muddat: <b>{$order->correction_deadline_at->format('d.m.Y H:i')}</b>gacha."
            : null;

        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", array_filter([
            "⚠️ Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha klient ishni qabul qilmadi.',
            'Buyurtma yana "jarayonda" holatiga qaytdi.',
            $deadlineLine,
            'Shu muddatgacha tuzatib, qayta topshiring — aks holda buyurtma ko\'rib chiqish uchun administratsiyaga yuboriladi.',
        ])), "📂 Buyurtmani ko'rish", $this->agentOrderPath($order));

        $this->admin->disputeOpened($order);
    }

    /**
     * The correction window on a quality dispute is about to run out — nudge
     * the agent once, a day before the sweep would flag the order as a
     * problem report.
     */
    public function notifyCorrectionDeadlineApproaching(Order $order): void
    {
        $order->loadMissing('acceptedOffer.agent');

        $deadline = $order->correction_deadline_at?->format('d.m.Y H:i') ?? '—';

        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", [
            "⏳ Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha tuzatish muddati tugamoqda.',
            "Muddat: <b>{$deadline}</b>gacha.",
            'Shu vaqtgacha ishni tuzatib topshirmasangiz, buyurtma admin ko\'rib chiqishiga yuboriladi.',
        ]), "📂 Buyurtmani ko'rish", $this->agentOrderPath($order));
    }

    /**
     * The client reported "agent never started" — the agent should hear it
     * directly (not just find out later from a refund), so they have a
     * chance to respond before a manager reviews the report.
     */
    public function notifyReportedNoStart(Order $order): void
    {
        $order->loadMissing('acceptedOffer.agent');

        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", [
            "🚩 Buyurtma <b>#{$order->id}</b> (".e($order->title).') bo\'yicha mijoz sizni ishni hali boshlamagansiz deb shikoyat qildi.',
            "Agar ish boshlangan bo'lsa, darhol chatda mijozga yozing yoki topshiriqni yuklang — admin tez orada ko'rib chiqadi.",
        ]), "📂 Buyurtmani ko'rish", $this->agentOrderPath($order));
    }

    /**
     * A manager resolved a problem-order report (quality dispute past its
     * correction window, or "agent never started") — tell both the client
     * and the agent (a refund affects the agent's earnings; a dismissal
     * clears their name).
     */
    public function notifyOrderProblemResolved(Order $order, bool $refunded): void
    {
        $order->loadMissing('client', 'acceptedOffer.agent');

        $this->sendToUser($order->client, implode("\n", [
            $refunded
                ? "↩️ <b>Buyurtma #{$order->id}</b> bo'yicha qaytarish rasmiylashtirildi."
                : "✅ <b>Buyurtma #{$order->id}</b> bo'yicha shikoyat ko'rib chiqildi — muammo topilmadi.",
            $refunded
                ? "Operator siz bilan bog'lanadi."
                : 'Buyurtma odatdagidek davom etadi.',
        ]), '📂 Buyurtmani ko\'rish', "/orders/{$order->id}");

        // The agent's earnings are affected by a refund (and they should know
        // a dismissed report cleared their name either way).
        $this->sendToUser($order->acceptedOffer?->agent, implode("\n", [
            $refunded
                ? "↩️ <b>Buyurtma #{$order->id}</b> bo'yicha shikoyat bo'yicha mijozga qaytarish rasmiylashtirildi."
                : "✅ <b>Buyurtma #{$order->id}</b> bo'yicha sizga qarshi shikoyat asossiz deb topildi.",
            $refunded
                ? "Bu chiqimingizga (payout) ta'sir qilishi mumkin — tafsilot uchun operatorga murojaat qiling."
                : 'Buyurtma odatdagidek davom etadi, qo\'shimcha choralar ko\'rish shart emas.',
        ]), "📂 Buyurtmani ko'rish", $this->agentOrderPath($order));
    }

    /**
     * Client cancelled while the order was still open for offers. Tell every
     * agent who had a pending bid (so they stop waiting) and mirror to ops.
     *
     * @param  list<User>  $biddingAgents
     */
    public function notifyOrderCancelled(Order $order, array $biddingAgents): void
    {
        $order->loadMissing('category');

        foreach ($biddingAgents as $agent) {
            $this->sendToUser($agent, implode("\n", [
                "🚫 Buyurtma <b>#{$order->id}</b> (".e($order->title).') mijoz tomonidan bekor qilindi.',
                'Taklifingiz yopildi — keyingi buyurtmalarda omad!',
            ]));
        }

        $this->admin->orderCancelled($order, count($biddingAgents));
    }

    /**
     * Client cancelled after accepting an offer but before paying. Ops is
     * notified separately by PaymentService; here we only ping the winning agent.
     */
    public function notifyAwaitingPaymentCancelled(Order $order, ?User $agent): void
    {
        if ($agent === null) {
            return;
        }

        $order->loadMissing('category');

        $this->sendToUser($agent, implode("\n", [
            "🚫 Buyurtma <b>#{$order->id}</b> (".e($order->title).') mijoz to\'lovni yakunlamasdan bekor qildi.',
            'Deal ochilmadi — keyingi buyurtmalarda omad!',
        ]));
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

    /**
     * Provider-facing deep link. Agents don't own the order, so the client
     * order route (/orders/{id}) 404s for them — they land on their own
     * workspace instead, which focuses the order (open card to bid, or the
     * active-deal card once their offer was accepted) via the `order` query.
     */
    private function agentOrderPath(Order $order): string
    {
        return '/offers?order='.$order->id;
    }

    private function buildOfferMessage(Offer $offer): string
    {
        $order = $offer->order;
        $agent = $offer->agent;
        $company = e($offer->agentProfile?->company_name
            ?? trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')));

        if (! $offer->hasPrice()) {
            return implode("\n", [
                '🙋 <b>Yangi javob!</b>',
                '',
                "🔖 Buyurtma: <b>#{$order->id}</b> — ".e($order->title),
                "🏢 Agentlik: <b>{$company}</b>",
                '',
                'Agentlik buyurtmangizga qiziqish bildirdi. Suhbatni boshlash uchun tugmani bosing.',
            ]);
        }

        $price = number_format((float) $offer->price, 0, '.', ' ');
        $lines = [
            '💼 <b>Yangi taklif!</b>',
            '',
            "🔖 Buyurtma: <b>#{$order->id}</b> — ".e($order->title),
            "🏢 Agentlik: <b>{$company}</b>",
            "💰 Narx: <b>{$price} so'm</b>",
        ];

        if ($offer->comment !== null && $offer->comment !== '') {
            $lines[] = '💬 Izoh: '.e(Str::limit($offer->comment, 300));
        }

        $lines[] = '';
        $lines[] = "Taklifni ko'rish va tanlash uchun quyidagi tugmani bosing.";

        return implode("\n", $lines);
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
            '📌 Loyiha: <b>'.e($order->title).'</b>',
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
