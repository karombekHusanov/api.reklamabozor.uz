<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an approved agent serving the given category.
     *
     * @return array{0: User, 1: string}
     */
    private function approvedAgent(Category $category): array
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_agent_sees_open_orders_in_their_categories(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);

        Order::factory()->for(Category::factory()->create())->create(); // other category
        $relevant = Order::factory()->for($category)->create();

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $relevant->id)
            ->assertJsonPath('data.0.my_offer', null);
    }

    public function test_agent_can_submit_an_offer(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $order = Order::factory()->for($category)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 2_500_000,
            'comment' => 'We can deliver in 2 weeks.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // First offer flips the order from new → offers_sent.
        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
        $this->assertDatabaseCount('offers', 1);
    }

    public function test_agent_cannot_offer_twice_for_the_same_order(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($category);
        $order = Order::factory()->for($category)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 1_000_000,
            'comment' => 'Second attempt.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_agent_cannot_offer_outside_their_categories(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $foreignOrder = Order::factory()->for(Category::factory()->create())->create();

        $this->postJson("/api/v1/agent/orders/{$foreignOrder->id}/offers", [
            'price' => 1_000_000,
            'comment' => 'Out of scope.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_pending_agent_cannot_submit_offers(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->create(); // pending
        $token = $user->createToken('test')->plainTextToken;
        $order = Order::factory()->for($category)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 1_000_000,
            'comment' => 'Too soon.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_agent_cannot_offer_on_a_closed_order(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $order = Order::factory()->for($category)->status(OrderStatus::InProgress)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 1_000_000,
            'comment' => 'Closed already.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_agent_can_list_their_offers(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($category);
        Offer::factory()->for($agent, 'agent')->count(2)->create();
        Offer::factory()->count(3)->create(); // other agents

        $this->getJson('/api/v1/agent/offers', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
