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
            'purpose' => $this->purpose->value,
            'method' => $this->method->value,
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
            'invoice_url' => $this->invoiceFile?->url(),
            'reference' => $this->reference,
            'percent' => $this->percent,
            'matched_via' => $this->matched_via,
            'note' => $this->note,
            'confirmed_at' => $this->confirmed_at,
            'confirmed_by' => $this->confirmedBy ? [
                'id' => $this->confirmedBy->id,
                'name' => trim($this->confirmedBy->first_name.' '.($this->confirmedBy->last_name ?? '')),
            ] : null,
            'paid_at' => $this->paid_at,
            'refunded_at' => $this->refunded_at,
            'refund_source' => is_array($this->meta) ? ($this->meta['refund_source'] ?? null) : null,
            'created_at' => $this->created_at,
        ];
    }
}
