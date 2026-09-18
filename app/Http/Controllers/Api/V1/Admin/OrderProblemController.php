<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderProblemState;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\DismissOrderProblemRequest;
use App\Http\Requests\Api\V1\Admin\RefundOrderProblemRequest;
use App\Http\Resources\AdminOrderProblemResource;
use App\Models\Order;
use App\Services\Order\OrderProblemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager review of "problem orders" — a quality dispute whose correction
 * window ran out, or a paid deal the agent never started. Each is resolved
 * with a manual refund or dismissed back to the normal deal.
 */
class OrderProblemController extends ApiController
{
    public function __construct(private readonly OrderProblemService $problems) {}

    public function index(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['client', 'acceptedOffer.agentProfile', 'acceptedOffer.agent'])
            ->where('problem_state', '!=', OrderProblemState::None);

        $state = $request->query('problem_state');
        if (is_string($state) && $state !== '') {
            $query->where('problem_state', $state);
        } elseif (! $request->boolean('all')) {
            // Default queue: reports still awaiting a decision.
            $query->where('problem_state', OrderProblemState::Flagged);
        }

        if ($reason = $request->query('reason')) {
            $query->where('problem_reason', $reason);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('id', (int) $search)
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($c) use ($search): void {
                        $c->where('phone', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        $query->orderByDesc('problem_flagged_at');

        $paginator = $query->paginate(min(max((int) $request->query('per_page', 20), 1), 100));

        return $this->success([
            'items' => AdminOrderProblemResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Full detail with the audit trail — order, client, accepted offer/agent,
     * problem events and any past resolutions.
     */
    public function show(Order $order): JsonResponse
    {
        abort_if($order->problem_state === OrderProblemState::None, 404);

        $order->load([
            'client', 'acceptedOffer.agentProfile', 'acceptedOffer.agent',
            'problemEvents.actor', 'problemResolutions.resolvedBy',
        ]);

        return $this->success((new AdminOrderProblemResource($order))->withEvents());
    }

    public function refund(RefundOrderProblemRequest $request, Order $order): JsonResponse
    {
        $this->problems->resolveWithRefund(
            $order,
            $request->user(),
            (int) $request->validated('amount'),
            $request->validated('method'),
            $request->validated('reference'),
            $request->validated('note'),
        );

        return $this->success($this->resource($order->fresh()), 'Refund recorded');
    }

    public function dismiss(DismissOrderProblemRequest $request, Order $order): JsonResponse
    {
        $this->problems->dismiss($order, $request->user(), $request->validated('note'));

        return $this->success($this->resource($order->fresh()), 'Problem dismissed');
    }

    private function resource(Order $order): AdminOrderProblemResource
    {
        return new AdminOrderProblemResource(
            $order->load([
                'client', 'acceptedOffer.agentProfile', 'acceptedOffer.agent',
                'problemEvents.actor', 'problemResolutions.resolvedBy',
            ]),
        )->withEvents();
    }
}
