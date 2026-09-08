<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Order\AcceptOfferRequest;
use App\Http\Resources\DirectChatResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\PaymentResource;
use App\Models\Offer;
use App\Services\Chat\DirectChatService;
use App\Services\Order\OfferService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly PaymentService $payments,
        private readonly DirectChatService $directChats,
    ) {}

    /**
     * Client accepts an agent's offer for their order.
     *
     * The client must first confirm the three-party contract shown in the accept
     * drawer (`accept_contract`); the consent is logged against that exact
     * document before the deal (or checkout) starts.
     *
     * When the payment gateway is enabled the order moves to awaiting_payment
     * and the response carries a `payment.checkout_url` the client is
     * redirected to — the deal activates once payment succeeds. Otherwise the
     * deal activates immediately and `payment` is null.
     */
    public function accept(AcceptOfferRequest $request, Offer $offer): JsonResponse
    {
        $accepted = $this->offers->acceptOffer(
            $request->user(),
            $offer,
            $request->validated('contract_hash'),
            $request,
        );

        $payment = null;

        if ($accepted->order->status === OrderStatus::AwaitingPayment) {
            try {
                $payment = $this->payments->startOrderPayment($accepted->order);
            } catch (\Throwable $e) {
                // The acceptance is already committed; a gateway hiccup (e.g.
                // Multicard unreachable) must not fail it with a 500. The order
                // stays awaiting_payment and the client retries via /orders/{id}/pay.
                report($e);
            }
        }

        return $this->success([
            'offer' => new OfferResource($accepted),
            'payment' => $payment ? new PaymentResource($payment) : null,
        ], 'Offer accepted');
    }

    /**
     * The three-party contract the client is asked to accept for this offer —
     * rendered in the accept drawer before they confirm.
     */
    public function contractPreview(Request $request, Offer $offer): JsonResponse
    {
        return $this->success(
            $this->offers->previewContractForClient($request->user(), $offer),
        );
    }

    /**
     * Client (order owner) opens the order-scoped DirectChat for an offer/interest.
     */
    public function openChat(Request $request, Offer $offer): JsonResponse
    {
        $chat = $this->directChats->openForClient($request->user(), $offer);
        $chat->load(['client', 'agent', 'agentProfile', 'order.category', 'lastMessage.attachments']);

        return $this->success(
            (new DirectChatResource($chat))->withActiveOffer(
                $this->directChats->activeOfferForPair($chat),
            ),
        );
    }
}
