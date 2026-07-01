<?php

namespace Tests\Feature\Api\V1\Order;

use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string, 2: Category}
     */
    private function approvedAgentInCategory(): array
    {
        $category = Category::factory()->create();
        $agent = User::factory()->create();
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($category);

        return [$agent, $agent->createToken('test')->plainTextToken, $category];
    }

    private function orderInCategory(Category $category): Order
    {
        return Order::factory()->create([
            'category_id' => $category->id,
            'client_id' => User::factory()->create()->id,
        ]);
    }

    public function test_listing_available_orders_records_a_distinct_view(): void
    {
        [, $token, $category] = $this->approvedAgentInCategory();
        $order = $this->orderInCategory($category);

        // First load records the view.
        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])->assertOk();
        // Second load must not double-count.
        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertSame(1, $order->views()->count());
        $this->assertDatabaseCount('order_views', 1);
    }

    public function test_distinct_agents_each_count_once(): void
    {
        [, $tokenA, $category] = $this->approvedAgentInCategory();
        $order = $this->orderInCategory($category);

        // A second approved agent serving the same category.
        $agentB = User::factory()->create();
        AgentProfile::factory()->for($agentB)->approved()->create()->categories()->attach($category);
        $tokenB = $agentB->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$tokenA])->assertOk();
        // Reset the memoized guard user so the second Bearer token re-resolves
        // (the guard caches the resolved user across requests within one test).
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$tokenB])->assertOk();

        $this->assertSame(2, $order->views()->count());
    }

    public function test_client_sees_view_and_offer_counts_on_their_order(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $category = Category::factory()->create();
        $order = Order::factory()->for($client, 'client')->create(['category_id' => $category->id]);

        // Two viewers + two offers.
        $order->views()->create(['user_id' => User::factory()->create()->id]);
        $order->views()->create(['user_id' => User::factory()->create()->id]);
        Offer::factory()->for($order)->count(2)->create();

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.views_count', 2)
            ->assertJsonPath('data.offers_count', 2);

        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.views_count', 2)
            ->assertJsonPath('data.0.offers_count', 2);
    }

    public function test_agent_order_card_exposes_view_and_offer_counts(): void
    {
        [, $token, $category] = $this->approvedAgentInCategory();
        $order = $this->orderInCategory($category);
        Offer::factory()->for($order)->count(3)->create();

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.offers_count', 3)
            ->assertJsonPath('data.0.views_count', 0);
    }
}
