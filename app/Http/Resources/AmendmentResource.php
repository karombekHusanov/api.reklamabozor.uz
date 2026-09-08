<?php

namespace App\Http\Resources;

use App\Enums\AmendmentStatus;
use App\Enums\Role;
use App\Models\ContractAcceptance;
use App\Models\OrderAmendment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderAmendment */
class AmendmentResource extends JsonResource
{
    /** Include the audit trail (admin detail view). */
    private bool $withEvents = false;

    public function withEvents(bool $with = true): static
    {
        $this->withEvents = $with;

        return $this;
    }

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
            // Addendum identity: DS number bound to the parent contract.
            'number' => $this->number,
            'sequence' => $this->sequence,
            'contract_number' => $this->contract?->number,
            'document_hash' => $this->document_hash,
            'expires_at' => $this->expires_at,
            'client_window_ends_at' => $this->client_window_ends_at,
            'status' => $this->status->value,
            'initiator_role' => $this->initiator_role,
            // Context for the admin queue (loaded relations only).
            'order' => $this->whenLoaded('order', fn () => $order ? [
                'id' => $order->id,
                'title' => $order->title,
                'status' => $order->status?->value,
                'payment_state' => $order->payment_state?->value,
                'outstanding_som' => round($order->outstandingTiyin() / 100, 2),
                'client' => $order->relationLoaded('client') && $order->client ? [
                    'id' => $order->client->id,
                    'name' => trim($order->client->first_name.' '.($order->client->last_name ?? '')),
                    'phone' => $order->client->phone,
                ] : null,
            ] : null),
            'agent' => $this->whenLoaded('offer', fn () => $offer ? [
                'id' => $offer->agent_id,
                'company_name' => $offer->agentProfile?->company_name,
            ] : null),
            'initiator' => $this->whenLoaded('initiator', fn () => $this->initiator ? [
                'id' => $this->initiator->id,
                'name' => trim($this->initiator->first_name.' '.($this->initiator->last_name ?? '')),
            ] : null),
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
            // Click-wrap trail (who accepted this exact text, and when).
            'acceptances' => [
                'client' => $this->acceptedAt(ContractAcceptance::PARTY_CLIENT),
                'agent' => $this->acceptedAt(ContractAcceptance::PARTY_AGENT),
                'operator' => $this->acceptedAt('operator'),
            ],
            'rejection_reason' => $this->rejection_reason,
            // Money the addendum created: a debt on the order, or a refund the
            // operator hands back (no gateway path for a partial refund).
            'refund' => [
                'state' => $this->refund_state,
                'amount' => $this->refund_amount,
                'method' => $this->refund_method,
                'reference' => $this->refund_reference,
                'note' => $this->refund_note,
                'refunded_at' => $this->refunded_at,
            ],
            'events' => $this->when($this->withEvents, fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'actor_role' => $event->actor_role,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => trim($event->actor->first_name.' '.($event->actor->last_name ?? '')),
                ] : null,
                'payload' => $event->payload,
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at,
            ])->all()),
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
