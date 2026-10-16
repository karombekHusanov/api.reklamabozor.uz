<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Per-order conversation between the client and the winning agent. Created
 * when an offer is accepted; becomes read-only once the order is terminal.
 */
class Chat extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'client_id',
        'agent_id',
        'agent_profile_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function agentProfile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }

    public function isParticipant(User $user): bool
    {
        return $user->id === $this->client_id || $user->id === $this->agent_id;
    }

    public function otherParticipant(User $user): User
    {
        return $user->id === $this->client_id ? $this->agent : $this->client;
    }

    /**
     * Preload the unread counter for one viewer as `unread_count` (inbox
     * lists), so {@see unreadCountFor()} needs no query per thread.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithUnreadCountFor(Builder $query, User $user): Builder
    {
        return $query->withCount([
            'messages as unread_count' => fn (Builder $q) => $q
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at'),
        ]);
    }

    /**
     * Unread messages for `$user`. Uses the counter preloaded by
     * {@see scopeWithUnreadCountFor()} when present — the list query always
     * loads it for the requesting participant.
     */
    public function unreadCountFor(User $user): int
    {
        if (array_key_exists('unread_count', $this->attributes)) {
            return (int) $this->attributes['unread_count'];
        }

        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}
