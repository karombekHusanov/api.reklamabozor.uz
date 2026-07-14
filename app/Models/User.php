<?php

namespace App\Models;

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

    public function agentProfile(): HasOne
    {
        return $this->hasOne(AgentProfile::class);
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
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
