<?php

namespace App\Models;

use App\Enums\PaymentMethod;
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
        'method',
        'checkout_url',
        'short_link',
        'invoice_file_id',
        'reference',
        'note',
        'percent',
        'matched_via',
        'confirmed_by',
        'confirmed_at',
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

    /** Manager who confirmed an offline (cash / bank transfer) payment. */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Generated invoice (hisob-faktura) handed to the client. */
    public function invoiceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'invoice_file_id');
    }

    public function isOffline(): bool
    {
        return $this->method->isOffline();
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
            'percent' => 'integer',
            'status' => PaymentStatus::class,
            'purpose' => PaymentPurpose::class,
            'method' => PaymentMethod::class,
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
