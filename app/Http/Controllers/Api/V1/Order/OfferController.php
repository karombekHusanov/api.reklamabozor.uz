<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\ApiController;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Services\Order\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends ApiController
{
    public function __construct(
        private readonly OfferService $offers,
    ) {}

    /**
     * Client accepts an agent's offer for their order.
     */
    public function accept(Request $request, Offer $offer): JsonResponse
    {
        $accepted = $this->offers->acceptOffer($request->user(), $offer);

        return $this->success(new OfferResource($accepted), 'Offer accepted');
    }
}
