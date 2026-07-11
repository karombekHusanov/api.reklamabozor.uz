<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Approved client review as shown on a public agency profile.
 * Exposes only moderated feedback — no client ids or order details.
 *
 * @mixin Review
 */
class PublicReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'created_at' => $this->created_at,
            'client_name' => $this->publicClientName($this->client),
            'client_avatar' => $this->client?->avatarFile?->url(),
        ];
    }

    private function publicClientName(?User $client): string
    {
        if ($client === null) {
            return 'Client';
        }

        $first = filled($client->first_name) ? $client->first_name : 'Client';

        if (! filled($client->last_name)) {
            return $first;
        }

        return trim($first.' '.mb_substr($client->last_name, 0, 1).'.');
    }
}
