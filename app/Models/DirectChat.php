<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One-to-one conversation between a client and an agency.
 *
 * Marketplace DM: order_id IS NULL (one per client↔agent pair).
 * Order-scoped negotiation: order_id set (one per client↔agent↔order).
 */
class DirectChat extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'agent_id',
        'agent_profile_id',
        'order_id',
        'blocked_at',
        'blocked_by',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function blockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DirectChatMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(DirectChatMessage::class)->latestOfMany();
    }

    public function isParticipant(User $user): bool
    {
        return $user->id === $this->client_id || $user->id === $this->agent_id;
    }

    public function otherParticipant(User $user): User
    {
        return $user->id === $this->client_id ? $this->agent : $this->client;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function canWrite(User $user): bool
    {
        return $this->isParticipant($user) && ! $this->isBlocked();
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
        ];
    }
}
