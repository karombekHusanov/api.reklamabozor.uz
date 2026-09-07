<?php

namespace App\Models;

use App\Enums\AgentProfileStatus;
use App\Enums\LegalEntityStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PersonType;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_id',
        'phone',
        'first_name',
        'last_name',
        'username',
        'email',
        'password',
        'role',
        'roles',
        'role_selected_at',
        'person_type',
        'person_type_selected_at',
        'accepted_terms_version',
        'accepted_terms_at',
        'avatar_file_id',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function avatarFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_file_id');
    }

    /**
     * The user's single provider profile (1 user = 1 profile). Canonical
     * accessor — new code should use this. See PROFILE_ARCHITECTURE.md.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    /**
     * The profile if it is approved, otherwise null — the common gate for
     * "can this user act as a provider right now".
     */
    public function approvedProfile(): ?AgentProfile
    {
        $profile = $this->profile;

        return $profile?->status === AgentProfileStatus::Approved ? $profile : null;
    }

    /**
     * Optional legal-entity verification request (self-declared client/designer).
     */
    public function legalEntityVerification(): HasOne
    {
        return $this->hasOne(LegalEntityVerification::class);
    }

    /**
     * Escrow releases owed to / paid to this user as a provider (advance /
     * final / adjustment). Drives the agent's earnings + withdrawable balance.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'agent_id');
    }

    /**
     * On-demand cash-outs of this user's escrow balance to their card.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'agent_id');
    }

    /**
     * The approved profile if it serves the given category — the profile that
     * bids on an order in that category. Null when not approved or the profile
     * does not list the category. (1 user = 1 profile.)
     */
    public function providerProfileForCategory(?int $categoryId): ?AgentProfile
    {
        if ($categoryId === null) {
            return null;
        }

        $profile = $this->approvedProfile();

        if ($profile === null) {
            return null;
        }

        $serves = $profile->relationLoaded('categories')
            ? $profile->categories->contains('id', $categoryId)
            : $profile->categories()->whereKey($categoryId)->exists();

        return $serves ? $profile : null;
    }

    /**
     * The approved profile eligible to bid on a broadcast order ("Other" /
     * empty category, or a category no one is approved to serve). Null when the
     * order is not a broadcast or the user has no approved profile.
     */
    public function providerProfileForBroadcastOrder(Order $order): ?AgentProfile
    {
        $order->loadMissing('category');

        // A category-less order is open to every approved provider.
        if ($order->category !== null && ! $order->category->shouldBroadcastToAllProviders()) {
            return null;
        }

        return $this->approvedProfile();
    }

    /**
     * The user's profile where there is no order/offer context (global chat,
     * admin list). (1 user = 1 profile.)
     */
    public function primaryProviderProfile(): ?AgentProfile
    {
        return $this->profile;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'agent_id');
    }

    /**
     * Completed-work counts grouped by capacity — "N done as agent, M as
     * designer". Reads the frozen `orders.category_type` snapshot so history
     * stays stable across category edits (a provider's accepted offer whose
     * order reached `completed`). Keeps the client vs provider sides separate:
     * this is work delivered, not orders the user placed as a client.
     *
     * @return array<string, int> e.g. ['agent' => 12, 'designer' => 5]
     */
    public function completedWorkByCapacity(): array
    {
        return $this->offers()
            ->where('offers.status', OfferStatus::Accepted)
            ->join('orders', 'orders.id', '=', 'offers.order_id')
            ->where('orders.status', OrderStatus::Completed)
            ->whereNotNull('orders.category_type')
            ->selectRaw('orders.category_type as capacity, count(*) as total')
            ->groupBy('orders.category_type')
            ->pluck('total', 'capacity')
            ->map(fn ($total): int => (int) $total)
            ->toArray();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'client_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByTelegramId(Builder $query, int $telegramId): Builder
    {
        return $query->where('telegram_id', $telegramId);
    }

    /**
     * Every role the user holds. `client` is the universal base role — every
     * user can always act as a client, so it is guaranteed to be present for
     * anyone but a pure admin (admins are provisioned out of band). On top of
     * that, a committed active role (role_selected_at set) or admin is always
     * part of the set. `roles` may be null for legacy rows; this normalizes it.
     *
     * @return Collection<int, Role>
     */
    public function allRoles(): Collection
    {
        $roles = collect($this->roles ?? []);

        // A committed active role (or admin) is always part of the set.
        if ($this->role_selected_at !== null || $this->role === Role::Admin) {
            $roles->push($this->role);
        }

        // Client is the base role: held by every non-admin user by default.
        if ($this->role !== Role::Admin) {
            $roles->push(Role::Client);
        }

        return $roles->unique(fn (Role $role): string => $role->value)->values();
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role || $this->allRoles()->contains($role);
    }

    /** The platform public offer version currently in force. */
    public static function currentTermsVersion(): string
    {
        return (string) config('legal.terms_version');
    }

    /**
     * Whether the user has accepted the public offer version currently in force.
     * Admins are provisioned out of band and never gated on it.
     */
    public function hasAcceptedCurrentTerms(): bool
    {
        if ($this->role === Role::Admin) {
            return true;
        }

        return $this->accepted_terms_version === self::currentTermsVersion();
    }

    /** Record acceptance of the current public offer version. */
    public function acceptCurrentTerms(): void
    {
        $this->accepted_terms_version = self::currentTermsVersion();
        $this->accepted_terms_at = now();
        $this->save();
    }

    /**
     * Whether the user's legal nature is fixed by their role: `agent` and
     * `seller` are always legal entities (KYC / bank account), so they never
     * self-declare and are treated as verified.
     */
    public function hasRoleBoundLegalStatus(): bool
    {
        return $this->hasRole(Role::Agent) || $this->hasRole(Role::Seller);
    }

    /**
     * The legal nature that actually applies: derived as `legal_entity` for
     * agents/sellers, otherwise the self-declared value (null until asked).
     * Deriving rather than storing keeps it correct across role changes — e.g.
     * an individual client who becomes a verified agent reads as a legal entity,
     * and reverts to their own choice if that agent role is later removed.
     */
    public function effectivePersonType(): ?PersonType
    {
        return $this->hasRoleBoundLegalStatus() ? PersonType::LegalEntity : $this->person_type;
    }

    /**
     * Whether the effective legal-entity status is confirmed. Role-bound legal
     * entities (agent/seller) are verified out of the box; a self-declared legal
     * entity (client/designer) becomes verified when their verification request
     * is approved.
     */
    public function isVerifiedLegalEntity(): bool
    {
        // Agent: verified ONLY once the provider profile is approved. Holding
        // the (self-selectable) role is not enough — otherwise a user could look
        // verified by merely picking the role, with no KYC.
        if ($this->hasRole(Role::Agent)) {
            return $this->hasApprovedLegalEntityProfile();
        }

        // Seller flow is deferred (PROFILE_ARCHITECTURE.md §8) — unchanged.
        if ($this->hasRole(Role::Seller)) {
            return true;
        }

        return $this->person_type === PersonType::LegalEntity
            && $this->legalEntityVerification?->status === LegalEntityStatus::Approved;
    }

    /**
     * Whether the user has an approved provider profile that is a legal entity
     * (agent). The authority behind role-bound legal-entity verification —
     * PROFILE_ARCHITECTURE.md §3 (Phase 4 will derive this from categories).
     */
    public function hasApprovedLegalEntityProfile(): bool
    {
        return (bool) $this->approvedProfile()?->isLegalEntity();
    }

    /**
     * Moderation status of the legal-entity claim, for the LinkedIn-style badge:
     * `approved` for role-bound entities, otherwise the request status (pending /
     * approved / rejected) or null when the user hasn't submitted one.
     */
    public function legalEntityStatus(): ?LegalEntityStatus
    {
        // Agent: approved once the profile is approved, otherwise still pending
        // verification — never "approved" on the role bit alone.
        if ($this->hasRole(Role::Agent)) {
            return $this->hasApprovedLegalEntityProfile()
                ? LegalEntityStatus::Approved
                : LegalEntityStatus::Pending;
        }

        // Seller flow is deferred (PROFILE_ARCHITECTURE.md §8) — unchanged.
        if ($this->hasRole(Role::Seller)) {
            return LegalEntityStatus::Approved;
        }

        return $this->legalEntityVerification?->status;
    }

    /**
     * Add a role to the held set. Does not touch the active `role` and does
     * not save — the caller decides both.
     */
    public function grantRole(Role $role): void
    {
        $this->roles = $this->allRoles()
            ->push($role)
            ->unique(fn (Role $held): string => $held->value)
            ->values();
    }

    /**
     * Remove a role from the held set; if it was the active role, fall back
     * to the first remaining one (client when nothing remains). The client
     * base role can never be revoked. Does not save.
     */
    public function revokeRole(Role $role): void
    {
        // Client is the universal base role and is never removed.
        if ($role === Role::Client) {
            return;
        }

        $remaining = $this->allRoles()
            ->reject(fn (Role $held): bool => $held === $role)
            ->values();

        if ($remaining->isEmpty()) {
            $remaining = collect([Role::Client]);
        }

        $this->roles = $remaining;

        if ($this->role === $role) {
            $this->role = $remaining->first();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'role' => Role::class,
            'roles' => AsEnumCollection::of(Role::class),
            'role_selected_at' => 'datetime',
            'person_type' => PersonType::class,
            'person_type_selected_at' => 'datetime',
            'accepted_terms_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
