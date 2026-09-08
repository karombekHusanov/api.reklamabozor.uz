<?php

namespace App\Http\Resources;

use App\Models\OrderDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An accounting document (act) as the parties download it.
 *
 * @mixin OrderDocument
 */
class OrderDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->type->label(),
            'number' => $this->number,
            'total' => $this->total,
            'pdf_url' => $this->pdfFile?->url(),
            'hash' => $this->hash,
            'generated_at' => $this->generated_at,
        ];
    }
}
