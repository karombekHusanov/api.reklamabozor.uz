<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'type', 'amount_tiyin', 'reference', 'note', 'created_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount_tiyin' => 'integer',
        ];
    }
}
