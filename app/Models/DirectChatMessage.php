<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DirectChatMessage extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'direct_chat_id',
        'sender_id',
        'body',
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
