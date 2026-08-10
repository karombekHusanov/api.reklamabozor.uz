<?php

namespace App\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contract */
class ContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'total' => $this->total,
            'pdf_url' => $this->pdfFile?->url(),
            'version' => $this->version,
            'generated_at' => $this->generated_at,
        ];
    }
}
