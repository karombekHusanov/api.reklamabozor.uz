<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OfferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferItem>
 */
class OfferItemFactory extends Factory
{
    protected $model = OfferItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'name' => fake()->words(3, true),
            'unit' => 'dona',
            'quantity' => fake()->numberBetween(1, 20),
            'unit_price' => fake()->numberBetween(50_000, 2_000_000),
            'sort_order' => 0,
        ];
    }
}
