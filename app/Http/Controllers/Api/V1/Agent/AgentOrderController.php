<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\OrderRoute;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\PreviewOfferContractRequest;
use App\Http\Requests\Api\V1\Agent\SetOfferPricelistRequest;
use App\Http\Requests\Api\V1\Agent\StoreOfferRequest;
use App\Http\Requests\Api\V1\Agent\UpdateOfferPriceRequest;
use App\Http\Requests\Api\V1\Review\StoreReviewRequest;
use App\Http\Resources\AgentOfferDetailResource;
use App\Http\Resources\AgentOfferResource;
use App\Http\Resources\AgentOrderResource;
use App\Http\Resources\DirectChatResource;
use App\Http\Resources\OfferResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ReviewResource;
use App\Models\Offer;
use App\Models\Order;
use App\Services\Chat\DirectChatService;
use App\Services\Order\OfferService;
use App\Services\Order\OrderService;
use App\Services\Review\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentOrderController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OrderService $orders,
        private readonly ReviewService $reviews,
        private readonly DirectChatService $directChats,
    ) {}

    /**
     * Orders the agent can bid on (open, in their categories). Optional `?route=tender|tezkor`.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route' => ['nullable', Rule::enum(OrderRoute::class)],
        ]);

        $orders = $this->offers->availableForAgent(
            $request->user(),
            route: isset($validated['route']) ? OrderRoute::from($validated['route']) : null,
        );

        return $this->success(AgentOrderResource::collection($orders));
    }

    /**
     * Single open opportunity for the agent (detail / bid page).
     */
    public function showOrder(Request $request, Order $order): JsonResponse
    {
        $order = $this->offers->findAvailableForAgent($request->user(), $order);

        return $this->success(new AgentOrderResource($order));
    }

    /**
     * The agent's own offers across all orders.
     */
    public function myOffers(Request $request): JsonResponse
    {
        $offers = $this->offers->listForAgent($request->user());

        return $this->success(AgentOfferResource::collection($offers));
    }

    /**
     * Single offer belonging to the authenticated agent (detail page).
     */
    public function showOffer(Request $request, Offer $offer): JsonResponse
    {
        $offer = $this->offers->findForAgent($request->user(), $offer);

        return $this->success(new AgentOfferDetailResource($offer));
    }

    /**
     * Open (or return) the direct chat with the order's client from a pending offer.
     */
    public function openOfferChat(Request $request, Offer $offer): JsonResponse
    {
        $chat = $this->directChats->openForOffer($request->user(), $offer);
        $chat->load(['client', 'agent', 'agentProfile', 'order.category', 'lastMessage.attachments']);

        return $this->success(
            (new DirectChatResource($chat))->withActiveOffer(
                $this->directChats->activeOfferForPair($chat),
            ),
        );
    }

    /**
     * Agent pulls back their own pending offer/interest.
     */
    public function withdrawOffer(Request $request, Offer $offer): JsonResponse
    {
        $offer = $this->offers->withdraw($request->user(), $offer);

        return $this->success(new AgentOfferResource($offer), 'Offer withdrawn');
    }

    /**
     * Adjust a pending offer's price (hard cap: Offer::MAX_PRICE_EDITS).
     */
    public function updateOffer(UpdateOfferPriceRequest $request, Offer $offer): JsonResponse
    {
        $offer = $this->offers->updatePrice($request->user(), $offer, $request->validated());

        return $this->success(new AgentOfferDetailResource($offer), 'Offer price updated');
    }

    /**
     * The contract built from the pricelist the agent is composing — shown for
     * confirmation before the offer is sent. Stores nothing.
     */
    public function previewContract(PreviewOfferContractRequest $request, Offer $offer): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->offers->previewContractForAgent(
            $request->user(),
            $offer,
            $validated['items'],
            (int) $validated['deadline_days'],
        ));
    }

    /**
     * Send (or replace) the pricelist on a pending offer — the priced contract
     * step. Requires the agent's acceptance of the contract (logged), after
     * which the offer becomes visible to the client as a priced offer.
     */
    public function setPricelist(SetOfferPricelistRequest $request, Offer $offer): JsonResponse
    {
        $validated = $request->validated();
        $offer = $this->offers->setPricelist(
            $request->user(),
            $offer,
            $validated['items'],
            (int) $validated['deadline_days'],
            $request,
        );

        return $this->success(new AgentOfferDetailResource($offer), 'Pricelist sent');
    }

    /**
     * Submit an offer for an order.
     */
    public function storeOffer(StoreOfferRequest $request, Order $order): JsonResponse
    {
        $offer = $this->offers->submitOffer($request->user(), $order, $request->validated());

        return $this->success(new OfferResource($offer), 'Offer submitted', 201);
    }

    /**
     * The winning agent marks the work as delivered (awaits client confirmation).
     */
    public function submitWork(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->submitWork($request->user(), $order);

        return $this->success(new OrderResource($order), 'Work submitted — waiting for the client to confirm.');
    }

    /**
     * Tezkor: the claiming agent lets go of the request.
     */
    public function release(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->releaseClaim($request->user(), $order, asAgent: true);

        return $this->success([
            'id' => $order->id,
            'status' => $order->status->value,
            'route' => $order->route->value,
            'claimed' => false,
        ], 'Claim released.');
    }

    /**
     * Tezkor: the claiming agent marks the request as agreed — closed as
     * completed, symmetric with the client's own "Kelishildi" action.
     */
    public function close(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->closeAgreed($request->user(), $order, asAgent: true);

        return $this->success([
            'id' => $order->id,
            'status' => $order->status->value,
            'route' => $order->route->value,
            'claimed' => false,
        ], 'Request closed.');
    }

    /**
     * Provider rates the client on their completed order.
     */
    public function storeReview(StoreReviewRequest $request, Order $order): JsonResponse
    {
        $review = $this->reviews->submitProviderReview($request->user(), $order, $request->validated());

        return $this->success(new ReviewResource($review), 'Thank you for your feedback!', 201);
    }
}
