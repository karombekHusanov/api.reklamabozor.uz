<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PayoutStatus;
use App\Enums\PayoutTranche;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\ReleasePayoutRequest;
use App\Http\Resources\AdminPayoutResource;
use App\Models\Payout;
use App\Services\Payout\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Finance → Payouts. Managers review the escrow releases owed to agents
 * and mark them paid (v1: manual bank transfer; automated Multicard credit is
 * a later phase). The payout carries the agent's bank requisites for transfer.
 */
class PayoutController extends ApiController
{
    private const RELATIONS = ['agentProfile', 'agent'];

    public function __construct(
        private readonly PayoutService $payouts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Payout::query()->with(self::RELATIONS)->latest();

        if (($status = $request->query('status')) && PayoutStatus::tryFrom((string) $status)) {
            $query->where('status', $status);
        }

        if (($tranche = $request->query('tranche')) && PayoutTranche::tryFrom((string) $tranche)) {
            $query->where('tranche', $tranche);
        }

        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', (int) $orderId);
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => AdminPayoutResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Mark a pending payout as paid (optionally overriding the amount and
     * recording a bank reference).
     */
    public function release(ReleasePayoutRequest $request, Payout $payout): JsonResponse
    {
        if ($payout->status !== PayoutStatus::Pending) {
            return $this->error('Only a pending payout can be released.', 422);
        }

        $payout = $this->payouts->release($payout, $request->user(), $request->validated());

        return $this->success(
            new AdminPayoutResource($payout->load(self::RELATIONS)),
            'Payout released',
        );
    }
}
