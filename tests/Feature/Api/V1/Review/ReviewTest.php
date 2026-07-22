<?php

namespace Tests\Feature\Api\V1\Review;

use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A completed order with a winning agent.
     *
     * @return array{0: Order, 1: User, 2: User} [order, client, agent]
     */
    private function completedDeal(): array
    {
        $client = User::factory()->create();
        $agent = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->accepted()->create();

        return [$order, $client, $agent];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_client_can_review_a_completed_order(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        [$order, $client, $agent] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 5,
            'comment' => 'Ajoyib ish!',
        ], ['Authorization' => 'Bearer '.$this->token($client)])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'status' => 'pending',
        ]);

        // Ops group hears about the pending moderation.
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777'
            && str_contains($request['text'] ?? '', 'baho'));
    }

    public function test_review_is_attributed_to_the_winning_provider_profile(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();

        $client = User::factory()->create();
        $agent = User::factory()->create();
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->accepted()->create();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'rating' => 5,
            'comment' => 'Great!',
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertCreated();

        // Anchored to the profile, not just the user.
        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'agent_id' => $agent->id,
            'agent_profile_id' => $profile->id,
        ]);

        // Once moderated, it counts toward THIS profile's public rating
        // (the relation is keyed on agent_profile_id).
        Review::where('order_id', $order->id)->update(['status' => 'approved']);
        $this->assertSame(1, $profile->approvedReviews()->count());
    }

    public function test_review_requires_a_completed_order(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 4], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertUnprocessable();
    }

    public function test_review_can_only_be_left_once_and_only_by_the_owner(): void
    {
        Http::fake();
        [$order, $client, $agent] = $this->completedDeal();
        Review::factory()->create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 3], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertUnprocessable();

        // Drop the cached guard user before switching identity in the same test.
        $this->app['auth']->forgetGuards();

        $stranger = User::factory()->create();
        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 3], [
            'Authorization' => 'Bearer '.$this->token($stranger),
        ])->assertNotFound();
    }

    public function test_rating_is_validated(): void
    {
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", ['rating' => 9], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertJsonValidationErrors(['rating']);
    }

    public function test_admin_can_moderate_reviews(): void
    {
        [$order, $client, $agent] = $this->completedDeal();
        $review = Review::factory()->create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->getJson('/api/v1/admin/reviews?status=pending', [
            'Authorization' => 'Bearer '.$this->token($admin),
        ])
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $review->id);

        $this->patchJson("/api/v1/admin/reviews/{$review->id}/status", ['status' => 'approved'], [
            'Authorization' => 'Bearer '.$this->token($admin),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_non_admin_cannot_moderate(): void
    {
        $review = Review::factory()->create();
        $user = User::factory()->create();

        $this->patchJson("/api/v1/admin/reviews/{$review->id}/status", ['status' => 'approved'], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertForbidden();
    }

    public function test_only_approved_reviews_count_in_public_rating(): void
    {
        $agentUser = User::factory()->create();
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();

        Review::factory()->approved()->create(['agent_id' => $agentUser->id, 'rating' => 5]);
        Review::factory()->approved()->create(['agent_id' => $agentUser->id, 'rating' => 4]);
        Review::factory()->create(['agent_id' => $agentUser->id, 'rating' => 1]); // pending — ignored

        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.rating_avg', 4.5)
            ->assertJsonPath('data.rating_count', 2);
    }

    public function test_completed_order_exposes_the_client_review(): void
    {
        [$order, $client, $agent] = $this->completedDeal();
        Review::factory()->create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'rating' => 4,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonPath('data.review.rating', 4);
    }
}
