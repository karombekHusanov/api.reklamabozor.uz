<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\OrderDocumentResource;
use App\Models\Order;
use App\Services\Order\OrderActService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The acts closing an order, for whoever is party to it: the client, the agent
 * who did the work, or a manager. Documents are produced at completion; this
 * endpoint also generates the ones an older order never got, so bookkeeping
 * can always be caught up.
 */
class OrderDocumentController extends ApiController
{
    public function __construct(
        private readonly OrderActService $acts,
    ) {}

    public function index(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $isAgent = $order->acceptedOffer()->where('agent_id', $user->id)->exists();

        if ($order->client_id !== $user->id && ! $isAgent && $user->role !== Role::Admin) {
            return $this->error('This order is not yours.', 403);
        }

        $documents = collect($this->acts->generateForOrder($order))
            ->values()
            // The commission act is between the platform and the agent — the
            // client has no business seeing what the agent was charged.
            ->reject(fn ($document): bool => $document->type->value === 'commission_act'
                && $order->client_id === $user->id
                && $user->role !== Role::Admin)
            ->values()
            ->each(fn ($document) => $document->loadMissing('pdfFile'));

        return $this->success([
            'items' => OrderDocumentResource::collection($documents),
        ]);
    }
}
