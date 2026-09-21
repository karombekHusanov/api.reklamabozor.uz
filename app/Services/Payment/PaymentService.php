<?php

namespace App\Services\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Jobs\RecalculateRating;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
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
        private readonly OfferService $offers,
        private readonly PayoutService $payouts,
        private readonly AdminNotifier $notifier,
    ) {}

    /**
     * Register an offline payment intent: the client pays in cash at the office
     * or by bank transfer, and a manager confirms it in the admin panel. The
     * generated invoice (hisob-faktura) carries the platform's requisites.
     */
    public function startOfflineOrderPayment(Order $order, PaymentMethod $method, int $percent = 100): Payment
    {
        if (! $method->isOffline()) {
            throw ValidationException::withMessages([
                'method' => ['This method is not an offline payment.'],
            ]);
        }

        if (! in_array($percent, [50, 100], true)) {
            throw ValidationException::withMessages([
                'percent' => ['Only 50% or 100% of the outstanding amount can be paid at a time.'],
            ]);
        }

        $this->assertPayable($order);

        $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

        if ($offer === null) {
            throw new RuntimeException('Order has no accepted offer to pay for.');
        }

        return DB::transaction(function () use ($order, $method, $percent): Payment {
            // One open intent per order across every method — repeat taps
            // return the same invoice, and a race between two requests (e.g.
            // cash + bank transfer opened in two tabs) cannot leave two live
            // intents for the client to pay twice.
            $openIntents = $order->payments()
                ->where('purpose', PaymentPurpose::Order)
                ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
                ->lockForUpdate()
                ->get();

            $existing = $openIntents->firstWhere('method', $method);
            $otherMethodOpen = $openIntents->first(fn (Payment $p): bool => $p->method !== $method);

            if ($existing !== null && (int) $existing->percent === $percent) {
                return $this->attachInvoice($existing, $order);
            }

            if ($otherMethodOpen !== null && $existing === null) {
                throw ValidationException::withMessages([
                    'method' => ['A different payment method is already pending for this order.'],
                ]);
            }

            if ($existing !== null) {
                // The client changed their mind about how much to pay (e.g. had
                // a pending 100% invoice open and now wants 50% instead).
                // Reusing it would hand back a PDF quoting the wrong amount, so
                // void the stale intent the same way an abandoned checkout is
                // retired elsewhere in this service — never leave two live
                // offline intents on one order.
                $existing->update(['status' => PaymentStatus::Error]);
            }

            $amount = intdiv($this->amountDue($order) * $percent, 100);

            /** @var Payment $payment */
            $payment = $order->payments()->create([
                'payment_uuid' => (string) Str::uuid(),
                'purpose' => PaymentPurpose::Order,
                'method' => $method,
                'payer_id' => $order->client_id,
                'amount' => $amount,
                'currency' => 'UZS',
                'percent' => $percent,
                // Awaiting the manager's confirmation.
                'status' => PaymentStatus::Progress,
            ]);

            try {
                $this->notifier->offlinePaymentRequested($payment->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            return $this->attachInvoice($payment, $order);
        });
    }

    /**
     * Manager confirms money that arrived outside the gateway (cash desk or
     * bank statement). Settles the payment.
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

        return DB::transaction(function () use ($payment, $admin, $reference, $note): Payment {
            // Re-read under a row lock: a concurrent confirm/reject on the same
            // payment must not both settle it (or settle then reject).
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Success) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(PaymentStatus::Success)) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment can no longer be confirmed.'],
                ]);
            }

            $locked->update([
                'status' => PaymentStatus::Success,
                'paid_at' => now(),
                'reference' => $reference,
                'note' => $note,
                'matched_via' => 'admin',
                'confirmed_by' => $admin->id,
                'confirmed_at' => now(),
            ]);

            $this->onOrderPaid($locked->fresh());

            return $locked->refresh();
        });
    }

    /**
     * Kapitalbank auto-reconciliation confirms a bank-transfer payment: the
     * client's incoming transfer was matched to this payment by contract
     * number + exact amount (see `BankReconciliationService`). Settles the
     * payment exactly like a manual admin confirm would, but tags
     * `matched_via = 'auto'` and leaves `confirmed_by` null (no human acted).
     *
     * @param  array<string, mixed>  $matchedDocument  the matched GetDoc1C row
     */
    public function confirmBankTransferAutoMatch(Payment $payment, array $matchedDocument): Payment
    {
        if ($payment->method !== PaymentMethod::BankTransfer) {
            throw ValidationException::withMessages([
                'payment' => ['Only bank transfer payments are auto-matched.'],
            ]);
        }

        return DB::transaction(function () use ($payment, $matchedDocument): Payment {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Success) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(PaymentStatus::Success)) {
                throw ValidationException::withMessages([
                    'payment' => ['This payment can no longer be confirmed.'],
                ]);
            }

            $locked->update([
                'status' => PaymentStatus::Success,
                'paid_at' => now(),
                // `reference` is string(120) — the raw bank purpose text can run
                // longer than that, so it is trimmed for storage.
                'reference' => mb_substr((string) ($matchedDocument['purpose'] ?? ''), 0, 120),
                'matched_via' => 'auto',
                'confirmed_by' => null,
                'confirmed_at' => now(),
            ]);

            $this->onOrderPaid($locked->fresh());

            return $locked->refresh();
        });
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
        $order->assertTender();

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

    /**
     * Admin-initiated full refund of a settled offline payment: the manager
     * returns the money by hand (cash desk / bank transfer back) and records
     * it here. Blocks when any agent payout for the order is already paid
     * (manual recovery required first). Marks refund_source=admin so the
     * ensuing settlement cancels the order.
     */
    public function refundByAdmin(Payment $payment, User $admin): Payment
    {
        if ($payment->status !== PaymentStatus::Success) {
            throw ValidationException::withMessages([
                'payment' => ['Only a successful payment can be refunded.'],
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

    /**
     * Client cancels a paid deal inside the cooling-off window. Offline money
     * (cash / bank transfer) has no API to reverse, so the payment is marked
     * reverted and ops is told to hand the money back by hand. Cancels the
     * order and voids unpaid payouts.
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

        $payment->update([
            'status' => PaymentStatus::Revert,
            'refunded_at' => now(),
            'meta' => array_merge(is_array($payment->meta) ? $payment->meta : [], [
                'refund_source' => 'client_cancel',
                'refunded_by' => $client->id,
                'refund_requested_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->onOrderRefunded($payment->fresh(), cancelOrder: true);

        try {
            $this->notifier->manualRefundRequired($payment->fresh());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Retire every open payment attempt on an order (client cancelled while
     * unpaid) — nothing to call at a gateway, the offline requests simply die.
     */
    public function voidOpenIntents(Order $order): void
    {
        $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->update(['status' => PaymentStatus::Error]);
    }

    /**
     * Client cancels a legacy order still parked in `awaiting_payment`. Marks
     * any open payment intents dead, then cancels the order.
     */
    public function cancelAwaitingPayment(Order $order): void
    {
        $order->refresh();

        if ($order->status !== OrderStatus::AwaitingPayment) {
            throw ValidationException::withMessages([
                'order' => ['This order can no longer be cancelled.'],
            ]);
        }

        $paid = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->where('status', PaymentStatus::Success)
            ->exists();

        if ($paid) {
            throw ValidationException::withMessages([
                'order' => ['Payment already completed — this order can no longer be cancelled.'],
            ]);
        }

        $this->voidOpenIntents($order);

        $order->update(['status' => OrderStatus::Cancelled]);

        RecalculateRating::dispatch($order->client_id);

        try {
            $this->notifier->paymentAwaitingCancelledByClient($order);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Settle the money side once an order payment succeeds: mark the order
     * paid, plan the agent's advance payout and cancel the sibling intents the
     * client abandoned (e.g. a cash invoice they ended up paying by bank
     * transfer). Legacy orders still parked in `awaiting_payment` are
     * activated here.
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
            return; // already settled — idempotent retry
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
        $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereKeyNot($settled->id)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->update(['status' => PaymentStatus::Error]);
    }

    /**
     * A refund (admin or client-cancel initiated) always voids unpaid payouts
     * and alerts ops. Cancels the order only when `$cancelOrder` is true.
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
}
