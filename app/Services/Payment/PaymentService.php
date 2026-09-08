<?php

namespace App\Services\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\WithdrawalStatus;
use App\Jobs\RecalculateRating;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Payment;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Order\AmendmentService;
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
     * Start (or reuse) the Multicard payment for an order's accepted offer.
     *
     * Two shapes of the same invoice:
     *  - checkout (default) — short-lived, the client is redirected right away;
     *  - shareable — long-lived link the client pays later from any wallet,
     *    rendered as a QR and optionally sent to their phone by SMS.
     *
     * Either way the money lands in the Multicard merchant account and the
     * webhook settles it.
     */
    public function startOrderPayment(Order $order, bool $shareable = false, bool $sendSms = false): Payment
    {
        $this->assertPayable($order);

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

        // A shareable invoice must live long enough to be paid later, so a
        // short checkout invoice is not reused for it (and vice versa).
        if (
            $existing !== null
            && $existing->checkout_url
            && ! $this->invoiceExpired($existing, $shareable)
            && (bool) data_get($existing->meta, 'shareable', false) === $shareable
        ) {
            return $existing;
        }

        // Retire the dead invoice so it is never reused or polled again.
        if ($existing !== null && $existing->status === PaymentStatus::Draft) {
            $existing->update(['status' => PaymentStatus::Error]);
        }

        $amount = $this->amountDue($order);

        /** @var Payment $payment */
        $payment = $order->payments()->create([
            'payment_uuid' => (string) Str::uuid(),
            'gateway' => 'multicard',
            'purpose' => PaymentPurpose::Order,
            'method' => PaymentMethod::Multicard,
            'payer_id' => $order->client_id,
            'amount' => $amount,
            'currency' => 'UZS',
            'status' => PaymentStatus::Draft,
        ]);

        $payload = $this->invoicePayload($order, $payment, $amount, $shareable);

        if ($sendSms && ($phone = $this->smsPhone($order)) !== null) {
            $payload['sms'] = $phone;
        }

        $data = $this->client->createInvoice($payload);

        $payment->update([
            'gateway_uuid' => $data['uuid'] ?? null,
            'checkout_url' => $data['checkout_url'] ?? null,
            // Production-only short link — the QR source; falls back to checkout_url.
            'short_link' => $data['short_link'] ?? null,
            'meta' => array_merge(is_array($data) ? $data : [], [
                'shareable' => $shareable,
                'sms_sent_to' => $payload['sms'] ?? null,
            ]),
        ]);

        return $payment->refresh();
    }

    /**
     * Register an offline payment intent: the client pays in cash at the office
     * or by bank transfer, and a manager confirms it in the admin panel. The
     * generated invoice (hisob-faktura) carries the platform's requisites.
     */
    public function startOfflineOrderPayment(Order $order, PaymentMethod $method): Payment
    {
        if (! $method->isOffline()) {
            throw ValidationException::withMessages([
                'method' => ['This method is not an offline payment.'],
            ]);
        }

        $this->assertPayable($order);

        $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

        if ($offer === null) {
            throw new RuntimeException('Order has no accepted offer to pay for.');
        }

        // One open intent per method — repeat taps return the same invoice.
        $existing = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->where('method', $method)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->latest()
            ->first();

        if ($existing !== null) {
            return $this->attachInvoice($existing, $order);
        }

        $amount = $this->amountDue($order);

        /** @var Payment $payment */
        $payment = $order->payments()->create([
            'payment_uuid' => (string) Str::uuid(),
            'gateway' => 'offline',
            'purpose' => PaymentPurpose::Order,
            'method' => $method,
            'payer_id' => $order->client_id,
            'amount' => $amount,
            'currency' => 'UZS',
            // Awaiting the manager's confirmation, not the gateway.
            'status' => PaymentStatus::Progress,
        ]);

        try {
            $this->notifier->offlinePaymentRequested($payment->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->attachInvoice($payment, $order);
    }

    /**
     * Manager confirms money that arrived outside the gateway (cash desk or
     * bank statement). Settles the payment exactly like a webhook would.
     */
    public function confirmOfflinePayment(
        Payment $payment,
        User $admin,
        ?string $reference = null,
        ?string $note = null,
    ): Payment {
        if (! $payment->method->isOffline()) {
            throw ValidationException::withMessages([
                'payment' => ['Only cash / bank transfer payments are confirmed by hand.'],
            ]);
        }

        if ($payment->status === PaymentStatus::Success) {
            return $payment;
        }

        if (! $payment->status->canTransitionTo(PaymentStatus::Success)) {
            throw ValidationException::withMessages([
                'payment' => ['This payment can no longer be confirmed.'],
            ]);
        }

        $payment->update([
            'status' => PaymentStatus::Success,
            'paid_at' => now(),
            'reference' => $reference,
            'note' => $note,
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
        ]);

        $this->onOrderPaid($payment->fresh());

        return $payment->refresh();
    }

    /**
     * Manager rejects an offline intent that never arrived (or was a mistake).
     */
    public function rejectOfflinePayment(Payment $payment, User $admin, ?string $note = null): Payment
    {
        if (! $payment->method->isOffline()) {
            throw ValidationException::withMessages([
                'payment' => ['Only cash / bank transfer payments are rejected by hand.'],
            ]);
        }

        if ($payment->status === PaymentStatus::Success) {
            throw ValidationException::withMessages([
                'payment' => ['A confirmed payment cannot be rejected — refund it instead.'],
            ]);
        }

        $payment->update([
            'status' => PaymentStatus::Error,
            'note' => $note,
            'confirmed_by' => $admin->id,
            'confirmed_at' => now(),
        ]);

        return $payment->refresh();
    }

    /**
     * Generate (once) the invoice document for an offline payment.
     */
    private function attachInvoice(Payment $payment, Order $order): Payment
    {
        if ($payment->invoice_file_id !== null) {
            return $payment;
        }

        try {
            $file = app(InvoiceService::class)->generateForPayment($payment, $order);
            $payment->update(['invoice_file_id' => $file->id]);
        } catch (\Throwable $e) {
            // The intent itself is valid without the PDF — ops can still confirm.
            report($e);
        }

        return $payment->refresh();
    }

    /**
     * What this checkout should charge, in tiyin: the outstanding balance, so a
     * top-up after an applied amendment bills only the difference.
     */
    private function amountDue(Order $order): int
    {
        $outstanding = $order->outstandingTiyin();

        if ($outstanding > 0) {
            return $outstanding;
        }

        throw ValidationException::withMessages([
            'order' => ['This order has nothing left to pay.'],
        ]);
    }

    /**
     * An order may be paid while the deal is active and the money is still
     * owed. Legacy orders parked in `awaiting_payment` stay payable too.
     */
    private function assertPayable(Order $order): void
    {
        $payableStatus = in_array($order->status, [
            OrderStatus::AwaitingPayment,
            OrderStatus::InProgress,
            OrderStatus::WorkSubmitted,
        ], true);

        if (! $payableStatus || $order->payment_state === OrderPaymentState::Paid) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting a payment.'],
            ]);
        }
    }

    /** Client phone in Multicard's SMS format (998XXXXXXXXX), if usable. */
    private function smsPhone(Order $order): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $order->client?->phone);

        return is_string($digits) && strlen($digits) === 12 && str_starts_with($digits, '998')
            ? $digits
            : null;
    }

    /**
     * Start (or reuse) the checkout for an approved amendment's extra charge.
     * The amendment is applied to the deal only once this payment succeeds.
     */
    public function startAmendmentPayment(OrderAmendment $amendment): Payment
    {
        $order = $amendment->order;

        if (! $order instanceof Order) {
            throw new RuntimeException('Amendment has no order to charge against.');
        }

        $amount = (int) round(((float) $amendment->extra_amount) * 100); // som → tiyin

        if ($amount <= 0) {
            throw new RuntimeException('Amendment has no extra amount to charge.');
        }

        $existing = Payment::query()
            ->where('purpose', PaymentPurpose::Amendment)
            ->where('payable_type', $amendment->getMorphClass())
            ->where('payable_id', $amendment->id)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->latest()
            ->first();

        if ($existing !== null && $existing->checkout_url && ! $this->invoiceExpired($existing)) {
            return $existing;
        }

        if ($existing !== null && $existing->status === PaymentStatus::Draft) {
            $existing->update(['status' => PaymentStatus::Error]);
        }

        /** @var Payment $payment */
        $payment = Payment::query()->create([
            'payment_uuid' => (string) Str::uuid(),
            'gateway' => 'multicard',
            'purpose' => PaymentPurpose::Amendment,
            'payable_type' => $amendment->getMorphClass(),
            'payable_id' => $amendment->id,
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
            $this->onAmendmentPaid($payment);
        }

        // First transition into Revert only — idempotent retries must not
        // re-cancel / re-notify.
        if ($status === PaymentStatus::Revert && $previous !== PaymentStatus::Revert) {
            // Cancel the order only when we initiated the refund (admin panel).
            // Unexpected gateway reverts (e.g. auto-refund after a bad callback
            // ack) leave the order for ops to decide — void unpaid payouts and
            // alert either way.
            $cancelOrder = in_array(
                data_get($payment->meta, 'refund_source'),
                ['admin', 'client_cancel'],
                true,
            );
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

        if ($payment->method->isOffline()) {
            // Cash / bank transfer — the manager returns the money by hand and
            // records it here; there is no gateway call to make.
            $payment->update([
                'status' => PaymentStatus::Revert,
                'refunded_at' => now(),
                'confirmed_by' => $admin->id,
                'confirmed_at' => now(),
                'meta' => array_merge(is_array($payment->meta) ? $payment->meta : [], [
                    'refund_source' => 'admin',
                    'refunded_by' => $admin->id,
                    'refund_requested_at' => now()->toIso8601String(),
                ]),
            ]);

            $this->onOrderRefunded($payment->fresh(), cancelOrder: true);

            return $payment->refresh();
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
     * Client cancels a paid deal inside the cooling-off window. Gateway money
     * is reverted through Multicard; offline money (cash / bank transfer) has
     * no API to reverse, so the payment is marked reverted and ops is told to
     * hand the money back. Either path cancels the order and voids payouts.
     */
    public function refundForClientCancel(Order $order, User $client): void
    {
        /** @var Payment|null $payment */
        $payment = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->where('status', PaymentStatus::Success)
            ->latest()
            ->first();

        if ($payment === null) {
            throw ValidationException::withMessages([
                'order' => ['No settled payment found for this order.'],
            ]);
        }

        if ($order->payouts()->where('status', PayoutStatus::Paid)->exists()) {
            throw ValidationException::withMessages([
                'order' => ['An agent payout was already released — contact support.'],
            ]);
        }

        $meta = array_merge(is_array($payment->meta) ? $payment->meta : [], [
            'refund_source' => 'client_cancel',
            'refunded_by' => $client->id,
            'refund_requested_at' => now()->toIso8601String(),
        ]);
        $payment->update(['meta' => $meta]);

        if ($payment->method->isOffline()) {
            // Cash / bank transfer: nothing to call, a human returns the money.
            $payment->update([
                'status' => PaymentStatus::Revert,
                'refunded_at' => now(),
            ]);

            $this->onOrderRefunded($payment->fresh(), cancelOrder: true);

            try {
                $this->notifier->manualRefundRequired($payment->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        if (blank($payment->gateway_uuid)) {
            throw ValidationException::withMessages([
                'order' => ['This payment has no gateway reference to refund — contact support.'],
            ]);
        }

        try {
            $this->client->refundPayment((string) $payment->gateway_uuid);
        } catch (\Throwable $e) {
            $payment->update([
                'meta' => array_merge(is_array($payment->fresh()?->meta) ? $payment->fresh()->meta : [], [
                    'refund_source' => null,
                    'refund_failed' => $e->getMessage(),
                ]),
            ]);

            report($e);

            throw ValidationException::withMessages([
                'order' => ['Refund failed at the payment gateway. Please try again or contact support.'],
            ]);
        }

        // Apply locally at once — the webhook may lag or never arrive.
        $this->handleCallback([
            'uuid' => $payment->gateway_uuid,
            'status' => 'revert',
            'card_pan' => $payment->card_pan,
            'ps' => $payment->ps,
            'billing_id' => $payment->billing_id,
        ]);
    }

    /**
     * Retire every open payment attempt on an order (client cancelled while
     * unpaid): Multicard invoices are cancelled at the gateway, offline
     * requests simply die.
     */
    public function voidOpenIntents(Order $order): void
    {
        $open = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->get();

        foreach ($open as $payment) {
            if ($payment->method === PaymentMethod::Multicard && $payment->gateway_uuid) {
                try {
                    $this->client->cancelInvoice((string) $payment->gateway_uuid);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $payment->update(['status' => PaymentStatus::Error]);
        }
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
     * Settle the money side once an order payment succeeds: mark the order
     * paid, plan the agent's advance payout and cancel the sibling intents the
     * client abandoned (e.g. a cash invoice they ended up paying online).
     * Legacy orders still parked in `awaiting_payment` are activated here.
     */
    private function onOrderPaid(Payment $payment): void
    {
        if ($payment->purpose !== PaymentPurpose::Order) {
            return;
        }

        $order = $payment->payable;

        if (! $order instanceof Order) {
            return;
        }

        if ($order->payment_state === OrderPaymentState::Paid && $order->outstandingTiyin() <= 0) {
            return; // already settled — idempotent webhook retry
        }

        // Legacy flow: the deal was waiting for the money before starting.
        if ($order->status === OrderStatus::AwaitingPayment) {
            $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

            if ($offer !== null) {
                $this->offers->activateDeal($offer);
                $order->refresh();
            }
        }

        $order->update(['paid_at' => $order->paid_at ?? $payment->paid_at ?? now()]);

        // Ledger decides: a part payment leaves the order unpaid, a full one
        // (including an amendment's extra) settles it.
        $order->refresh()->recalculatePaymentState();
        $order->refresh();

        if ($order->payment_state !== OrderPaymentState::Paid) {
            // Still owed — no payout, no invoice cleanup yet.
            try {
                $this->notifier->paymentSucceeded($payment);
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        $this->closeOpenIntents($order, $payment);

        // Money is fully in — now the agent's advance can be planned.
        $this->payouts->planAdvance($order->fresh());

        try {
            $this->notifier->paymentSucceeded($payment);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Retire the other open payment intents for a settled order so the client
     * cannot pay twice and ops does not see stale cash/bank requests.
     */
    private function closeOpenIntents(Order $order, Payment $settled): void
    {
        $open = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereKeyNot($settled->id)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->get();

        foreach ($open as $intent) {
            if ($intent->method === PaymentMethod::Multicard && $intent->gateway_uuid) {
                try {
                    $this->client->cancelInvoice((string) $intent->gateway_uuid);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $intent->update(['status' => PaymentStatus::Error]);
        }
    }

    /**
     * Apply an approved amendment once its extra payment succeeds.
     */
    private function onAmendmentPaid(Payment $payment): void
    {
        if ($payment->purpose !== PaymentPurpose::Amendment) {
            return;
        }

        $amendment = $payment->payable;

        if (! $amendment instanceof OrderAmendment) {
            return;
        }

        app(AmendmentService::class)->applyPaid($amendment->fresh());
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

            $order->update(['payment_state' => OrderPaymentState::Refunded]);

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

    /**
     * Invoice lifetime in seconds (floored to a safe minimum). A shareable
     * link lives much longer than an in-app checkout — it is paid later.
     */
    private function invoiceTtl(bool $shareable = false): int
    {
        $ttl = $shareable
            ? (int) config('services.multicard.invoice_link_ttl', 259200)
            : (int) config('services.multicard.invoice_ttl', 3600);

        return max(300, $ttl);
    }

    /**
     * Whether a payment's invoice has (nearly) expired and must not be reused.
     * A 60s margin avoids handing back an invoice that dies mid-checkout.
     */
    private function invoiceExpired(Payment $payment, bool $shareable = false): bool
    {
        if ($payment->created_at === null) {
            return true;
        }

        return $payment->created_at->addSeconds($this->invoiceTtl($shareable) - 60)->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Order $order, Payment $payment, int $amount, bool $shareable = false): array
    {
        $payload = [
            'store_id' => (string) config('services.multicard.store_id'),
            'amount' => $amount,
            'invoice_id' => $payment->payment_uuid,
            'lang' => 'uz',
            'ttl' => $this->invoiceTtl($shareable),
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
