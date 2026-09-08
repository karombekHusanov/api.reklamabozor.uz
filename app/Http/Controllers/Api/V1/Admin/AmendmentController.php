<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AmendmentStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\RejectAmendmentRequest;
use App\Http\Requests\Api\V1\Admin\SettleAmendmentRefundRequest;
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
            ->with(['order.client', 'offer.agentProfile', 'payment', 'pdfFile', 'contract', 'acceptances', 'initiator'])
            ->latest();

        // Ops shortcut: addenda whose refund still has to be handed back.
        if ($request->boolean('refund_due')) {
            $query->where('refund_state', OrderAmendment::REFUND_DUE);
        } elseif ($request->boolean('expiring')) {
            // Proposals about to run out of time (nobody answered yet).
            $query->where('status', AmendmentStatus::Pending)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDay());
        } elseif (is_string($status) && $status !== '') {
            $query->where('status', $status);
        } elseif (! $request->boolean('all')) {
            // Default queue: pending amendments still awaiting the operator.
            $query->where('status', AmendmentStatus::Pending)
                ->where('requires_operator', true)
                ->whereNull('operator_approved_at');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('order_id', (int) $search);
            });
        }

        $paginator = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

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

    /**
     * Full detail with the audit trail — what the admin timeline renders.
     */
    public function show(OrderAmendment $amendment): JsonResponse
    {
        $amendment->load([
            'order.client', 'offer.agentProfile', 'payment', 'pdfFile',
            'contract', 'acceptances.user', 'events.actor', 'initiator',
        ]);

        return $this->success((new AmendmentResource($amendment))->withEvents());
    }

    /**
     * Record money handed back for an addendum that lowered a paid deal.
     */
    public function refund(SettleAmendmentRefundRequest $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->settleRefund($amendment, $request->user(), $request->validated());

        return $this->success($this->resource($amendment), 'Refund recorded');
    }

    /**
     * The refund will not be paid out (agreed with the client) — recorded.
     */
    public function waiveRefund(SettleAmendmentRefundRequest $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->waiveRefund($amendment, $request->user(), $request->validated('note'));

        return $this->success($this->resource($amendment), 'Refund waived');
    }

    /**
     * Close a proposal nobody answered.
     */
    public function expire(Request $request, OrderAmendment $amendment): JsonResponse
    {
        $amendment = $this->amendments->expire($amendment, $request->user());

        return $this->success($this->resource($amendment), 'Amendment expired');
    }

    public function approve(Request $request, OrderAmendment $amendment): JsonResponse
    {
        // Operator decision — logged as an acceptance like the parties' own.
        $amendment = $this->amendments->approve($request->user(), $amendment, null, $request);

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
            $amendment->load([
                'order.client', 'offer.agentProfile', 'payment', 'pdfFile',
                'contract', 'acceptances', 'events.actor',
            ]),
        );
    }
}
