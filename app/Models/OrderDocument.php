<?php

namespace App\Models;

use App\Enums\OrderDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated accounting document (act) for an order. Immutable: the snapshot
 * freezes the requisites and lines as they were when the deal closed.
 */
class OrderDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'offer_id',
        'type',
        'number',
        'total',
        'snapshot',
        'pdf_file_id',
        'hash',
        'version',
        'generated_at',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'pdf_file_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderDocumentType::class,
            'total' => 'decimal:2',
            'snapshot' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
