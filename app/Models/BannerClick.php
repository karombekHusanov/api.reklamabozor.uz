<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BannerClick extends Model
{
    /** Only created_at is tracked (append-only event log). */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'banner_id',
        'user_id',
    ];

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
