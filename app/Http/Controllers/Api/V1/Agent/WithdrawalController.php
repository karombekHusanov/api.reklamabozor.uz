<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\ConfirmWithdrawalRequest;
use App\Http\Resources\WithdrawalResource;
use App\Models\Withdrawal;
use App\Services\Payout\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent on-demand cash-out to their card. The agent starts a withdrawal (opens
 * a hosted card form), the mini app polls `show` (which advances the flow once
 * the card is bound), the agent enters the OTP via `confirm`, and may `cancel`
 * before completion.
 *
 * Disabled by default (`payouts.card_withdrawal_enabled`): earnings are paid to
 * the agent's bank account by a manager. The in-flight endpoints stay reachable
 * so a withdrawal started before the switch can still be finished or cancelled.
 */
class WithdrawalController extends ApiController
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (! config('payouts.card_withdrawal_enabled')) {
            return $this->error('Earnings are paid to your bank account — card withdrawals are off.', 422);
        }

        if (! config('services.multicard.enabled')) {
            return $this->error('Withdrawals are not available yet.', 422);
        }

        $withdrawal = $this->withdrawals->start($request->user());

        return $this->success(new WithdrawalResource($withdrawal), 'Withdrawal started', 201);
    }

    /**
     * Current withdrawal state. Advances a card_pending withdrawal to
     * otp_required once the card has been bound (poll target for the mini app).
     */
    public function show(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $this->authorizeOwner($request, $withdrawal);

        $withdrawal = $this->withdrawals->advance($withdrawal, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $this->success(new WithdrawalResource($withdrawal), 'Withdrawal status');
    }

    public function confirm(ConfirmWithdrawalRequest $request, Withdrawal $withdrawal): JsonResponse
    {
        $this->authorizeOwner($request, $withdrawal);

        $withdrawal = $this->withdrawals->confirm($withdrawal, (string) $request->validated('otp'));

        return $this->success(new WithdrawalResource($withdrawal), 'Withdrawal confirmed');
    }

    public function cancel(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $this->authorizeOwner($request, $withdrawal);

        $withdrawal = $this->withdrawals->cancel($withdrawal);

        return $this->success(new WithdrawalResource($withdrawal), 'Withdrawal cancelled');
    }

    private function authorizeOwner(Request $request, Withdrawal $withdrawal): void
    {
        abort_unless($withdrawal->agent_id === $request->user()->id, 404);
    }
}
