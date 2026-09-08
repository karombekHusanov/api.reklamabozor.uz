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
 * Admin Finance → Payouts. Managers review the escrow releases owed to agents,
 * transfer the money to the agent's bank account and mark the payout paid — the
 * gateway has no account-payout API, so this is the only way money leaves. The
 * payout carries the agent's bank requisites for the transfer.
 */
class PayoutController extends ApiController
{
    // `order` carries the cancel window that freezes a release.
    private const RELATIONS = ['agentProfile', 'agent', 'order'];

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

        // "Ready to pay": pending and past the client's cooling-off window.
        if ($request->boolean('ready')) {
            $query->releasable();
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
