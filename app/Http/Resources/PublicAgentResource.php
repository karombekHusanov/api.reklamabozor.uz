<?php

namespace App\Http\Resources;

use App\Models\AgentProfile;
use App\Services\Location\LocationPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Approved agent as shown publicly in the mini app (marketplace / home slider).
 * Exposes only presentation fields — no KYC / contact data.
 *
 * @mixin AgentProfile
 */
class PublicAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'company_name' => $this->company_name,
            // Individual executors: without a studio name the card shows
            // the person's own (live) name and Telegram avatar.
            'display_name' => $this->company_name
                ?: trim($this->user->first_name.' '.($this->user->last_name ?? '')),
            'avatar' => $this->user->avatarFile?->url(),
            'provider_type' => $this->provider_type->value,
            // Capacities offered, derived from category types (agent | designer).
            // Which marketplace lists this profile appears in.
            // Either executor track may serve both. PROFILE_ARCHITECTURE.md §3.
            'capabilities' => $this->servedCapabilities(),
            // Legal nature of the executor, from its KYC track (individual /
            // legal entity).
            'person_type' => $this->user->effectivePersonType()?->value,
            'person_type_verified' => $this->user->isVerifiedLegalEntity(),
            'company_logo' => $this->companyLogoFile?->url(),
            'bio' => $this->bio,
            // Public surface: a 1 km circle and the district + city — never the
            // exact point or the street address.
            ...LocationPrivacy::fields($this->lat, $this->lng, $this->location_label, 'agent:'.$this->id, false, publicArea: true),
            // Distance in metres from the requested point — only set by the "nearby" endpoint.
            'distance_m' => $this->when($this->distance_m !== null, fn () => $this->distance_m),
            'website_url' => $this->website_url,
            'linkedin_url' => $this->linkedin_url,
            'results_text' => $this->results_text,
            'completion_percent' => $this->completionPercent(),
            // "On the platform since" — when the provider profile was created.
            'member_since' => $this->created_at,
            'completed_orders_count' => (int) ($this->completed_orders_count ?? 0),
            // Stars/Grade from the user_ratings cache (falls back to legacy aggregates).
            'stars' => $this->cachedRating?->stars !== null
                ? (float) $this->cachedRating->stars
                : ($this->approved_reviews_avg_rating !== null
                    ? round((float) $this->approved_reviews_avg_rating, 1)
                    : null),
            'stars_count' => $this->cachedRating?->stars_count ?? (int) ($this->approved_reviews_count ?? 0),
            'grade' => $this->cachedRating?->grade ?? 50,
            'listing_boost' => $this->cachedRating?->listing_boost ?? 0,
            // Backward-compat aliases.
            'rating_avg' => $this->cachedRating?->stars_count > 0
                ? (float) $this->cachedRating->stars
                : ($this->approved_reviews_avg_rating !== null
                    ? round((float) $this->approved_reviews_avg_rating, 1)
                    : null),
            'rating_count' => $this->cachedRating?->stars_count ?? (int) ($this->approved_reviews_count ?? 0),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'reviews' => PublicReviewResource::collection($this->whenLoaded('approvedReviews')),
            'advantages' => AdvantageResource::collection($this->whenLoaded('advantages')),
            // Only items not taken down by moderation reach the public payload.
            'portfolio' => $this->whenLoaded('portfolioItems', fn () => AgentPortfolioItemResource::collection(
                $this->portfolioItems->whereNull('hidden_at')->values(),
            )),
            'workflow_steps' => $this->workflow_steps ?? [],
        ];
    }
}
