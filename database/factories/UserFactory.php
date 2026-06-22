<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numerify('##########'),
            'phone' => fake()->phoneNumber(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => null,
            'role' => Role::Client,
            'avatar_file_id' => null,
            'is_active' => true,
        ];
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Agent,
        ]);
    }

    public function designer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Designer,
        ]);
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Seller,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
        ]);
    }
}
