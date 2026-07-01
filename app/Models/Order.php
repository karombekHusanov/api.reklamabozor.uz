<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\OrderDeadline;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'category_id',
        'title',
        'description',
        'deadline',
        'tz_file_id',
        'attachment_file_ids',
        'budget_min',
        'budget_max',
        'status',
    ];

    /**
     * Eager loads needed to render an order for the client.
     *
     * @var list<string>
     */
    public const CLIENT_RELATIONS = [
        'category',
        'tzFile',
        'offers.agent.agentProfile.companyLogoFile',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tzFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'tz_file_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(OrderView::class);
    }

    public function acceptedOffer(): HasOne
    {
        return $this->hasOne(Offer::class)->where('status', OfferStatus::Accepted);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::New);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByStatus(Builder $query, OrderStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof OrderStatus ? $status : OrderStatus::from($status));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'status' => OrderStatus::class,
            'deadline' => OrderDeadline::class,
            'attachment_file_ids' => 'array',
        ];
    }
}
