<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        // Foreign category with its own approved provider → not a broadcast order.
        $foreign = Category::factory()->create();
        $otherAgent = User::factory()->create();
        AgentProfile::factory()->for($otherAgent)->approved()->create()
            ->categories()->attach($foreign);
        Order::factory()->for($foreign)->create();

        $relevant = Order::factory()->for($category)->create();

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $relevant->id)
            ->assertJsonPath('data.0.my_offer', null);
    }

    public function test_agent_can_view_open_order_detail(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $order = Order::factory()->for($category)->create([
            'description' => 'Need outdoor ads in Tashkent.',
        ]);

        $this->getJson("/api/v1/agent/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.description', 'Need outdoor ads in Tashkent.')
            ->assertJsonPath('data.my_offer', null)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'description',
                    'client' => ['id', 'first_name', 'avatar'],
                    'attachment_files',
                ],
            ]);
    }

    public function test_agent_cannot_view_order_outside_their_feed(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);

        $foreign = Category::factory()->create();
        $otherAgent = User::factory()->create();
        AgentProfile::factory()->for($otherAgent)->approved()->create()
            ->categories()->attach($foreign);
        $order = Order::factory()->for($foreign)->create();

        $this->getJson("/api/v1/agent/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }

    public function test_agent_order_includes_client_id_and_avatar(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $client = User::factory()->create(['first_name' => 'Aziz']);
        Order::factory()->for($category)->for($client, 'client')->create();

        $response = $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $clientData = $response->json('data.0.client');
        $this->assertSame($client->id, $clientData['id']);
        $this->assertSame('Aziz', $clientData['first_name']);
        $this->assertArrayHasKey('avatar', $clientData);
    }

    public function test_offer_binds_to_the_users_profile_that_serves_the_category(): void
    {
        Http::fake();

        // 1 user = 1 profile (PROFILE_ARCHITECTURE.md). The profile serves the
        // order's category, so the offer binds to it.
        $user = User::factory()->create();
        $designerCategory = Category::factory()->designer()->create();

        $profile = AgentProfile::factory()->for($user)->designer()->approved()->create();
        $profile->categories()->attach($designerCategory);

        $token = $user->createToken('test')->plainTextToken;
        $order = Order::factory()->for($designerCategory)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 1_200_000,
            'comment' => 'Design in a week.',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $this->assertDatabaseHas('offers', [
            'order_id' => $order->id,
            'agent_id' => $user->id,
            'agent_profile_id' => $profile->id,
        ]);
    }

    public function test_agent_can_submit_an_offer(): void
    {
        Http::fake();
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

    public function test_submitting_an_offer_notifies_the_client(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($category);
        $client = User::factory()->create(['telegram_id' => 444000333]);
        $order = Order::factory()->for($category)->for($client, 'client')->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 2_500_000,
            'comment' => 'We can deliver in 2 weeks.',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        // The client hears about the offer: order id, agency name, price, and a
        // deep-link button straight to their order detail page.
        // Company name is HTML-escaped in the Telegram body (e()), so match that.
        $company = e($agent->agentProfile->company_name);
        Http::assertSent(function ($request) use ($order, $company) {
            $text = $request['text'] ?? '';
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return str_contains($request->url(), 'sendMessage')
                && (int) $request['chat_id'] === 444000333
                && str_contains($text, "#{$order->id}")
                && str_contains($text, $company)
                && str_contains($text, '2 500 000')
                && str_contains($text, 'We can deliver in 2 weeks.')
                && $url === "https://app.test/orders/{$order->id}";
        });
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

        // Foreign category that HAS another approved provider → not a broadcast.
        $foreign = Category::factory()->create();
        $otherAgent = User::factory()->create();
        AgentProfile::factory()->for($otherAgent)->approved()->create()
            ->categories()->attach($foreign);

        $foreignOrder = Order::factory()->for($foreign)->create();

        $this->postJson("/api/v1/agent/orders/{$foreignOrder->id}/offers", [
            'price' => 1_000_000,
            'comment' => 'Out of scope.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order']);
    }

    public function test_agent_sees_and_can_offer_on_other_category_orders(): void
    {
        Http::fake();
        $served = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($served);
        $other = Category::query()->where('is_other', true)->where('type', 'agent')->firstOrFail();
        $order = Order::factory()->for($other)->create();

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 1_500_000,
            'comment' => 'We can handle custom work.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('offers', [
            'order_id' => $order->id,
            'agent_id' => $agent->id,
        ]);
    }

    public function test_agent_sees_and_can_offer_on_empty_category_orders(): void
    {
        Http::fake();
        $served = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($served);
        $empty = Category::factory()->create(); // nobody attached
        $order = Order::factory()->for($empty)->create();

        $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [
            'price' => 900_000,
            'comment' => 'Happy to take this on.',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated();
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
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'price',
                        'status',
                        'order' => [
                            'id',
                            'title',
                            'description',
                            'status',
                            'hashtags',
                            'views_count',
                            'offers_count',
                            'client' => ['id', 'first_name', 'avatar'],
                            'created_at',
                        ],
                    ],
                ],
            ]);
    }

    public function test_agent_can_view_own_offer_detail(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedAgent($category);
        $offer = Offer::factory()->for($agent, 'agent')->create([
            'price' => 2_500_000,
            'comment' => 'Full campaign package.',
        ]);

        $this->getJson("/api/v1/agent/offers/{$offer->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $offer->id)
            ->assertJsonPath('data.price', '2500000.00')
            ->assertJsonPath('data.comment', 'Full campaign package.')
            ->assertJsonPath('data.order.id', $offer->order_id)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'price',
                    'comment',
                    'status',
                    'order' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'client' => ['id', 'first_name', 'avatar'],
                        'attachment_files',
                    ],
                ],
            ]);
    }

    public function test_agent_cannot_view_another_agents_offer(): void
    {
        $category = Category::factory()->create();
        [, $token] = $this->approvedAgent($category);
        $foreign = Offer::factory()->create();

        $this->getJson("/api/v1/agent/offers/{$foreign->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }
}
