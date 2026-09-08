<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Order\ApproveAmendmentRequest;
use App\Http\Requests\Api\V1\Order\PreviewAmendmentRequest;
use App\Http\Requests\Api\V1\Order\RejectAmendmentRequest;
use App\Http\Requests\Api\V1\Order\StoreAmendmentRequest;
use App\Http\Resources\AmendmentResource;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Services\Order\AmendmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Additional agreements (Qo'shimcha kelishuv) — proposed by either party on an
 * active deal, then approved by client, agent, and (when flagged) the operator.
 */
class OrderAmendmentController extends ApiController
{
    public function __construct(private readonly AmendmentService $amendments) {}

    /** Amendments on an order — visible to its client, its agent, or an admin. */
    public function index(Request $request, Order $order): JsonResponse
    {
        $this->authorizeParticipant($request, $order);

        $amendments = $order->amendments()
            ->with(['order', 'offer', 'payment', 'pdfFile', 'contract', 'acceptances'])
            ->get();

        return $this->success(AmendmentResource::collection($amendments));
    }

    /**
     * The addendum text for a draft proposal — read before sending it.
     */
    public function preview(PreviewAmendmentRequest $request, Order $order): JsonResponse
    {
        return $this->success(
            $this->amendments->previewDocument($request->user(), $order, $request->validated()),
        );
    }

    /**
     * The stored addendum's text — read before approving it.
     */
    public function document(Request $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment->loadMissing('order');
        $this->authorizeParticipant($request, $amendment->order);

        return $this->success($this->amendments->documentFor($amendment));
    }

    public function store(StoreAmendmentRequest $request, Order $order): JsonResponse
    {
        $amendment = $this->amendments->propose($request->user(), $order, $request->validated(), $request);

        return $this->success(
            new AmendmentResource($amendment->load(['order', 'offer', 'payment', 'pdfFile', 'contract', 'acceptances'])),
            'Amendment proposed',
            201,
        );
    }

    public function approve(ApproveAmendmentRequest $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->approve(
            $request->user(),
            $amendment,
            $request->validated('document_hash'),
            $request,
        );

        return $this->respond($amendment, 'Amendment approved');
    }

    public function reject(RejectAmendmentRequest $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->reject(
            $request->user(),
            $amendment,
            $request->validated()['reason'] ?? null,
        );

        return $this->respond($amendment, 'Amendment rejected');
    }

    public function cancel(Request $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->cancel($request->user(), $amendment);

        return $this->respond($amendment, 'Amendment cancelled');
    }

    private function respond(OrderAmendment $amendment, string $message): JsonResponse
    {
        return $this->success(
            new AmendmentResource($amendment->load(['order', 'offer', 'payment', 'pdfFile', 'contract', 'acceptances'])),
            $message,
        );
    }

    private function authorizeParticipant(Request $request, Order $order): void
    {
        $user = $request->user();
        $order->loadMissing('acceptedOffer');

        $isParticipant = $user->id === $order->client_id
            || $user->id === $order->acceptedOffer?->agent_id
            || $user->role === Role::Admin;

        abort_unless($isParticipant, 403);
    }
}
