<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class AdminOrderProblemResource extends JsonResource
{
    /** Include the audit trail + resolutions (admin detail view). */
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
        $offer = $this->acceptedOffer;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status?->value,
            'payment_state' => $this->payment_state?->value,
            'problem_state' => $this->problem_state?->value,
            'problem_reason' => $this->problem_reason?->value,
            'problem_flagged_at' => $this->problem_flagged_at,
            'correction_deadline_at' => $this->correction_deadline_at,
            'problem_resolved_at' => $this->problem_resolved_at,
            'activated_at' => $this->activated_at,
            'work_submitted_at' => $this->work_submitted_at,
            'due_som' => round($this->dueTiyin() / 100, 2),
            'paid_som' => round($this->paidTiyin() / 100, 2),
            'outstanding_som' => round($this->outstandingTiyin() / 100, 2),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => trim($this->client->first_name.' '.($this->client->last_name ?? '')),
                'phone' => $this->client->phone,
            ] : null),
            'agent' => $this->whenLoaded('acceptedOffer', fn () => $offer ? [
                'id' => $offer->agent_id,
                'company_name' => $offer->agentProfile?->company_name,
                'name' => $offer->agent
                    ? trim($offer->agent->first_name.' '.($offer->agent->last_name ?? ''))
                    : null,
            ] : null),
            'events' => $this->when($this->withEvents, fn () => $this->problemEvents->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'actor_role' => $event->actor_role,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => trim($event->actor->first_name.' '.($event->actor->last_name ?? '')),
                ] : null,
                'payload' => $event->payload,
                'created_at' => $event->created_at,
            ])->all()),
            'resolutions' => $this->when($this->withEvents, fn () => $this->problemResolutions->map(fn ($resolution) => [
                'id' => $resolution->id,
                'resolution' => $resolution->resolution,
                'refund_amount_som' => $resolution->refund_amount !== null
                    ? round($resolution->refund_amount / 100, 2)
                    : null,
                'refund_method' => $resolution->refund_method,
                'reference' => $resolution->reference,
                'note' => $resolution->note,
                'resolved_by' => $resolution->resolvedBy ? [
                    'id' => $resolution->resolvedBy->id,
                    'name' => trim($resolution->resolvedBy->first_name.' '.($resolution->resolvedBy->last_name ?? '')),
                ] : null,
                'resolved_at' => $resolution->resolved_at,
            ])->all()),
            'created_at' => $this->created_at,
        ];
    }
}
