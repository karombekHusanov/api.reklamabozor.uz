<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * (Re)start checkout for an order the client has already accepted an offer
     * on but not yet paid. Returns the payment with its checkout_url.
     */
    public function pay(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        if (! config('services.multicard.enabled')) {
            return $this->error('Payments are not enabled.', 422);
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            return $this->error('This order is not awaiting payment.', 422);
        }

        $payment = $this->payments->startOrderPayment($order);

        return $this->success(new PaymentResource($payment), 'Checkout ready');
    }

    /**
     * Latest payment status for an order (used by the mini app to poll after
     * the client returns from the checkout page).
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payment = $order->latestPayment;

        return $this->success(
            $payment ? new PaymentResource($payment) : null,
            'Payment status',
        );
    }
}
