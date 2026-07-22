<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_uuid',
        'gateway',
        'gateway_uuid',
        'purpose',
        'payable_type',
        'payable_id',
        'payer_id',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'card_pan',
        'ps',
        'billing_id',
        'paid_at',
        'refunded_at',
        'meta',
    ];

    /**
     * What this payment is for (Order for now).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Success;
    }

    /** Amount in som (whole units), derived from the stored tiyin. */
    public function amountSom(): float
    {
        return $this->amount / 100;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
