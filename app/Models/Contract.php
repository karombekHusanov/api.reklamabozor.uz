<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable per-order service contract (client ↔ agent). Requisites + line
 * items are snapshotted; the rendered PDF is stored via {@see pdfFile}.
 */
class Contract extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'offer_id',
        'number',
        'total',
        'client_snapshot',
        'agent_snapshot',
        'items_snapshot',
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
            'total' => 'decimal:2',
            'client_snapshot' => 'array',
            'agent_snapshot' => 'array',
            'items_snapshot' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
