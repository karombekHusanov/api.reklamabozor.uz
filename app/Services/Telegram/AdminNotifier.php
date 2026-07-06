<?php

namespace App\Services\Telegram;

use App\Models\Offer;
use App\Models\Order;
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
        $offer->loadMissing('order', 'agent.agentProfile');

        $this->send('offer_submitted', implode("\n", [
            "💼 Taklif — buyurtma <b>#{$offer->order_id}</b>",
            '🏢 '.$this->agencyLabel($offer)." • 💰 {$this->price($offer)} so'm",
        ]));
    }

    public function dealMade(Offer $offer): void
    {
        $offer->loadMissing('order.client', 'agent.agentProfile');

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

        return e($agent?->agentProfile?->company_name
            ?? trim(($agent?->first_name ?? '').' '.($agent?->last_name ?? '')));
    }

    private function price(Offer $offer): string
    {
        return number_format((float) $offer->price, 0, '.', ' ');
    }
}
