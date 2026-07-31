<?php

namespace App\Http\Resources;

use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout as the earning agent sees it in the mini app: which order it came
 * from, the tranche, the amount, and whether it is still withdrawable
 * (pending), in flight (processing) or already paid.
 *
 * @mixin Payout
 */
class PayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->whenLoaded('order');

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_title' => $order->title ?? null,
            'tranche' => $this->tranche->value,
            'status' => $this->status->value,
            'method' => $this->method,
            'amount' => $this->amount,          // tiyin
            'amount_som' => $this->amountSom(), // display
            'currency' => $this->currency,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
