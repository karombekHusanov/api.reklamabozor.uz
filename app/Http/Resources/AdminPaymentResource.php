<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class AdminPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payer = $this->payer;

        return [
            'id' => $this->id,
            'uuid' => $this->payment_uuid,
            'gateway' => $this->gateway,
            'gateway_uuid' => $this->gateway_uuid,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'amount_som' => $this->amountSom(),
            'currency' => $this->currency,
            'order_id' => $this->payable_type === Order::class ? $this->payable_id : null,
            'payer' => $payer ? [
                'id' => $payer->id,
                'name' => trim($payer->first_name.' '.($payer->last_name ?? '')),
                'phone' => $payer->phone,
            ] : null,
            'card_pan' => $this->card_pan,
            'ps' => $this->ps,
            'billing_id' => $this->billing_id,
            'paid_at' => $this->paid_at,
            'refunded_at' => $this->refunded_at,
            'created_at' => $this->created_at,
        ];
    }
}
