<?php

namespace Tests\Feature\Api\V1\Review;

use App\Enums\OrderStatus;
use App\Enums\ProviderType;
use App\Enums\ReviewDirection;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A completed order with a winning agent (+ profile).
     *
     * @return array{0: Order, 1: User, 2: User, 3: AgentProfile} [order, client, agent, profile]
     */
    private function completedDeal(): array
    {
        $client = User::factory()->create();
        $agent = User::factory()->create();
        $profile = AgentProfile::factory()->for($agent)->approved()->create([
            'provider_type' => ProviderType::Agent,
        ]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->accepted()->create([
            'agent_profile_id' => $profile->id,
        ]);

        return [$order, $client, $agent, $profile];
    }

    private function agentCriteria(): array
    {
        return [
            ['code' => 'result_quality', 'score' => 5],
            ['code' => 'expertise', 'score' => 4],
            ['code' => 'communication', 'score' => 5],
            ['code' => 'deadline', 'score' => 4],
            ['code' => 'value_for_money', 'score' => 3],
            ['code' => 'transparency', 'score' => 5],
        ];
    }

    private function clientCriteria(): array
    {
        return [
            ['code' => 'brief_quality', 'score' => 5],
            ['code' => 'responsiveness', 'score' => 4],
            ['code' => 'payment_reliability', 'score' => 5],
            ['code' => 'cooperation', 'score' => 4],
            ['code' => 'respect', 'score' => 5],
        ];
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_client_can_review_a_completed_order(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        Queue::fake();
        [$order, $client, $agent, $profile] = $this->completedDeal();

        $response = $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => $this->agentCriteria(),
            'comment' => 'Ajoyib ish!',
        ], ['Authorization' => 'Bearer '.$this->token($client)]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.direction', 'client_to_provider')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'direction' => 'client_to_provider',
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'reviewer_id' => $client->id,
            'reviewee_id' => $agent->id,
            'status' => 'pending',
        ]);

        // rating = weighted deal score, not raw integer
        $review = Review::where('order_id', $order->id)->first();
        $this->assertGreaterThan(1, (float) $review->rating);
        $this->assertLessThanOrEqual(5, (float) $review->rating);

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777'
            && str_contains($request['text'] ?? '', 'baho'));
    }

    public function test_provider_can_review_client(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        Queue::fake();
        [$order, $client, $agent] = $this->completedDeal();

        $response = $this->postJson("/api/v1/agent/orders/{$order->id}/review", [
            'criteria' => $this->clientCriteria(),
            'comment' => 'Good client!',
        ], ['Authorization' => 'Bearer '.$this->token($agent)]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.direction', 'provider_to_client');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'direction' => 'provider_to_client',
            'reviewer_id' => $agent->id,
            'reviewee_id' => $client->id,
        ]);
    }

    public function test_both_sides_can_review_same_order(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        Queue::fake();
        [$order, $client, $agent] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => $this->agentCriteria(),
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertCreated();

        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/agent/orders/{$order->id}/review", [
            'criteria' => $this->clientCriteria(),
        ], ['Authorization' => 'Bearer '.$this->token($agent)])->assertCreated();

        $this->assertDatabaseCount('reviews', 2);
    }

    public function test_duplicate_review_same_direction_is_rejected(): void
    {
        Http::fake();
        Queue::fake();
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => $this->agentCriteria(),
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertCreated();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => $this->agentCriteria(),
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertUnprocessable();
    }

    public function test_review_requires_a_completed_order(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => $this->agentCriteria(),
        ], [
            'Authorization' => 'Bearer '.$this->token($client),
        ])->assertUnprocessable();
    }

    public function test_wrong_criteria_codes_are_rejected(): void
    {
        Http::fake();
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => [
                ['code' => 'wrong_code', 'score' => 5],
            ],
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertUnprocessable();
    }

    public function test_partial_criteria_review_is_accepted(): void
    {
        Http::fake();
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => [['code' => 'result_quality', 'score' => 4]],
        ], ['Authorization' => 'Bearer '.$this->token($client)])
            ->assertCreated()
            ->assertJsonPath('data.rating', 4);
    }

    public function test_comment_only_review_is_accepted_without_rating(): void
    {
        Http::fake();
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'comment' => 'Yaxshi ishladi',
        ], ['Authorization' => 'Bearer '.$this->token($client)])
            ->assertCreated()
            ->assertJsonPath('data.rating', null);
    }

    public function test_empty_review_is_rejected(): void
    {
        Http::fake();
        [$order, $client] = $this->completedDeal();

        $this->postJson("/api/v1/orders/{$order->id}/review", [
            'criteria' => [],
            'comment' => '  ',
        ], ['Authorization' => 'Bearer '.$this->token($client)])->assertUnprocessable();
    }

    public function test_review_criteria_endpoint(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/review-criteria?role=agent', [
            'Authorization' => 'Bearer '.$this->token($user),
        ])
            ->assertOk()
            ->assertJsonCount(6, 'data');

        $this->getJson('/api/v1/review-criteria?role=client', [
            'Authorization' => 'Bearer '.$this->token($user),
        ])
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_reviews_index_returns_both_directions(): void
    {
        Http::fake();
        Queue::fake();
        [$order, $client, $agent] = $this->completedDeal();

        Review::factory()->create([
            'order_id' => $order->id,
            'direction' => ReviewDirection::ClientToProvider,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'reviewer_id' => $client->id,
            'reviewee_id' => $agent->id,
        ]);
        Review::factory()->providerToClient()->create([
            'order_id' => $order->id,
            'direction' => ReviewDirection::ProviderToClient,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'reviewer_id' => $agent->id,
            'reviewee_id' => $client->id,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}/reviews", [
            'Authorization' => 'Bearer '.$this->token($client),
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_moderate_reviews(): void
    {
        Queue::fake();
        [$order, $client, $agent] = $this->completedDeal();
        $review = Review::factory()->create([
            'order_id' => $order->id,
            'direction' => ReviewDirection::ClientToProvider,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'reviewer_id' => $client->id,
            'reviewee_id' => $agent->id,
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

    public function test_admin_reviews_filter_by_direction(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        Review::factory()->create(['direction' => ReviewDirection::ClientToProvider]);
        Review::factory()->providerToClient()->create(['direction' => ReviewDirection::ProviderToClient]);

        $this->getJson('/api/v1/admin/reviews?direction=client_to_provider', [
            'Authorization' => 'Bearer '.$this->token($admin),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_non_admin_cannot_moderate(): void
    {
        $review = Review::factory()->create();
        $user = User::factory()->create();

        $this->patchJson("/api/v1/admin/reviews/{$review->id}/status", ['status' => 'approved'], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertForbidden();
    }

    public function test_my_rating_endpoint(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->getJson('/api/v1/me/rating', [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertOk();
    }

    public function test_admin_user_rating_endpoint(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();

        $this->getJson("/api/v1/admin/users/{$user->id}/rating", [
            'Authorization' => 'Bearer '.$this->token($admin),
        ])->assertOk();
    }
}
