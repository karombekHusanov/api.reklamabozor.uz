<?php

namespace App\Models;

use App\Enums\IdentityVerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'session_id',
        'state',
        'state_expires_at',
        'myid_reuid',
        'pinfl',
        'verified_full_name',
        'pass_data',
        'comparison_value',
        'failure_code',
        'failure_note',
        'verified_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdentityVerificationStatus::class,
            'comparison_value' => 'float',
            'state_expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
