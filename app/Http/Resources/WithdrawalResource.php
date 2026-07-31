<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A withdrawal as the agent sees it in the mini app. Never exposes the card
 * token (hidden on the model); `form_url` is the hosted page to open, `status`
 * drives the UI (open form → enter OTP → done).
 *
 * @mixin Withdrawal
 */
class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'status' => $this->status->value,
            'amount' => $this->amount,          // tiyin
            'amount_som' => $this->amountSom(), // display
            'currency' => $this->currency,
            'form_url' => $this->form_url,
            'card_pan' => $this->card_pan,
            'ps' => $this->ps,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
