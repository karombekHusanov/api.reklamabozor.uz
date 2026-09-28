<?php

namespace App\Services\Telegram;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\PaymentMethod;
use App\Models\GatewayPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Posts every money movement to the dedicated "payments" Telegram group:
 * card payments (Propusk / wallet top-up), order payments (admin-confirmed or
 * Kapitalbank auto-matched) and refunds. Disabled until
 * TELEGRAM_PAYMENTS_CHAT_ID is set. Messages go out only after the database
 * transaction commits (a rolled-back payment is never announced) and a send
 * never throws — failures are reported.
 */
class PaymentFeedNotifier
{
    public function __construct(
        private readonly TelegramBotService $bot,
    ) {}

    public function gatewayPaid(GatewayPayment $payment): void
    {
        $payment->loadMissing('user.profile');

        $this->send(implode("\n", [
            "💳 <b>+{$this->som($payment->amount_tiyin)} so'm</b> · ".$this->gatewayPurpose($payment),
            '👤 '.$this->userLabel($payment->user),
            '💳 '.e(trim(($payment->meta['card_mask'] ?? '').' · '.strtoupper($payment->gateway), ' ·')),
            "🧾 #{$payment->id}",
        ]));
    }

    public function gatewayRefunded(GatewayPayment $payment): void
    {
        $payment->loadMissing('user.profile');
        $refund = $payment->meta['refund'] ?? [];

        $this->send(implode("\n", array_filter([
            "↩️ <b>−{$this->som($payment->amount_tiyin)} so'm</b> qaytarildi · ".$this->gatewayPurpose($payment),
            '👤 '.$this->userLabel($payment->user),
            '💳 '.e(trim(($payment->meta['card_mask'] ?? '').' · '.strtoupper($payment->gateway), ' ·')),
            isset($refund['by']) ? '🛡 Admin: '.$this->userLabel(User::find($refund['by'])) : null,
            isset($refund['reason']) ? '📝 '.e((string) $refund['reason']) : null,
            "🧾 #{$payment->id}",
        ])));
    }

    public function orderPaid(Payment $payment): void
    {
        $order = $payment->payable;
        $order = $order instanceof Order ? $order->loadMissing('client', 'contract') : null;

        $how = $payment->matched_via === 'auto'
            ? "Kapitalbank ko'chirmasidan avtomatik topildi"
            : 'Admin tasdiqladi: '.$this->userLabel($payment->confirmed_by ? User::find($payment->confirmed_by) : null);

        $this->send(implode("\n", array_filter([
            "🏦 <b>+{$this->som($payment->amount)} so'm</b> · Buyurtma #".($order?->id ?? '—').($payment->percent ? " ({$payment->percent}%)" : ''),
            '👤 Mijoz: '.$this->userLabel($order?->client),
            '💼 '.$this->method($payment->method).' · '.$how,
            $order?->contract?->number ? '📄 Shartnoma '.e($order->contract->number) : null,
            "🧾 To'lov #{$payment->id}",
        ])));
    }

    public function orderRefunded(Payment $payment): void
    {
        $order = $payment->payable;
        $order = $order instanceof Order ? $order->loadMissing('client') : null;
        $source = $payment->meta['refund_source'] ?? null;

        $this->send(implode("\n", array_filter([
            "↩️ <b>−{$this->som($payment->amount)} so'm</b> qaytarildi · Buyurtma #".($order?->id ?? '—'),
            '👤 Mijoz: '.$this->userLabel($order?->client),
            '💼 '.$this->method($payment->method).' · '.match ($source) {
                'client_cancel' => "mijoz bekor qildi — pulni qo'lda qaytarish kerak",
                'admin' => 'admin qaytardi',
                default => 'qaytarildi',
            },
            "🧾 To'lov #{$payment->id}",
        ])));
    }

    private function send(string $text): void
    {
        $chatId = (string) config('services.telegram.payments_chat_id');

        if ($chatId === '') {
            return;
        }

        DB::afterCommit(function () use ($chatId, $text): void {
            try {
                $this->bot->sendMessage($chatId, $text);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    private function gatewayPurpose(GatewayPayment $payment): string
    {
        return $payment->purpose === GatewayPaymentPurpose::Pass ? 'Propusk' : "Balansni to'ldirish";
    }

    private function method(?PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Naqd',
            PaymentMethod::BankTransfer => "Bank o'tkazmasi",
            default => '—',
        };
    }

    private function som(int|string|null $tiyin): string
    {
        return number_format(intdiv((int) $tiyin, 100), 0, '.', ' ');
    }

    private function userLabel(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $name = $user->profile?->company_name ?: trim($user->first_name.' '.($user->last_name ?? ''));
        $phone = $user->phone ? ", {$user->phone}" : '';

        return e("{$name} (#{$user->id}{$phone})");
    }
}
