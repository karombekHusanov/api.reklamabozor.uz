<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One-to-one conversation between a client and an agency, opened from the
 * agent's public profile — not tied to any order.
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
    ];

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

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}
