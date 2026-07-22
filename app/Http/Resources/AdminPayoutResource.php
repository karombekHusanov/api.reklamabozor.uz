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
        $profile = $this->whenLoaded('agentProfile');
        $agent = $this->whenLoaded('agent');

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'tranche' => $this->tranche->value,
            'status' => $this->status->value,
            'method' => $this->method,
            'amount' => $this->amount,          // tiyin
            'amount_som' => $this->amountSom(), // display
            'currency' => $this->currency,
            'reference' => $this->reference,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'agent' => $this->when($profile !== null || $agent !== null, fn (): array => [
                'id' => $this->agent_id,
                'name' => $this->relationLoaded('agent') && $this->agent
                    ? trim($this->agent->first_name.' '.($this->agent->last_name ?? ''))
                    : null,
                'company_name' => $profile->company_name ?? null,
                'inn' => $profile->inn ?? null,
                'bank_name' => $profile->bank_name ?? null,
                'bank_account' => $profile->bank_account ?? null,
                'mfo' => $profile->mfo ?? null,
            ]),
        ];
    }
}
