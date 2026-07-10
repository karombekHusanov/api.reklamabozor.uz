<?php

namespace App\Models;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentProfile extends Model
{
    use HasFactory;

    /**
     * Eager loads required to render an agent profile (files + categories).
     *
     * @var list<string>
     */
    public const PROFILE_RELATIONS = [
        'categories',
        'companyLogoFile',
        'directorPassportFile',
        'registrationCertificateFile',
    ];

    /**
     * Weights (summing to 100) for the presentation fields that make up the
     * profile-completion percentage shown to approved agents.
     *
     * @var array<string, int>
     */
    private const COMPLETION_WEIGHTS = [
        'logo' => 20,
        'location' => 20,
        'categories' => 20,
        'bio' => 15,
        'results' => 15,
        'links' => 10,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'legal_form',
        'company_logo_file_id',
        'director_name',
        'inn',
        'director_passport',
        'director_passport_file_id',
        'registration_certificate_file_id',
        'bank_name',
        'bank_account',
        'mfo',
        'bio',
        'linkedin_url',
        'website_url',
        'phone',
        'lat',
        'lng',
        'location_label',
        'results_text',
        'status',
        'rejection_reason',
        'approved_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function companyLogoFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'company_logo_file_id');
    }

    public function directorPassportFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'director_passport_file_id');
    }

    public function registrationCertificateFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'registration_certificate_file_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'agent_categories')
            ->withPivot('is_custom');
    }

    /**
     * Accepted offers of this agency that ended in a completed order —
     * i.e. its successfully delivered jobs. Keyed off the owning user
     * (offers.agent_id references the user, not the profile).
     */
    public function completedOrders(): HasMany
    {
        return $this->hasMany(Offer::class, 'agent_id', 'user_id')
            ->where('status', OfferStatus::Accepted)
            ->whereHas('order', fn (Builder $query) => $query->where('status', OrderStatus::Completed));
    }

    /**
     * Moderated client reviews — the only ones that count publicly.
     * Keyed off the owning user (reviews.agent_id references the user).
     */
    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'agent_id', 'user_id')->approved();
    }

    /**
     * Weighted completion of the client-facing presentation fields (0–100).
     */
    public function completionPercent(): int
    {
        $w = self::COMPLETION_WEIGHTS;
        $earned = 0;

        if ($this->company_logo_file_id !== null) {
            $earned += $w['logo'];
        }

        if ($this->lat !== null && $this->lng !== null && filled($this->location_label)) {
            $earned += $w['location'];
        }

        $hasCategories = $this->relationLoaded('categories')
            ? $this->categories->isNotEmpty()
            : $this->categories()->exists();

        if ($hasCategories) {
            $earned += $w['categories'];
        }

        if (filled($this->bio)) {
            $earned += $w['bio'];
        }

        if (filled($this->results_text)) {
            $earned += $w['results'];
        }

        if (filled($this->website_url) || filled($this->linkedin_url)) {
            $earned += $w['links'];
        }

        return $earned;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AgentProfileStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', AgentProfileStatus::Approved);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', AgentProfileStatus::Rejected);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'status' => AgentProfileStatus::class,
            'approved_at' => 'datetime',
        ];
    }
}
