<?php

namespace App\Http\Resources;

use App\Models\IdentityVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IdentityVerification */
class IdentityVerificationResource extends JsonResource
{
    /**
     * Owner-facing view of a MyID verification. Serves the badge/CTA state plus
     * the government-sourced fields the owner is entitled to see.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'verified_full_name' => $this->verified_full_name,
            'pinfl' => $this->pinfl,
            'pass_data' => $this->pass_data,
            'comparison_value' => $this->comparison_value,
            'failure_code' => $this->failure_code,
            'failure_note' => $this->failure_note,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
