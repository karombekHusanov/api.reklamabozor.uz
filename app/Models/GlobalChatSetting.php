<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-row settings for the global chat (row id = 1).
 */
class GlobalChatSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'enabled',
        'max_message_length',
        'pinned_message',
        'pinned_by',
        'pinned_at',
    ];

    public static function current(): self
    {
        // Defaults passed explicitly: a freshly created row would otherwise
        // carry no attributes for the DB-level defaults.
        return self::query()->firstOrCreate(
            ['id' => 1],
            ['enabled' => true, 'max_message_length' => 500],
        );
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_message_length' => 'integer',
            'pinned_at' => 'datetime',
        ];
    }
}
