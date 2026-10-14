<?php

namespace App\Models;

use App\Enums\AgentProfileUpgradeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An individual executor's request to be raised to the legal-entity track.
 * Holds the full legal KYC until a manager approves it; approval copies
 * {@see self::KYC_FIELDS} onto the same profile and flips its provider_type.
 */
class AgentProfileUpgrade extends Model
{
    use HasFactory;

    /**
     * The legal-entity KYC columns copied onto the profile on approval — the
     * same set the agent application form collects.
     */
    public const KYC_FIELDS = [
        'company_name',
        'legal_form',
        'inn',
        'legal_address',
        'director_name',
        'director_pinfl',
        'director_position',
        'director_passport',
        'director_passport_file_id',
        'registration_certificate_file_id',
        'bank_name',
        'bank_account',
        'mfo',
        'phone',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agent_profile_id',
        'user_id',
        'status',
        ...self::KYC_FIELDS,
        'offer_version',
        'offer_hash',
        'offer_accepted_at',
        'offer_accepted_ip',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function directorPassportFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'director_passport_file_id');
    }

    public function registrationCertificateFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'registration_certificate_file_id');
    }

    public function isPending(): bool
    {
        return $this->status === AgentProfileUpgradeStatus::Pending;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentProfileUpgradeStatus::class,
            'offer_accepted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
