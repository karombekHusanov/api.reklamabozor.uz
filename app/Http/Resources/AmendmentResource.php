<?php

namespace App\Http\Resources;

use App\Enums\AmendmentStatus;
use App\Enums\Role;
use App\Models\OrderAmendment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderAmendment */
class AmendmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $order = $this->order;
        $offer = $this->offer;

        $isClient = $user !== null && $user->id === $order?->client_id;
        $isAgent = $user !== null && $user->id === $offer?->agent_id;
        $isOperator = $user !== null && $user->role === Role::Admin;
        $isOpen = $this->status === AmendmentStatus::Pending;

        $mySlotFilled = ($isClient && $this->client_approved_at !== null)
            || ($isAgent && $this->agent_approved_at !== null)
            || ($isOperator && $this->operator_approved_at !== null);

        $canApprove = $isOpen && ! $mySlotFilled && (
            $isClient || $isAgent || ($isOperator && $this->requires_operator)
        );

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'status' => $this->status->value,
            'initiator_role' => $this->initiator_role,
            'reason' => $this->reason,
            'before' => $this->before_snapshot,
            'after' => $this->after_snapshot,
            'extra_amount' => $this->extra_amount,
            'requires_operator' => $this->requires_operator,
            'requires_formal_doc' => $this->requires_formal_doc,
            'approvals' => [
                'client' => $this->client_approved_at !== null,
                'agent' => $this->agent_approved_at !== null,
                'operator' => $this->operator_approved_at !== null,
            ],
            'rejection_reason' => $this->rejection_reason,
            'can_approve' => $canApprove,
            'can_cancel' => $isOpen && $user?->id === $this->initiator_id,
            'payment' => $this->when($this->relationLoaded('payment') && $this->payment !== null, fn () => [
                'status' => $this->payment?->status->value,
                'checkout_url' => $this->payment?->checkout_url,
            ]),
            'pdf_url' => $this->pdfFile?->url(),
            'applied_at' => $this->applied_at,
            'created_at' => $this->created_at,
        ];
    }
}
