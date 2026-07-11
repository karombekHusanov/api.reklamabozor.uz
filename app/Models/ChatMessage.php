<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChatMessage extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_id',
        'sender_id',
        'body',
        'file_id',
        'read_at',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * Files sent together with this message, in the order they were attached.
     *
     * @return BelongsToMany<File, $this>
     */
    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'chat_message_attachments')
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
