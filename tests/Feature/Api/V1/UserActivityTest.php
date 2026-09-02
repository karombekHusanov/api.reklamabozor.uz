<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserActivityTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->token($user)];
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/me/activity')->assertUnauthorized();
    }

    public function test_client_only_has_null_provider_and_zero_scalars(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->getJson('/api/v1/me/activity', $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.provider', null)
            ->assertJsonPath('data.client.orders_total', 0)
            ->assertJsonPath('data.client.orders_cancelled', 0)
            ->assertJsonPath('data.client.offers_received_pending', 0)
            ->assertJsonPath('data.client.reviews_pending', 0)
            ->assertJsonPath('data.chats.unread_messages', 0)
            ->assertJsonPath('data.chats.unread_threads', 0)
            ->assertJsonPath('data.live_orders.count', 0)
            ->assertJsonPath('data.notifications.unread', 0)
            ->assertJsonPath('data.action_required', 0)
            ->assertJsonPath('data.agent_profile_id', null);
    }

    public function test_provider_with_client_base_role_returns_both_blocks(): void
    {
        $agent = User::factory()->agent()->create([
            'roles' => [Role::Client, Role::Agent],
            'role_selected_at' => now(),
        ]);
        $profile = AgentProfile::factory()->approved()->for($agent, 'user')->create();
        $category = Category::factory()->create();
        $profile->categories()->attach($category);
        AgentPortfolioItem::factory()->for($profile, 'agentProfile')->create();

        Order::factory()->for($agent, 'client')->create(['status' => OrderStatus::New]);

        $response = $this->getJson('/api/v1/me/activity', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.role', 'agent')
            ->assertJsonPath('data.agent_profile_id', $profile->id)
            ->assertJsonPath('data.client.orders_total', 1)
            ->assertJsonPath('data.provider.has_profile', true)
            ->assertJsonPath('data.provider.profile_status', 'approved')
            ->assertJsonPath('data.provider.portfolio_items', 1)
            ->assertJsonPath('data.provider.categories', 1);

        $roles = $response->json('data.roles');
        $this->assertContains('client', $roles);
        $this->assertContains('agent', $roles);
    }

    public function test_role_not_held_returns_422(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        $this->getJson('/api/v1/me/activity?role=agent', $this->auth($client))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_foreign_agent_profile_id_returns_404(): void
    {
        $agent = User::factory()->agent()->create();
        AgentProfile::factory()->approved()->for($agent, 'user')->create();

        $other = User::factory()->agent()->create();
        $foreign = AgentProfile::factory()->approved()->for($other, 'user')->create();

        $this->getJson('/api/v1/me/activity?agent_profile_id='.$foreign->id, $this->auth($agent))
            ->assertNotFound();
    }

    public function test_orders_total_includes_cancelled(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);

        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::Completed]);
        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::Cancelled]);
        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::New]);

        $this->getJson('/api/v1/me/activity', $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.client.orders_total', 3)
            ->assertJsonPath('data.client.orders_cancelled', 1)
            ->assertJsonPath('data.client.orders_completed', 1)
            ->assertJsonPath('data.client.orders_open', 1)
            ->assertJsonPath('data.client.by_status.cancelled', 1)
            ->assertJsonPath('data.client.by_status.completed', 1);
    }

    public function test_unread_excludes_own_messages(): void
    {
        $client = User::factory()->create(['telegram_id' => 1001]);
        $agent = User::factory()->agent()->create(['telegram_id' => 1002]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        $chat = Chat::factory()->forDeal($order, $agent)->create();

        ChatMessage::factory()->for($chat)->create([
            'sender_id' => $client->id,
            'body' => 'from me',
            'read_at' => null,
        ]);
        ChatMessage::factory()->for($chat)->create([
            'sender_id' => $agent->id,
            'body' => 'from them',
            'read_at' => null,
        ]);
        ChatMessage::factory()->for($chat)->create([
            'sender_id' => $agent->id,
            'body' => 'already read',
            'read_at' => now(),
        ]);

        $this->getJson('/api/v1/me/activity', $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.chats.order_unread_messages', 1)
            ->assertJsonPath('data.chats.unread_messages', 1)
            ->assertJsonPath('data.chats.unread_threads', 1);
    }

    public function test_live_orders_excludes_own_cancelled_and_non_open(): void
    {
        $me = User::factory()->create(['role' => Role::Client, 'created_at' => now()->subDay()]);
        $other = User::factory()->create(['role' => Role::Client]);

        // Counted: other user's open-for-offers orders after my signup
        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::New,
            'created_at' => now()->subHour(),
        ]);
        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::OffersSent,
            'created_at' => now()->subMinutes(30),
        ]);

        // Excluded
        Order::factory()->for($me, 'client')->create(['status' => OrderStatus::New]);
        Order::factory()->for($other, 'client')->create(['status' => OrderStatus::Cancelled]);
        Order::factory()->for($other, 'client')->create(['status' => OrderStatus::InProgress]);
        Order::factory()->for($other, 'client')->create(['status' => OrderStatus::Completed]);
        // Before my account existed — ignored on first visit
        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::New,
            'created_at' => now()->subDays(2),
        ]);

        $this->getJson('/api/v1/me/activity', $this->auth($me))
            ->assertOk()
            ->assertJsonPath('data.live_orders.count', 2)
            ->assertJsonPath('data.live_orders.last_seen_at', null);
    }

    public function test_live_orders_respects_last_seen_cursor(): void
    {
        $me = User::factory()->create(['role' => Role::Client, 'created_at' => now()->subDays(3)]);
        $other = User::factory()->create(['role' => Role::Client]);

        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::New,
            'created_at' => now()->subDay(),
        ]);
        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::OffersSent,
            'created_at' => now()->subHour(),
        ]);

        $seen = $this->postJson('/api/v1/me/activity/live-orders/seen', [], $this->auth($me))
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->assertNotEmpty($seen->json('data.last_seen_at'));

        // Still only the older open orders exist — badge clears
        $activity = $this->getJson('/api/v1/me/activity', $this->auth($me))
            ->assertOk()
            ->assertJsonPath('data.live_orders.count', 0);

        $this->assertNotEmpty($activity->json('data.live_orders.last_seen_at'));

        // New open order after mark-seen
        Order::factory()->for($other, 'client')->create([
            'status' => OrderStatus::New,
            'created_at' => now()->addMinute(),
        ]);

        $this->getJson('/api/v1/me/activity', $this->auth($me))
            ->assertOk()
            ->assertJsonPath('data.live_orders.count', 1);
    }

    public function test_admin_returns_null_client_and_provider(): void
    {
        $admin = User::factory()->admin()->create();

        $this->getJson('/api/v1/me/activity', $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.client', null)
            ->assertJsonPath('data.provider', null)
            ->assertJsonPath('data.notifications.unread', 0)
            ->assertJsonPath('data.action_required', 0);
    }

    public function test_client_action_required_rolls_up_pending_items(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $agent = User::factory()->agent()->create();
        AgentProfile::factory()->approved()->for($agent, 'user')->create();

        // work_submitted → awaiting confirmation
        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::WorkSubmitted]);

        // pending offer on open order
        $open = Order::factory()->for($client, 'client')->create(['status' => OrderStatus::OffersSent]);
        Offer::factory()->for($open)->create([
            'agent_id' => $agent->id,
            'status' => OfferStatus::Pending,
        ]);

        // completed without client→provider review
        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::Completed]);

        // awaiting_payment is time-critical (72h auto-cancel) — must count.
        Order::factory()->for($client, 'client')->create(['status' => OrderStatus::AwaitingPayment]);

        $this->getJson('/api/v1/me/activity?role=client', $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.client.orders_awaiting_payment', 1)
            ->assertJsonPath('data.client.orders_awaiting_confirmation', 1)
            ->assertJsonPath('data.client.offers_received_pending', 1)
            ->assertJsonPath('data.client.reviews_pending', 1)
            ->assertJsonPath('data.action_required', 4);
    }

    public function test_agent_without_profile_gets_provider_stub(): void
    {
        $agent = User::factory()->agent()->create([
            'roles' => [Role::Client, Role::Agent],
            'role_selected_at' => now(),
        ]);

        $this->getJson('/api/v1/me/activity?role=agent', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.provider.has_profile', false)
            ->assertJsonPath('data.provider.profile_status', null)
            ->assertJsonPath('data.provider.offers_total', 0)
            ->assertJsonPath('data.agent_profile_id', null);
    }

    public function test_provider_action_required_is_reviews_pending_plus_unread(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $agent = User::factory()->agent()->create();
        AgentProfile::factory()->approved()->for($agent, 'user')->create();

        $order = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create();
        Offer::factory()->for($order)->accepted()->create(['agent_id' => $agent->id]);

        // Client already reviewed; provider has not
        Review::factory()->clientToProvider()->create([
            'order_id' => $order->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'reviewer_id' => $client->id,
            'reviewee_id' => $agent->id,
        ]);

        $chat = Chat::factory()->forDeal(
            Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create(),
            $agent,
        )->create();
        ChatMessage::factory()->for($chat)->create([
            'sender_id' => $client->id,
            'read_at' => null,
        ]);

        $this->getJson('/api/v1/me/activity?role=agent', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.provider.reviews_pending', 1)
            ->assertJsonPath('data.chats.unread_threads', 1)
            ->assertJsonPath('data.action_required', 2);
    }

    public function test_provider_deals_bucket_by_order_status(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $agent = User::factory()->agent()->create();
        AgentProfile::factory()->approved()->for($agent, 'user')->create();

        foreach (
            [
                OrderStatus::InProgress,
                OrderStatus::ClientSelected,
                OrderStatus::AwaitingPayment,
                OrderStatus::WorkSubmitted,
                OrderStatus::Completed,
            ] as $status
        ) {
            $order = Order::factory()->for($client, 'client')->create(['status' => $status]);
            Offer::factory()->for($order)->accepted()->create(['agent_id' => $agent->id]);
        }

        // Pending offer should not count as a deal
        $open = Order::factory()->for($client, 'client')->create(['status' => OrderStatus::OffersSent]);
        Offer::factory()->for($open)->create([
            'agent_id' => $agent->id,
            'status' => OfferStatus::Pending,
        ]);

        $this->getJson('/api/v1/me/activity', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.provider.offers_total', 6)
            ->assertJsonPath('data.provider.offers_pending', 1)
            ->assertJsonPath('data.provider.offers_accepted', 5)
            ->assertJsonPath('data.provider.deals_awaiting_payment', 1)
            ->assertJsonPath('data.provider.deals_in_progress', 2)
            ->assertJsonPath('data.provider.deals_work_submitted', 1)
            ->assertJsonPath('data.provider.deals_completed', 1);
    }

    public function test_scoped_agent_profile_id_must_reference_own_profile(): void
    {
        // 1 user = 1 profile: an explicit agent_profile_id scope may only point
        // to the user's own single profile (it no longer narrows across many).
        $agent = User::factory()->agent()->create([
            'roles' => [Role::Client, Role::Agent],
            'role_selected_at' => now(),
        ]);
        $profile = AgentProfile::factory()->approved()->for($agent, 'user')->create();

        $client = User::factory()->create();
        $orderA = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($orderA)->accepted()->create([
            'agent_id' => $agent->id,
            'agent_profile_id' => $profile->id,
        ]);
        $orderB = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create();
        Offer::factory()->for($orderB)->accepted()->create([
            'agent_id' => $agent->id,
            'agent_profile_id' => $profile->id,
        ]);

        // Scoping to the user's own profile is accepted; counts equal the
        // unscoped view (all of the single profile's offers).
        $this->getJson('/api/v1/me/activity?agent_profile_id='.$profile->id, $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.agent_profile_id', $profile->id)
            ->assertJsonPath('data.provider.offers_accepted', 2)
            ->assertJsonPath('data.provider.deals_in_progress', 1)
            ->assertJsonPath('data.provider.deals_completed', 1);

        // A profile belonging to someone else is rejected.
        $foreign = AgentProfile::factory()->approved()->create();
        $this->getJson('/api/v1/me/activity?agent_profile_id='.$foreign->id, $this->auth($agent))
            ->assertNotFound();
    }
}
