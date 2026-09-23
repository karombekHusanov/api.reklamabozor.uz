<?php

namespace App\Services\Telegram;

use App\Enums\OrderProblemReason;
use App\Enums\PaymentMethod;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
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
            "📤 {$notifiedAgents} ta agentlikka yuborish navbatga qo'yildi",
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
        $method = $this->methodLabel($payment);

        $this->send('payment_success', implode("\n", [
            "💳 <b>To'lov qabul qilindi — buyurtma #{$orderId}</b>",
            "💰 {$som} so'm • ".e((string) ($payment->ps ?? $method)),
            "Usul: {$method}",
        ]));
    }

    /**
     * Client asked to pay outside the gateway (cash desk or bank transfer) —
     * a manager must confirm the money once it arrives.
     */
    public function offlinePaymentRequested(Payment $payment): void
    {
        $som = number_format($payment->amountSom(), 0, '.', ' ');
        $orderId = $payment->payable_id;

        $this->send('payment_offline_requested', implode("\n", [
            "🧾 <b>Offline to'lov so'raldi — buyurtma #{$orderId}</b>",
            "💰 {$som} so'm • ".$this->methodLabel($payment),
            'Pul kelgach admin panelda tasdiqlang (Finance → Payments).',
        ]));
    }

    /**
     * Offline money (cash / bank transfer) has to be handed back by a human —
     * the gateway cannot reverse it.
     */
    public function manualRefundRequired(Payment $payment): void
    {
        $som = number_format($payment->amountSom(), 0, '.', ' ');
        $orderId = $payment->payable_id;

        $this->send('payment_manual_refund', implode("\n", [
            "↩️ <b>Qo'lda qaytarish kerak — buyurtma #{$orderId}</b>",
            "💰 {$som} so'm • ".$this->methodLabel($payment),
            'Mijoz buyurtmani bekor qildi; pul gateway orqali qaytmaydi.',
        ]));
    }

    /**
     * The deal is active but the client has not paid by the due date.
     */
    public function paymentOverdue(Order $order): void
    {
        $due = $order->payment_due_at?->format('d.m.Y') ?? '—';

        $this->send('payment_overdue', implode("\n", [
            "⏰ <b>To'lov muddati o'tdi — buyurtma #{$order->id}</b>",
            "Muddat: {$due}",
            'Ish davom etmoqda, pul hali kelmadi.',
        ]));
    }

    /**
     * Order completed while money is still owed — the agent's final payout is
     * held until the client settles.
     */
    public function finalPayoutHeld(Order $order, int $outstandingTiyin): void
    {
        $som = number_format($outstandingTiyin / 100, 0, '.', ' ');

        $this->send('payout_held', implode("\n", [
            "⏸️ <b>Yakuniy chiqim ushlab turildi — buyurtma #{$order->id}</b>",
            "Qoldiq qarz: {$som} so'm",
            'Mijoz to\'lovni yopgach payout rejalashtiriladi.',
        ]));
    }

    /**
     * The cooling-off window closed on paid orders: these payouts are now free
     * to leave, and the bank transfer is a manual step.
     */
    public function payoutsDue(int $count, int $totalTiyin): void
    {
        $som = number_format($totalTiyin / 100, 0, '.', ' ');

        $this->send('payouts_due', implode("\n", [
            "\u{1F4B8} <b>Bank o'tkazmasi kutmoqda: {$count} ta chiqim</b>",
            "Jami: {$som} so'm",
            'Admin panel → Moliya → Chiqimlar → «To\'lovga tayyor».',
        ]));
    }

    /**
     * A proposal needs the operator's signature before it can take effect.
     */
    public function amendmentNeedsOperator(OrderAmendment $amendment): void
    {
        $delta = number_format((float) $amendment->extra_amount, 0, '.', ' ');

        $this->send('amendment_operator', implode("\n", [
            "🖋 <b>Operator tasdig'i kerak — {$amendment->number}</b>",
            "Buyurtma #{$amendment->order_id} · o'zgarish {$delta} so'm",
            'Admin panel → Kelishuvlar → Operator kutmoqda.',
        ]));
    }

    /**
     * An applied addendum lowered a paid deal — the difference has to be handed
     * back by a human (the gateway has no partial refund).
     */
    public function amendmentRefundDue(OrderAmendment $amendment): void
    {
        $som = number_format((float) $amendment->refund_amount, 0, '.', ' ');

        $this->send('amendment_refund_due', implode("\n", [
            "↩️ <b>Qaytarish kerak — {$amendment->number}</b>",
            "Buyurtma #{$amendment->order_id} · {$som} so'm",
            'Qo\'shimcha kelishuv summani kamaytirdi. Admin panelda qaytarishni qayd eting.',
        ]));
    }

    /**
     * An order landed in the problem-orders queue — either the scheduled sweep
     * (unresolved quality dispute) or the client's own "agent never started"
     * report.
     */
    public function orderProblemFlagged(Order $order, OrderProblemReason $reason): void
    {
        $order->loadMissing('client');

        $reasonLabel = match ($reason) {
            OrderProblemReason::QualityUnresolved => "Sifat bo'yicha e'tiroz hal qilinmadi",
            OrderProblemReason::AgentNoStart => 'Agent avans oldi, ishni boshlamadi',
        };

        $this->send('order_problem_flagged', implode("\n", [
            "🚩 <b>Muammoli buyurtma — #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            "Sabab: {$reasonLabel}",
            'Admin panel → Muammoli buyurtmalar.',
        ]));
    }

    /**
     * An order with an open (unresolved) problem report reached `completed`
     * through the normal flow anyway — the payout gate still holds the money,
     * but a human should look at this one first.
     */
    public function orderCompletedWhileFlagged(Order $order): void
    {
        $order->loadMissing('client');

        $this->send('order_completed_while_flagged', implode("\n", [
            "⚠️ <b>Muammoli buyurtma yakunlandi — #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            "Buyurtma hali ochiq muammo hisoboti bilan turib 'completed' bo'ldi — chiqim hamon bloklangan, lekin ustuvor ko'rib chiqing.",
            'Admin panel → Muammoli buyurtmalar.',
        ]));
    }

    /**
     * An order sat open-for-offers with zero offers for longer than the
     * configured grace period — a one-time nudge to ops, mirroring the
     * client-facing reminder ({@see OrderNotifier::notifyOrderStale()}).
     */
    public function orderStale(Order $order): void
    {
        $order->loadMissing('client');

        $this->send('order_stale', implode("\n", [
            "\u{1F634} <b>Javobsiz buyurtma — #{$order->id}</b>",
            '👤 Klient: '.$this->userLabel($order->client),
            $order->target_agent_id !== null
                ? 'Yo\'naltirilgan agentlik hali otklik bermadi.'
                : 'Hali birorta otklik kelmadi.',
            'Kerak bo\'lsa qo\'lda yordam bering.',
        ]));
    }

    private function methodLabel(Payment $payment): string
    {
        return match ($payment->method) {
            PaymentMethod::Cash => 'naqd',
            PaymentMethod::BankTransfer => 'bank o\'tkazmasi',
        };
    }

    /**
     * A payment was reverted (admin refund or client-cancel refund). Ops must
     * check whether any agent payout was already released.
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
            "💰 {$som} so'm ({$this->methodLabel($payment)})",
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
