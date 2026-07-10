<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-frequency rule: exactly one of role / user_id is set.
 * A user-specific rule always overrides the user's role rule.
 */
class GlobalChatRule extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'user_id',
        'cooldown_seconds',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cooldown_seconds' => 'integer',
        ];
    }
}
