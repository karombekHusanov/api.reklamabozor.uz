<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a problem order's audit trail (see OrderProblemService).
 */
class OrderProblemEvent extends Model
{
    use HasFactory;

    public const FLAGGED = 'flagged';

    public const REFUNDED = 'refunded';

    public const DISMISSED = 'dismissed';

    /** The order reached `completed` through the normal flow while still flagged. */
    public const COMPLETED_WHILE_FLAGGED = 'completed_while_flagged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'actor_id',
        'actor_role',
        'type',
        'payload',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
