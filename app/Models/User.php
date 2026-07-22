<?php

namespace App\Models;

use App\Enums\AgentProfileStatus;
use App\Enums\LegalEntityStatus;
use App\Enums\PersonType;
use App\Enums\ProviderType;
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
     * Backward-compatible single-profile accessor. Today a user owns at most
     * one provider profile, so this stays valid; context-aware code (offers,
     * chats, reviews) should prefer the row's own `agentProfile` link, and
     * category-scoped bidding should use {@see providerProfileForCategory()}.
     */
    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
    }

    /**
     * All provider profiles this user owns (at most one per provider_type).
     */
    public function providerProfiles(): HasMany
    {
        return $this->hasMany(AgentProfile::class);
    }

    /**
     * Optional legal-entity verification request (self-declared client/designer).
     */
    public function legalEntityVerification(): HasOne
    {
        return $this->hasOne(LegalEntityVerification::class);
    }

    /**
     * The approved provider profile eligible to serve the given category —
     * the one whose category set contains it. Determines which of the user's
     * profiles bids on an order. Null when the user serves no such category.
     */
    public function providerProfileForCategory(int $categoryId): ?AgentProfile
    {
        return $this->providerProfiles()
            ->where('status', AgentProfileStatus::Approved)
            ->whereHas('categories', fn ($query) => $query->where('categories.id', $categoryId))
            ->first();
    }

    /**
     * The profile representing the user where there is no order/offer context
     * (global chat, admin user list): the agency profile if any, else the
     * first provider profile.
     */
    public function primaryProviderProfile(): ?AgentProfile
    {
        $profiles = $this->providerProfiles->all();

        return collect($profiles)->firstWhere('provider_type', ProviderType::Agent)
            ?? ($profiles[0] ?? null);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'agent_id');
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

    /**
     * Whether the user may take on this role under the coexistence matrix
     * ({@see Role::conflictingRoles()}). Client and already-held roles are
     * always allowed (switching); acquiring a new provider role is blocked
     * when it conflicts with one the user already holds.
     */
    public function canAcquireRole(Role $role): bool
    {
        if ($role === Role::Client || $this->hasRole($role)) {
            return true;
        }

        foreach ($role->conflictingRoles() as $conflict) {
            if ($this->hasRole($conflict)) {
                return false;
            }
        }

        return true;
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
        if ($this->hasRoleBoundLegalStatus()) {
            return true;
        }

        return $this->person_type === PersonType::LegalEntity
            && $this->legalEntityVerification?->status === LegalEntityStatus::Approved;
    }

    /**
     * Moderation status of the legal-entity claim, for the LinkedIn-style badge:
     * `approved` for role-bound entities, otherwise the request status (pending /
     * approved / rejected) or null when the user hasn't submitted one.
     */
    public function legalEntityStatus(): ?LegalEntityStatus
    {
        if ($this->hasRoleBoundLegalStatus()) {
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
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
