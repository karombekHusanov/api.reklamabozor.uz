<?php

namespace App\Models;

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\CategoryType;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\ProviderType;
use App\Enums\ReviewDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentProfile extends Model
{
    use HasFactory;

    /**
     * Eager loads required to render an agent profile (files + categories).
     *
     * @var list<string>
     */
    public const PROFILE_RELATIONS = [
        'user.avatarFile',
        'categories',
        'advantages',
        'portfolioItems.imageFile',
        'portfolioItems.imageFiles',
        'portfolioItems.attachmentFiles',
        'companyLogoFile',
        'directorPassportFile',
        'registrationCertificateFile',
        'contractFile',
        'signedContractFile',
    ];

    /**
     * Weights (summing to 100) for the presentation fields that make up the
     * profile-completion percentage shown to approved agents.
     *
     * @var array<string, int>
     */
    private const COMPLETION_WEIGHTS = [
        'logo' => 15,
        'location' => 15,
        'categories' => 15,
        'bio' => 10,
        'results' => 10,
        'links' => 5,
        'portfolio' => 20,
        'advantages' => 10,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider_type',
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
        'workflow_steps',
        'status',
        'rejection_reason',
        'approved_at',
        'contract_status',
        'contract_file_id',
        'contract_hash',
        'contract_version',
        'contract_generated_at',
        'signed_contract_file_id',
        'contract_signed_at',
        'contract_rejection_reason',
        'offer_version',
        'offer_hash',
        'offer_accepted_at',
        'offer_accepted_ip',
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

    /** The platform↔agent agreement generated from KYC data. */
    public function contractFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'contract_file_id');
    }

    /** The agent's uploaded signed (wet-signature + stamp) scan. */
    public function signedContractFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signed_contract_file_id');
    }

    /**
     * Only agencies (legal entities) sign the platform agreement; designers
     * (individuals, instant-approved) do not.
     */
    public function requiresContract(): bool
    {
        return $this->isLegalEntity();
    }

    /**
     * Whether this profile is a legal-entity provider. `provider_type` is the
     * profile's legal/KYC track — agent = legal entity (company KYC + contract),
     * designer = individual (light). It is NOT the marketplace capability, which
     * is derived from categories ({@see servedCapabilities()}).
     * PROFILE_ARCHITECTURE.md §3.
     */
    public function isLegalEntity(): bool
    {
        return $this->provider_type === ProviderType::Agent;
    }

    /**
     * The capacities this profile actually offers, derived from the category
     * types it lists (agent | designer). A legal entity may serve both; an
     * individual serves designer only. Drives which marketplace lists it
     * appears in and its capacity stats.
     *
     * @return list<string>
     */
    public function servedCapabilities(): array
    {
        $types = $this->relationLoaded('categories')
            ? $this->categories->pluck('type')
            : $this->categories()->pluck('type');

        return $types
            ->map(fn ($type): string => $type instanceof CategoryType ? $type->value : (string) $type)
            ->unique()
            ->values()
            ->all();
    }

    /** Whether the signed agreement is approved (or not required at all). */
    /**
     * Whether the agency partnership offer (current version) has been
     * accepted. Only legal-entity (agent) profiles are bound by it.
     */
    public function hasAcceptedCurrentOffer(): bool
    {
        return ! $this->requiresContract()
            || $this->offer_version === (string) config('legal.agent_offer_version');
    }

    public function contractApproved(): bool
    {
        return ! $this->requiresContract()
            || $this->contract_status === AgentContractStatus::Approved;
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'agent_categories')
            ->withPivot('is_custom');
    }

    /**
     * Accepted offers of this profile that ended in a completed order —
     * i.e. its successfully delivered jobs. Keyed on agent_profile_id so a
     * user's separate provider profiles keep independent histories.
     */
    public function completedOrders(): HasMany
    {
        return $this->hasMany(Offer::class, 'agent_profile_id')
            ->where('status', OfferStatus::Accepted)
            ->whereHas('order', fn (Builder $query) => $query->where('status', OrderStatus::Completed));
    }

    /**
     * Moderated client→provider reviews — the only ones that count publicly.
     * Keyed on agent_profile_id (this profile's reputation only).
     * Excludes provider→client reviews that also store agent_profile_id.
     */
    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'agent_profile_id')
            ->where('direction', ReviewDirection::ClientToProvider)
            ->approved();
    }

    /**
     * Advantages the provider picked from the admin-managed catalog.
     */
    public function advantages(): BelongsToMany
    {
        return $this->belongsToMany(Advantage::class, 'agent_profile_advantage');
    }

    public function portfolioItems(): HasMany
    {
        return $this->hasMany(AgentPortfolioItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Cached Stars/Grade row for this profile.
     * One row per profile_id (role matched to provider_type at recompute time).
     */
    public function cachedRating(): HasOne
    {
        return $this->hasOne(UserRating::class, 'agent_profile_id');
    }

    /**
     * Weighted completion of the client-facing presentation fields (0–100).
     */
    /**
     * Whether the KYC requisites carry everything a bank transfer needs. Agent
     * earnings are paid to this account, so an incomplete set blocks a release.
     */
    public function hasBankRequisites(): bool
    {
        return filled($this->bank_name) && filled($this->bank_account) && filled($this->mfo);
    }

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

        $hasPortfolio = $this->relationLoaded('portfolioItems')
            ? $this->portfolioItems->whereNull('hidden_at')->isNotEmpty()
            : $this->portfolioItems()->visible()->exists();

        if ($hasPortfolio) {
            $earned += $w['portfolio'];
        }

        $hasAdvantages = $this->relationLoaded('advantages')
            ? $this->advantages->isNotEmpty()
            : $this->advantages()->exists();

        if ($hasAdvantages) {
            $earned += $w['advantages'];
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
            'provider_type' => ProviderType::class,
            'status' => AgentProfileStatus::class,
            'approved_at' => 'datetime',
            'workflow_steps' => 'array',
            'contract_status' => AgentContractStatus::class,
            'contract_generated_at' => 'datetime',
            'contract_signed_at' => 'datetime',
            'offer_accepted_at' => 'datetime',
        ];
    }
}
