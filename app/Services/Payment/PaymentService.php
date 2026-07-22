<?php

namespace App\Services\Payment;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Order\OfferService;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly MulticardClient $client,
        private readonly OfferService $offers,
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

        // Reuse an existing unpaid payment so retries don't spawn duplicates.
        $existing = $order->payments()
            ->where('purpose', PaymentPurpose::Order)
            ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress])
            ->latest()
            ->first();

        if ($existing !== null && $existing->checkout_url) {
            return $existing;
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
     * @param  array<string, mixed>  $payload
     */
    public function handleCallback(array $payload): void
    {
        $uuid = (string) ($payload['uuid'] ?? '');

        $payment = Payment::query()->where('gateway_uuid', $uuid)->first();

        if ($payment === null) {
            return; // unknown transaction — nothing to do
        }

        $status = PaymentStatus::fromGateway($payload['status'] ?? null);

        $payment->fill([
            'status' => $status,
            'card_pan' => $payload['card_pan'] ?? $payment->card_pan,
            'ps' => $payload['ps'] ?? $payment->ps,
            'billing_id' => $payload['billing_id'] ?? $payment->billing_id,
        ]);

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
     * @return array<string, mixed>
     */
    private function invoicePayload(Order $order, Payment $payment, int $amount): array
    {
        $payload = [
            'store_id' => (string) config('services.multicard.store_id'),
            'amount' => $amount,
            'invoice_id' => $payment->payment_uuid,
            'lang' => 'uz',
            'callback_url' => (string) config('services.multicard.callback_url'),
            // Minimal fiscal receipt line. Real fiscalization (proper mxik /
            // package_code per category) is a later finance phase.
            'ofd' => [[
                'name' => Str::limit((string) $order->title, 120, ''),
                'qty' => 1,
                'price' => $amount,
                'total' => $amount,
                'mxik' => '10305001001000000',
                'package_code' => '1495862',
                'vat' => 0,
            ]],
        ];

        if ($returnUrl = $this->miniAppReturnUrl($order)) {
            $payload['return_url'] = $returnUrl;
        }

        return $payload;
    }

    /**
     * Deep link back into the mini app's order page after checkout, if the
     * mini app base URL is configured.
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
