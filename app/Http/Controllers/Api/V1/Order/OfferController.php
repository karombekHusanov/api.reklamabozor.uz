<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Order\AcceptOfferRequest;
use App\Http\Resources\DirectChatResource;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Services\Chat\DirectChatService;
use App\Services\Order\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly DirectChatService $directChats,
    ) {}

    /**
     * Client accepts an agent's offer for their order.
     *
     * The client must first confirm the three-party contract shown in the accept
     * drawer (`accept_contract`); the consent is logged against that exact
     * document before the deal starts. Acceptance always activates the deal
     * immediately (`in_progress`) — payment is collected separately afterwards
     * (cash or bank transfer, `POST /orders/{order}/pay/offline`), so
     * `payment` in the response is always null.
     */
    public function accept(AcceptOfferRequest $request, Offer $offer): JsonResponse
    {
        $accepted = $this->offers->acceptOffer(
            $request->user(),
            $offer,
            $request->validated('contract_hash'),
            $request,
        );

        return $this->success([
            'offer' => new OfferResource($accepted),
            'payment' => null,
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
