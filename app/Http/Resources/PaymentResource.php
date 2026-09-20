<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->payment_uuid,
            'purpose' => $this->purpose->value,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount' => $this->amount,          // tiyin
            'amount_som' => $this->amountSom(),  // whole som
            'currency' => $this->currency,
            'invoice_url' => $this->invoiceFile?->url(),
            'reference' => $this->reference,
            'percent' => $this->percent,
            'matched_via' => $this->matched_via,
            'confirmed_at' => $this->confirmed_at,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
