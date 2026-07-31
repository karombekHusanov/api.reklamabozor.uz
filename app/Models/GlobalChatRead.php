<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalChatRead extends Model
{
    protected $fillable = [
        'user_id',
        'last_seen_message_id',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_message_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
