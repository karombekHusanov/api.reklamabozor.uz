<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_public_client_profile(): void
    {
        $viewer = User::factory()->create();
        $client = User::factory()->create([
            'role' => Role::Client,
            'phone' => '+998901234567',
        ]);

        Order::factory()->for($client, 'client')->count(2)->create([
            'status' => OrderStatus::Completed,
        ]);
        Order::factory()->for($client, 'client')->create([
            'status' => OrderStatus::InProgress,
        ]);

        Review::factory()->create([
            'client_id' => $client->id,
            'rating' => 5,
            'status' => ReviewStatus::Approved,
        ]);

        $this->getJson("/api/v1/clients/{$client->id}", [
            'Authorization' => 'Bearer '.$viewer->createToken('test')->plainTextToken,
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.total_orders', 3)
            ->assertJsonPath('data.in_progress_orders', 1)
            ->assertJsonPath('data.completed_orders', 2)
            ->assertJsonPath('data.rating_count', 1)
            ->assertJsonPath('data.rating_avg', 5);
    }

    public function test_non_client_users_are_not_exposed(): void
    {
        $viewer = User::factory()->create();
        $agent = User::factory()->create(['role' => Role::Agent]);

        $this->getJson("/api/v1/clients/{$agent->id}", [
            'Authorization' => 'Bearer '.$viewer->createToken('test')->plainTextToken,
        ])->assertNotFound();
    }

    public function test_public_client_profile_requires_authentication(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->getJson("/api/v1/clients/{$client->id}")->assertUnauthorized();
    }
}
