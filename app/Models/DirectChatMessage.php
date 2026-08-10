<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DirectChatMessage extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_OFFER_PRICE_CHANGED = 'offer_price_changed';

    public const TYPE_OFFER_PRICELIST_SENT = 'offer_pricelist_sent';

    public const TYPE_OFFER_ACCEPTED = 'offer_accepted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'direct_chat_id',
        'sender_id',
        'type',
        'body',
        'meta',
        'read_at',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(DirectChat::class, 'direct_chat_id');
    }

    /**
     * @return BelongsToMany<File, $this>
     */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'direct_chat_message_attachments')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isEvent(): bool
    {
        return $this->type !== self::TYPE_TEXT;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
