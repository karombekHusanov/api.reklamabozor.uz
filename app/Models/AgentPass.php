<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPass extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id', 'agent_profile_id', 'starts_at', 'expires_at', 'price_tiyin',
        'source', 'status', 'gateway_payment_id', 'granted_by', 'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gatewayPayment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'price_tiyin' => 'integer',
        ];
    }
}
