<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AmendmentStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Order\RejectAmendmentRequest;
use App\Http\Resources\AmendmentResource;
use App\Models\OrderAmendment;
use App\Services\Order\AmendmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operator review of additional agreements. Only amendments flagged
 * `requires_operator` (deadline change / beyond original pricelist) need it.
 */
class AmendmentController extends ApiController
{
    public function __construct(private readonly AmendmentService $amendments) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $query = OrderAmendment::query()
            ->with(['order.client', 'offer.agentProfile', 'payment', 'pdfFile'])
            ->latest();

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } else {
            // Default queue: pending amendments still awaiting the operator.
            $query->where('status', AmendmentStatus::Pending)
                ->where('requires_operator', true)
                ->whereNull('operator_approved_at');
        }

        $paginator = $query->paginate((int) $request->query('per_page', 20));

        return $this->success([
            'items' => AmendmentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function approve(Request $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->approve($request->user(), $amendment);

        return $this->success($this->resource($amendment), 'Amendment approved');
    }

    public function reject(RejectAmendmentRequest $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->reject(
            $request->user(),
            $amendment,
            $request->validated()['reason'] ?? null,
        );

        return $this->success($this->resource($amendment), 'Amendment rejected');
    }

    private function resource(OrderAmendment $amendment): AmendmentResource
    {
        return new AmendmentResource(
            $amendment->load(['order.client', 'offer.agentProfile', 'payment', 'pdfFile']),
        );
    }
}
