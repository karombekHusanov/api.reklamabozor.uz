<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card the user bound at the payment provider (ATMOS bind-card). Only the
 * provider's token (encrypted at rest) and a masked PAN are kept — paying with
 * it needs no card number and no SMS code.
 */
class SavedCard extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'gateway', 'card_id', 'card_token', 'pan_mask', 'last_used_at'];

    /** @var list<string> */
    protected $hidden = ['card_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'card_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }
}
