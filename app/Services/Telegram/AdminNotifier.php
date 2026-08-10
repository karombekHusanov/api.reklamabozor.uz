<?php

namespace App\Services\Telegram;

use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Review;
use App\Models\User;

/**
 * Posts marketplace events to the private admin ("ops") Telegram group so the
 * team can watch the market without opening the panel. Disabled until
 * TELEGRAM_ADMIN_CHAT_ID is configured; individual event types can be muted
 * via TELEGRAM_ADMIN_EVENTS. Sends never throw — failures are reported.
 */
class AdminNotifier
{
    public function __construct(
        private readonly TelegramBotService $bot,
    ) {}

    public function orderPlaced(Order $order, int $notifiedAgents): void
    {
        $order->loadMissing('category', 'client');

        $this->send('order_placed', implode("\n", [
            "🆕 <b>Buyurtma #{$order->id}</b> — ".e($order->category?->name_uz ?? ''),
            '👤 Klient: '.$this->userLabel($order->client),
            "📤 {$notifiedAgents} ta agentlikka yuborildi",
        ]));
    }

    public function offerSubmitted(Offer $offer): void
    {
        $offer->loadMissing('order', 'agent', 'agentProfile');

        if (! $offer->hasPrice()) {
            $this->send('offer_submitted', implode("\n", [
                "🙋 Otklik — buyurtma <b>#{$offer->order_id}</b>",
                '🏢 '.$this->agencyLabel($offer),
            ]));

            return;
        }

        $this->send('offer_submitted', implode("\n", [
            "💼 Taklif — buyurtma <b>#{$offer->order_id}</b>",
            '🏢 '.$this->agencyLabel($offer)." • 💰 {$this->price($offer)} so'm",
        ]));
    }

    public function dealMade(Offer $offer): void
    {
        $offer->loadMissing('order.client', 'agent', 'agentProfile');

        $this->send('deal', implode("\n", [
            "🤝 <b>Kelishuv — buyurtma #{$offer->order_id}</b>",
            '👤 Klient: '.$this->userLabel($offer->order->client),
            '🏢 Agentlik: '.$this->agencyLabel($offer),
            "💰 Narx: <b>{$this->price($offer)} so'm</b>",
            'Holat: ish boshlandi (in_progress).',
        ]));
    }

    public function workSubmitted(Order $order): void
    {
        $this->send('work_submitted', implode("\n", [
            "🏁 Ish topshirildi — buyurtma <b>#{$order->id}</b>",
            'Klient tasdig\'i kutilmoqda (3 kunda auto-complete).',
        ]));
    }

    public function orderCompleted(Order $order, bool $auto): void
    {
        $this->send('completed', implode("\n", [
            "✅ Yakunlandi — buyurtma <b>#{$order->id}</b>",
            $auto ? 'Klient javob bermadi — avtomatik yakunlandi.' : 'Klient ishni qabul qildi.',
        ]));
    }

    public function disputeOpened(Order $order): void
    {
        $order->loadMissing('client');

        $this->send('dispute', implode("\n", [
            "⚠️ <b>Muammo — buyurtma #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            'Klient ishni qabul qilmadi — aralashuv kerak. Buyurtma in_progress holatiga qaytarildi.',
        ]));
    }

    public function reviewSubmitted(Review $review): void
    {
        $review->loadMissing('agent', 'agentProfile');

        $agency = e($review->agentProfile?->company_name
            ?? trim(($review->agent?->first_name ?? '').' '.($review->agent?->last_name ?? '')));

        $this->send('review', implode("\n", [
            "⭐ Yangi baho — buyurtma <b>#{$review->order_id}</b>",
            "🏢 {$agency} • {$review->rating}/5",
            'Moderatsiya kutilmoqda (admin panel → Reviews).',
        ]));
    }

