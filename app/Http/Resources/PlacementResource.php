<?php

namespace App\Http\Resources;

use App\Models\AdPlacement;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Map-list shape of a placement: enough to draw the pin, the cluster and the
 * tap card. Detail (specs, rate card) is {@see PlacementDetailResource}.
 *
 * @mixin AdPlacement
 */
class PlacementResource extends JsonResource
{
    /** Resolved once per request — every placement order lands in the same category. */
    private static ?int $categoryId = null;

    private static bool $categoryResolved = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minDay = $this->minDayPriceTiyin();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'group_key' => $this->group_key,
            'district' => new RegionResource($this->whenLoaded('region')),
            'owner' => [
                'name' => $this->relationLoaded('owner') && $this->owner
                    ? ($this->owner->company_name ?: $this->owner_name_raw)
                    : $this->owner_name_raw,
                'on_platform' => $this->hasPlatformOwner(),
                'agent_profile_id' => $this->hasPlatformOwner() ? $this->owner_agent_profile_id : null,
                // Public contacts as imported — the client reaches the owner directly
                // (in-app placement ordering is off, see placements.ordering_enabled).
                'phones' => $this->owner_phones ?? [],
                'website' => $this->owner_website,
                'email' => $this->owner_email,
            ],
            'has_price' => $this->hasPrice(),
            'min_day_price_tiyin' => $minDay,
            'min_day_price_som' => $minDay === null ? null : intdiv($minDay, 100),
            'audience_tags' => $this->audience_tags ?? [],
            'photo' => $this->photoUrl(),
            // Category a map order is filed under (the mini app preselects it).
            'category_id' => self::defaultCategoryId(),
        ];
    }

    protected function photoUrl(): ?string
    {
        $ids = $this->photo_file_ids ?? [];
        if ($ids === []) {
            return null;
        }

        return File::query()->find($ids[0])?->url();
    }

    protected static function defaultCategoryId(): ?int
    {
        if (! self::$categoryResolved) {
            self::$categoryId = AdPlacement::defaultCategory()?->id;
            self::$categoryResolved = true;
        }

        return self::$categoryId;
    }

    /** Test helper: forget the memoised category between requests. */
    public static function forgetDefaultCategory(): void
    {
        self::$categoryResolved = false;
        self::$categoryId = null;
    }
}
