<?php

namespace App\Services\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\WithdrawalStatus;
use App\Jobs\RecalculateRating;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Order\OfferService;
use App\Services\Payout\PayoutService;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly MulticardClient $client,
        private readonly OfferService $offers,
        private readonly PayoutService $payouts,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * Start (or reuse) the checkout for an order's accepted offer. Creates a
     * Multicard hosted-checkout invoice and returns the Payment carrying the
     * checkout_url the client is redirected to.
     */
    public function startOrderPayment(Order $order): Payment
    {
        $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

        if ($offer === null) {
            throw new RuntimeException('Order has no accepted offer to pay for.');
        }

        // Reuse an existing unpaid payment so retries don't spawn duplicates —
        // but only while its invoice is still alive. Multicard cancels an
        // invoice once its ttl elapses; reusing that dead checkout_url strands
        // the client on the gateway's "invoice expired" page. When expired we
        // fall through and mint a fresh invoice instead.
        $existing = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->latest()
            ->first();

        if ($existing !== null && $existing->checkout_url && ! $this->invoiceExpired($existing)) {
            return $existing;
        }

        // Retire the dead invoice so it is never reused or polled again.
        if ($existing !== null && $existing->status === PaymentStatus::Draft) {
            $existing->update(['status' => PaymentStatus::Error]);
        }

        $amount = (int) round(((float) $offer->price) * 100); // som → tiyin

        /** @var Payment $payment */
        $payment = $order->payments()->create([
            'payment_uuid' => (string) Str::uuid(),
            'gateway' => 'multicard',
            'purpose' => PaymentPurpose::Order,
            'payer_id' => $order->client_id,
            'amount' => $amount,
            'currency' => 'UZS',
            'status' => PaymentStatus::Draft,
        ]);

        $data = $this->client->createInvoice($this->invoicePayload($order, $payment, $amount));

        $payment->update([
            'gateway_uuid' => $data['uuid'] ?? null,
            'checkout_url' => $data['checkout_url'] ?? null,
            'meta' => $data,
        ]);

        return $payment->refresh();
    }

    /**
     * Handle a Multicard status webhook. Signature must already be verified by
     * the caller (controller). Idempotent: safe to call for repeated webhooks.
     *
     * When the webhook omits `status` (seen on the real stand), we fill it via
     * `getPayment` — never map null → Draft (that would downgrade Progress).
     * When the webhook *does* carry a status, trust it and do not re-query
     * (a lagging GET can report `error` over a valid `success`).
     *
     * Status updates are monotonic: a final Success cannot be overwritten by a
     * late progress/error (only Revert = refund is allowed). The
     * `payments:reconcile-pending` sweep uses this same path.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): void
    {
        $uuid = (string) ($payload['uuid'] ?? '');

        $payment = Payment::query()->where('gateway_uuid', $uuid)->first();

        if ($payment === null) {
            return; // unknown transaction — nothing to do
        }

        $rawStatus = $payload['status'] ?? null;

        if (! is_string($rawStatus) || $rawStatus === '') {
            $payload = $this->enrichPayloadFromGateway($payload, $uuid);
            $rawStatus = $payload['status'] ?? null;
        }

        $previous = $payment->status;

        // Metadata (card / PS) may still refresh on ignored / missing status.
        $payment->fill([
            'card_pan' => $payload['card_pan'] ?? $payment->card_pan,
            'ps' => $payload['ps'] ?? $payment->ps,
            'billing_id' => $payload['billing_id'] ?? $payment->billing_id,
        ]);

        if (! is_string($rawStatus) || $rawStatus === '') {
            logger()->warning('multicard.callback.status_missing', [
                'uuid' => $uuid,
                'current' => $previous->value,
            ]);
            $payment->save();

            return;
        }

        $status = PaymentStatus::fromGateway($rawStatus);

        if (! $previous->canTransitionTo($status)) {
            logger()->warning('multicard.callback.status_ignored', [
                'uuid' => $uuid,
                'current' => $previous->value,
                'ignored' => $status->value,
            ]);
            $payment->save();

            return;
        }

        $payment->status = $status;

        if ($status === PaymentStatus::Success && $payment->paid_at === null) {
            $payment->paid_at = now();
        }

        if ($status === PaymentStatus::Revert && $payment->refunded_at === null) {
            $payment->refunded_at = now();
        }

        $payment->save();

        if ($status === PaymentStatus::Success) {
            $this->onOrderPaid($payment);
        }

        // First transition into Revert only — idempotent retries must not
        // re-cancel / re-notify.
        if ($status === PaymentStatus::Revert && $previous !== PaymentStatus::Revert) {
            // Cancel the order only when we initiated the refund (admin panel).
            // Unexpected gateway reverts (e.g. auto-refund after a bad callback
            // ack) leave the order for ops to decide — void unpaid payouts and
            // alert either way.
            $cancelOrder = data_get($payment->meta, 'refund_source') === 'admin';
            $this->onOrderRefunded($payment, $cancelOrder);
        }
    }

    /**
     * Fill a status-less callback payload from GET /payment/{uuid}.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichPayloadFromGateway(array $payload, string $uuid): array
    {
        try {
            $gateway = $this->client->getPayment($uuid);
        } catch (\Throwable $e) {
            logger()->warning('multicard.callback.get_payment_failed', [
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }

        $gatewayStatus = $gateway['status'] ?? null;

        if (! is_string($gatewayStatus) || $gatewayStatus === '') {
            logger()->info('multicard.callback.get_payment_empty_status', ['uuid' => $uuid]);

            return $payload;
        }

        logger()->info('multicard.callback.status_from_gateway', [
            'uuid' => $uuid,
            'status' => $gatewayStatus,
        ]);

        $enriched = $payload;
        $enriched['status'] = $gatewayStatus;

        foreach (['card_pan', 'ps', 'billing_id'] as $key) {
            if (isset($gateway[$key]) && is_string($gateway[$key]) && $gateway[$key] !== '') {
                $enriched[$key] = $gateway[$key];
            }
        }

        return $enriched;
    }

    /**
     * Admin-initiated full refund via Multicard DELETE /payment/{uuid}.
     * Blocks when any agent payout for the order is already paid (manual
     * recovery required first). Marks refund_source=admin so the ensuing
     * revert settlement cancels the order.
     */
    public function refundByAdmin(Payment $payment, User $admin): Payment
    {
        if ($payment->status !== PaymentStatus::Success) {
            throw ValidationException::withMessages([
                'payment' => ['Only a successful payment can be refunded.'],
            ]);
        }

        if (blank($payment->gateway_uuid)) {
            throw ValidationException::withMessages([
                'payment' => ['This payment has no gateway reference to refund.'],
            ]);
        }

        $order = $payment->payable;

        if ($order instanceof Order) {
            $paidOut = $order->payouts()->where('status', PayoutStatus::Paid)->exists();

            if ($paidOut) {
                throw ValidationException::withMessages([
                    'payment' => ['Cannot refund: an agent payout was already released. Recover funds manually first.'],
                ]);
            }
        }

        $meta = array_merge(is_array($payment->meta) ? $payment->meta : [], [
            'refund_source' => 'admin',
            'refunded_by' => $admin->id,
            'refund_requested_at' => now()->toIso8601String(),
        ]);
        $payment->update(['meta' => $meta]);

        try {
            $this->client->refundPayment((string) $payment->gateway_uuid);
        } catch (\Throwable $e) {
            // Clear the admin marker so an unrelated later revert is not
            // treated as an intentional cancel.
            $payment->update([
                'meta' => array_merge(is_array($payment->fresh()?->meta) ? $payment->fresh()->meta : [], [
                    'refund_source' => null,
                    'refund_failed' => $e->getMessage(),
                ]),
            ]);

            throw $e;
        }

        // Apply locally immediately (webhook may be delayed or missing).
        $this->handleCallback([
            'uuid' => $payment->gateway_uuid,
            'status' => 'revert',
            'card_pan' => $payment->card_pan,
            'ps' => $payment->ps,
            'billing_id' => $payment->billing_id,
        ]);

        return $payment->refresh();
    }

    /**
     * Auto-cancel an order that stayed unpaid past the configured timeout.
     */
    public function expireAwaitingPayment(Order $order): void
    {
        $this->cancelAwaitingPayment($order, 'timeout');
    }

    /**
     * Cancel an unpaid checkout (timeout cron or client self-cancel).
     * Best-effort cancels open Multicard invoices, then marks the order cancelled.
     *
     * @param  'timeout'|'client'  $reason
     */
    public function cancelAwaitingPayment(Order $order, string $reason = 'timeout'): void
    {
        $order->refresh();

        if ($order->status !== OrderStatus::AwaitingPayment) {
            if ($reason === 'client') {
                throw ValidationException::withMessages([
                    'order' => ['This order can no longer be cancelled.'],
                ]);
            }

            return;
        }

        $paid = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->where('status', PaymentStatus::Success)
            ->exists();

        if ($paid) {
            if ($reason === 'client') {
                throw ValidationException::withMessages([
                    'order' => ['Payment already completed — this order can no longer be cancelled.'],
                ]);
            }

            return;
        }

        foreach ($order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->whereNotNull('gateway_uuid')
            ->get() as $payment) {
            try {
                $this->client->cancelInvoice((string) $payment->gateway_uuid);
                $payment->update(['status' => PaymentStatus::Error]);
            } catch (\Throwable $e) {
                report($e);
                $payment->update(['status' => PaymentStatus::Error]);
            }
        }

        $order->update(['status' => OrderStatus::Cancelled]);

        RecalculateRating::dispatch($order->client_id);

        try {
            if ($reason === 'client') {
                $this->notifier->paymentAwaitingCancelledByClient($order);
            } else {
                $this->notifier->paymentAwaitingTimedOut($order);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Activate the deal once its order payment succeeds.
     */
    private function onOrderPaid(Payment $payment): void
    {
        if ($payment->purpose !== PaymentPurpose::Order) {
            return;
        }

        $order = $payment->payable;

        if (! $order instanceof Order || $order->status !== OrderStatus::AwaitingPayment) {
            return; // already activated or not an order payment
        }

        $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

        if ($offer !== null) {
            $this->offers->activateDeal($offer);
        }

        try {
            $this->notifier->paymentSucceeded($payment);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Gateway refunded/reversed a charge. Always voids unpaid payouts and alerts
     * ops. Cancels the order only when `$cancelOrder` is true (admin-initiated).
     */
    private function onOrderRefunded(Payment $payment, bool $cancelOrder): void
    {
        if ($payment->purpose !== PaymentPurpose::Order) {
            return;
        }

        $order = $payment->payable;

        if (! $order instanceof Order) {
            return;
        }

        $result = ['cancelled' => 0, 'paid_tiyin' => 0];
        $orderCancelled = false;

        DB::transaction(function () use ($order, $cancelOrder, &$result, &$orderCancelled): void {
            $this->abortWithdrawalsForOrder($order);

            $result = $this->payouts->cancelUnpaidForOrder($order);

            if ($cancelOrder && in_array($order->status, [
                OrderStatus::AwaitingPayment,
                OrderStatus::InProgress,
                OrderStatus::WorkSubmitted,
            ], true)) {
                $order->update(['status' => OrderStatus::Cancelled]);
                $orderCancelled = true;
            }
        });

        try {
            $this->notifier->paymentRefunded($payment, $result['cancelled'], $result['paid_tiyin'], $orderCancelled);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Abort in-flight card withdrawals tied to this order's processing payouts
     * so a refund cannot race a credit that still thinks funds are reserved.
     */
    private function abortWithdrawalsForOrder(Order $order): void
    {
        $ids = $order->payouts()
            ->whereNotNull('withdrawal_id')
            ->pluck('withdrawal_id')
            ->unique()
            ->filter();

        if ($ids->isEmpty()) {
            return;
        }

        Withdrawal::query()
            ->whereIn('id', $ids)
            ->whereNotIn('status', [
                WithdrawalStatus::Success->value,
                WithdrawalStatus::Failed->value,
                WithdrawalStatus::Cancelled->value,
            ])
            ->update([
                'status' => WithdrawalStatus::Cancelled->value,
                'failure_reason' => 'payment_reverted',
                'card_token' => null,
            ]);
    }

    /** Invoice lifetime in seconds (floored to a safe minimum). */
    private function invoiceTtl(): int
    {
        return max(300, (int) config('services.multicard.invoice_ttl', 3600));
    }

    /**
     * Whether a payment's invoice has (nearly) expired and must not be reused.
     * A 60s margin avoids handing back an invoice that dies mid-checkout.
     */
    private function invoiceExpired(Payment $payment): bool
    {
        if ($payment->created_at === null) {
            return true;
        }

        return $payment->created_at->addSeconds($this->invoiceTtl() - 60)->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Order $order, Payment $payment, int $amount): array
    {
        $payload = [
            'store_id' => (string) config('services.multicard.store_id'),
            'amount' => $amount,
            'invoice_id' => $payment->payment_uuid,
            'lang' => 'uz',
            'ttl' => $this->invoiceTtl(),
            'callback_url' => (string) config('services.multicard.callback_url'),
        ];

        // Fiscal receipt line — optional. When the gateway's OFD service is
        // down/misconfigured, sending it makes the whole payment fail
        // ("сервис недоступен" on confirm), so it is behind a flag. Real
        // fiscalization (proper mxik / package_code per category) is a later
        // finance phase.
        if (config('services.multicard.ofd_enabled')) {
            $payload['ofd'] = [[
                'name' => Str::limit((string) $order->title, 120, ''),
                'qty' => 1,
                'price' => $amount,
                'total' => $amount,
                'mxik' => '10305001001000000',
                'package_code' => '1495862',
                'vat' => 0,
            ]];
        }

        if ($returnUrl = $this->miniAppReturnUrl($order)) {
            // Success and failure both return into the mini app order page —
            // without these the user is stranded on Multicard's hosted page.
            $payload['return_url'] = $returnUrl;
            $payload['return_error_url'] = $returnUrl.'?pay=failed';
        } else {
            logger()->warning('multicard.invoice.missing_mini_app_url', [
                'order_id' => $order->id,
                'hint' => 'Set TELEGRAM_MINI_APP_URL so checkout return_url / return_error_url work.',
            ]);
        }

        return $payload;
    }

    /**
     * Deep link back into the mini app's order page after checkout, if the
     * mini app base URL is configured (required for a usable hosted checkout).
     */
    private function miniAppReturnUrl(Order $order): ?string
    {
        $base = trim((string) config('services.telegram.mini_app_url'));

        if ($base === '') {
            return null;
        }

        return rtrim($base, '/')."/orders/{$order->id}";
    }
}
