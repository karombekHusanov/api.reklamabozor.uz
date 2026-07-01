<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A distinct view of an order by a user (agent). One row per (order, user).
 */
class OrderView extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'user_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
