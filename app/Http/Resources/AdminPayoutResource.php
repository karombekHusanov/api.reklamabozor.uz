<?php

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout as the admin Finance module sees it — includes the agent's bank
 * requisites so a manager can execute the transfer and mark it paid.
 *
 * @mixin Payout
 */
class AdminPayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->relationLoaded('agentProfile') ? $this->agentProfile : null;
        $agent = $this->relationLoaded('agent') ? $this->agent : null;

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'tranche' => $this->tranche->value,
            'status' => $this->status->value,
            'method' => $this->method,
            // How the money leaves the platform (bank transfer by a manager).
            'channel' => (string) config('payouts.channel', 'bank'),
            'amount' => $this->amount,          // tiyin
            'amount_som' => $this->amountSom(), // display
            'currency' => $this->currency,
            'reference' => $this->reference,
            // Payouts are frozen while the client may still cancel the order.
            'release_locked' => $this->relationLoaded('order') && $this->order
                ? $this->order->payoutsLocked()
                : false,
            'releasable_at' => $this->relationLoaded('order') && $this->order
                ? $this->order->payoutsUnlockAt()
                : null,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'agent' => $this->when($profile !== null || $agent !== null, fn (): array => [
                'id' => $this->agent_id,
                'name' => $agent
                    ? trim($agent->first_name.' '.($agent->last_name ?? ''))
                    : null,
                'company_name' => $profile->company_name ?? null,
                'inn' => $profile->inn ?? null,
                'bank_name' => $profile->bank_name ?? null,
                'bank_account' => $profile->bank_account ?? null,
                'mfo' => $profile->mfo ?? null,
                // A transfer cannot be recorded against an incomplete set.
                'bank_requisites_complete' => $profile?->hasBankRequisites() ?? false,
            ]),
        ];
    }
}
