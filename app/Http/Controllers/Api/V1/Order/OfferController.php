<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\OfferResource;
use App\Http\Resources\PaymentResource;
use App\Models\Offer;
use App\Services\Order\OfferService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Client accepts an agent's offer for their order.
     *
     * When the payment gateway is enabled the order moves to awaiting_payment
     * and the response carries a `payment.checkout_url` the client is
     * redirected to — the deal activates once payment succeeds. Otherwise the
     * deal activates immediately and `payment` is null.
     */
    public function accept(Request $request, Offer $offer): JsonResponse
    {
        $accepted = $this->offers->acceptOffer($request->user(), $offer);

        $payment = null;

        if ($accepted->order->status === OrderStatus::AwaitingPayment) {
            $payment = $this->payments->startOrderPayment($accepted->order);
        }

        return $this->success([
            'offer' => new OfferResource($accepted),
            'payment' => $payment ? new PaymentResource($payment) : null,
        ], 'Offer accepted');
    }
}
