<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Enums\PaymentMethod;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Payment\StartOfflinePaymentRequest;
use App\Http\Requests\Api\V1\Payment\StartPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * (Re)start the Multicard payment for an active order the client has not
     * paid yet.
     *
     * `mode=checkout` (default) returns the checkout_url to redirect to now;
     * `mode=invoice` returns a long-lived link (plus short_link for a QR) the
     * client can pay later from any wallet — optionally texted to their phone.
     */
    public function pay(StartPaymentRequest $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        if (! config('services.multicard.enabled')) {
            return $this->error('Payments are not enabled.', 422);
        }

        $shareable = $request->validated('mode') === 'invoice';

        try {
            $payment = $this->payments->startOrderPayment(
                $order,
                $shareable,
                $shareable && (bool) $request->validated('send_sms', false),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Gateway unreachable / errored — surface a retryable error, not a 500.
            report($e);

            return $this->error('Payment service is temporarily unavailable. Please try again.', 503);
        }

        return $this->success(
            new PaymentResource($payment),
            $shareable ? 'Invoice ready' : 'Checkout ready',
        );
    }

    /**
     * Client chooses to pay outside the gateway — cash at the platform's desk
     * or a bank transfer. Returns the pending payment with its invoice
     * (hisob-faktura); a manager confirms the money once it arrives.
     */
    public function payOffline(StartOfflinePaymentRequest $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payment = $this->payments->startOfflineOrderPayment(
            $order,
            PaymentMethod::from((string) $request->validated('method')),
        );

        return $this->success(new PaymentResource($payment), 'Invoice ready');
    }

    /**
     * Every payment attempt on an order (online, invoice, cash, bank) so the
     * client sees what is pending and what settled.
     */
    public function index(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payments = $order->payments()
            ->with('invoiceFile')
            ->latest()
            ->get();

        return $this->success(PaymentResource::collection($payments));
    }

    /**
     * Latest payment status for an order (used by the mini app to poll after
     * the client returns from the checkout page).
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payment = $order->latestPayment;
        $payment?->loadMissing('invoiceFile');

        return $this->success(
            $payment ? new PaymentResource($payment) : null,
            'Payment status',
        );
    }
}
