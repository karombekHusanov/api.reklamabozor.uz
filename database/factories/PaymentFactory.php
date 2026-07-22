<?php

namespace Database\Factories;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_uuid' => (string) Str::uuid(),
            'gateway' => 'multicard',
            'gateway_uuid' => (string) Str::uuid(),
            'purpose' => PaymentPurpose::Order,
            'payable_type' => Order::class,
            'payable_id' => Order::factory(),
            'payer_id' => User::factory(),
            'amount' => fake()->numberBetween(500_000, 50_000_000),
            'currency' => 'UZS',
            'status' => PaymentStatus::Draft,
            'checkout_url' => 'https://app.rhmt.uz/invoice/'.Str::uuid(),
        ];
    }

    public function success(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Success, 'paid_at' => now()]);
    }
}
