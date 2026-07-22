<?php

namespace App\Models;

use App\Enums\LegalEntityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalEntityVerification extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'inn',
        'company_name',
        'registration_certificate_file_id',
        'status',
        'rejection_reason',
        'verified_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrationCertificateFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'registration_certificate_file_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LegalEntityStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}
