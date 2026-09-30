<?php

namespace App\Http\Resources;

use App\Enums\OrderDocumentType;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'deadline' => $this->deadline?->value,
            'deadline_from' => $this->deadline_from?->toDateString(),
            'deadline_to' => $this->deadline_to?->toDateString(),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'region' => new RegionResource($this->whenLoaded('region')),
            'district' => new RegionResource($this->whenLoaded('district')),
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            'attachment_file_ids' => $this->allAttachmentFileIds(),
            'attachment_files' => FileResource::collection(
                $this->relationLoaded('attachmentFiles') ? $this->attachmentFiles : [],
            ),
            'budget_min' => $this->budget_min,
            'budget_max' => $this->budget_max,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'location_label' => $this->location_label,
            'status' => $this->status->value,
            // tender | tezkor — fixed at creation.
            'route' => $this->route->value,
            // Tezkor: the agency the client picked (null until "Kelishildi").
            'claim' => $this->when($this->isTezkor(), fn () => $this->claimPayload()),
            // Tezkor: the client may pick one of the pending otkliks.
            'can_close' => $this->isTezkor() && $this->status->isOpenForOffers(),
            // Money runs on its own track: the deal is active from the moment
            // the contract is accepted, the payment may still be outstanding.
            'payment_state' => $this->payment_state?->value,
            'payment_due_at' => $this->payment_due_at,
            'paid_at' => $this->paid_at,
            'activated_at' => $this->activated_at,
            // Client's own cancel affordance (unpaid: any time; paid: inside
            // the cooling-off window).
            'can_cancel' => $this->isCancellableByClient(),
            'cancel_deadline_at' => $this->clientCancelDeadline(),
            // Outstanding balance drives the pay prompts (an applied amendment
            // can put a paid order back in debt).
            'outstanding_som' => round($this->outstandingTiyin() / 100, 2),
            // Who may propose an additional agreement right now, and until when.
            'amendment_window' => [
                'can_propose' => $this->canProposeAmendment($request->user()),
                'reason' => $this->amendmentProposalState($request->user()),
                'ends_at' => $this->amendmentWindowEndsAt(),
            ],
            // Client's legal nature — present when the client is loaded (provider
            // views), for billing context (can they be issued a VAT invoice).
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'person_type' => $this->client->effectivePersonType()?->value,
                'person_type_verified' => $this->client->isVerifiedLegalEntity(),
            ] : null),
            // The single agency this order was directed to, or null for a normal
            // broadcast order (shown to every provider in the category).
            'target_agent' => $this->whenLoaded('targetAgent', fn () => $this->targetAgent ? [
                'id' => $this->targetAgent->id,
                'company_name' => $this->targetAgent->profile?->company_name,
            ] : null),
            'work_submitted_at' => $this->work_submitted_at,
            'completed_at' => $this->completed_at,
            'auto_completed' => $this->auto_completed,
            // "Problem orders" track (quality dispute past its correction
            // window, or agent-never-started) — orthogonal to `status`.
            'problem_state' => $this->problem_state?->value,
            'problem_reason' => $this->problem_reason?->value,
            'problem_flagged_at' => $this->problem_flagged_at,
            'correction_deadline_at' => $this->correction_deadline_at,
            'can_report_no_start' => $this->canReportNoStart(),
            'no_start_report_eligible_at' => $this->noStartReportEligibleAt(),
            // Latest payment attempt (checkout / invoice / offline) so the
            // client can settle or retry. Null when the gateway is off.
            'payment' => $this->whenLoaded(
                'latestPayment',
                fn () => $this->latestPayment ? new PaymentResource($this->latestPayment) : null,
            ),
            'review' => new ReviewResource($this->whenLoaded('review')),
            'provider_review' => new ReviewResource($this->whenLoaded('providerReview')),
            // Per-order service contract (present once the deal started).
            'contract' => $this->whenLoaded(
                'contract',
                fn () => $this->contract ? new ContractResource($this->contract) : null,
            ),
            // Acts closing the order, once it completed. The commission act is
            // between the platform and the agent — never the client's business.
            'documents' => OrderDocumentResource::collection(
                $this->whenLoaded('documents', fn () => $this->documents->where(
                    'type',
                    OrderDocumentType::WorkAct,
                )->values()),
            ),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'offers_count' => $this->whenCounted('offers'),
            'views_count' => $this->whenCounted('views'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimPayload(): ?array
    {
        if (! $this->isClaimed()) {
            return null;
        }

        $agent = $this->relationLoaded('claimedAgent')
            ? $this->claimedAgent
            : $this->claimedAgent()->with(['profile.companyLogoFile', 'profile.cachedRating'])->first();
        $profile = $agent?->profile;
        $rating = $profile?->cachedRating;

        return [
            'agent_id' => $this->claimed_agent_id,
            'claimed_at' => $this->claimed_at,
            'agent' => [
                'first_name' => $agent?->first_name,
                'last_name' => $agent?->last_name,
                'phone' => $agent?->phone,
                'username' => $agent?->username,
                'profile_id' => $profile?->id,
                'company_name' => $profile?->company_name,
                'company_logo' => $profile?->companyLogoFile?->url(),
                'location_label' => $profile?->location_label,
                'stars' => $rating?->stars !== null ? (float) $rating->stars : null,
                'stars_count' => (int) ($rating?->stars_count ?? 0),
                'grade' => $rating?->grade ?? 50,
            ],
        ];
    }
}