    public function paymentSucceeded(Payment $payment): void
    {
        $som = number_format($payment->amountSom(), 0, '.', ' ');
        $orderId = $payment->payable_id;

        $this->send('payment_success', implode("\n", [
            "💳 <b>To'lov qabul qilindi — buyurtma #{$orderId}</b>",
            "💰 {$som} so'm • ".e((string) ($payment->ps ?? '')),
            'Holat: ish boshlandi (in_progress).',
        ]));
    }

    /**
     * Gateway reversed a charge (Multicard status `revert`). Ops must check
     * whether any agent payout was already released.
     */
    public function paymentRefunded(
        Payment $payment,
        int $cancelledPayouts,
        int $paidTiyin,
        bool $orderCancelled,
    ): void {
        $som = number_format($payment->amountSom(), 0, '.', ' ');
        $orderId = $payment->payable_id;
        $paidSom = number_format($paidTiyin / 100, 0, '.', ' ');

        $lines = [
            "↩️ <b>To'lov qaytarildi — buyurtma #{$orderId}</b>",
            "💰 {$som} so'm (Multicard revert)",
            $orderCancelled
                ? 'Buyurtma bekor qilindi (admin refund).'
                : '⚠️ Buyurtma hali ochiq — kutilmagan revert. Ops tekshirib cancel/qayta to\'lov qaror qilsin.',
            "Payout: {$cancelledPayouts} ta bekor qilindi.",
        ];

        if ($paidTiyin > 0) {
            $lines[] = "⚠️ <b>{$paidSom} so'm</b> allaqachon agentga chiqarilgan — qo'lda undirish kerak.";
        }

        $this->send('payment_refunded', implode("\n", $lines));
    }

    public function paymentAwaitingTimedOut(Order $order): void
    {
        $order->loadMissing('client', 'category');

        $this->send('payment_timeout', implode("\n", [
            "⏰ <b>To'lov muddati o'tdi — buyurtma #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            '📁 '.e($order->category?->name_uz ?? $order->title),
            'Buyurtma bekor qilindi (awaiting_payment timeout).',
        ]));
    }

    public function paymentAwaitingCancelledByClient(Order $order): void
    {
        $order->loadMissing('client', 'category');

        $this->send('payment_awaiting_cancelled', implode("\n", [
            "🚫 <b>To'lov bekor — buyurtma #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            '📁 '.e($order->category?->name_uz ?? $order->title),
            'Klient awaiting_payment holatida buyurtmani bekor qildi.',
        ]));
    }

    public function orderCancelled(Order $order, int $rejectedOffers): void
    {
        $order->loadMissing('client', 'category');

        $this->send('order_cancelled', implode("\n", [
            "🚫 <b>Buyurtma #{$order->id} bekor qilindi</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            '📁 '.e($order->category?->name_uz ?? $order->title),
            $rejectedOffers > 0
                ? "💼 {$rejectedOffers} ta kutilayotgan taklif rad etildi."
                : 'Takliflar yo\'q edi.',
        ]));
    }

    /**
     * Post a one-off connectivity check so the group wiring can be verified.
     */
    public function ping(string $text): void
    {
        $this->send('ping', $text);
    }

    private function send(string $event, string $text): void
    {
        if (! $this->enabled($event)) {
            return;
        }

        try {
            $this->bot->sendMessage((string) config('services.telegram.admin_chat_id'), $text);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function enabled(string $event): bool
    {
        if ((string) config('services.telegram.admin_chat_id') === '') {
            return false;
        }

        $events = trim((string) config('services.telegram.admin_events', '*'));

        return $events === '*'
            || $event === 'ping'
            || in_array($event, array_map('trim', explode(',', $events)), true);
    }

    private function userLabel(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $name = trim($user->first_name.' '.($user->last_name ?? ''));

        return e($user->phone !== null ? "{$name} ({$user->phone})" : $name);
    }

    private function agencyLabel(Offer $offer): string
    {
        $agent = $offer->agent;

        return e($offer->agentProfile?->company_name
            ?? trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')));
    }

    private function price(Offer $offer): string
    {
        return number_format((float) $offer->price, 0, '.', ' ');
    }
}
