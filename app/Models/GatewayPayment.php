<?php

namespace App\Models;

use App\Enums\GatewayPaymentPurpose;
use App\Enums\GatewayPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Online-gateway payment for platform services (Propusk / wallet top-up).
 * Kept apart from `payments`, which is the order-money ledger.
 */
class GatewayPayment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'user_id', 'purpose', 'amount_tiyin', 'status', 'gateway',
        'gateway_ref', 'checkout_url', 'paid_at', 'meta',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => GatewayPaymentPurpose::class,
            'status' => GatewayPaymentStatus::class,
            'amount_tiyin' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
