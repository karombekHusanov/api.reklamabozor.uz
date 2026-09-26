<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferNegotiationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string, 2: AgentProfile, 3: Category}
     */
    private function approvedAgent(?Category $category = null): array
    {
        $category ??= Category::factory()->create();
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return [$user, $user->createToken('test')->plainTextToken, $profile, $category];
    }

    public function test_agent_with_pending_offer_can_open_chat(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/chat", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.can_write', true);

        $this->assertDatabaseHas('direct_chats', [
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'agent_profile_id' => $profile->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_agent_can_open_chat_while_awaiting_payment(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()
            ->for($category)
            ->for($client, 'client')
            ->status(OrderStatus::AwaitingPayment)
            ->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => 5_000_000,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/chat", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.can_write', true);

        $this->getJson("/api/v1/agent/offers/{$offer->id}", [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.can_edit_price', false)
            ->assertJsonStructure(['data' => ['chat' => ['id', 'blocked']]]);

        $this->patchJson("/api/v1/agent/offers/{$offer->id}", [
            'price' => 4_000_000,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_rejected_agent_cannot_initiate_chat(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Rejected,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/chat", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }

    public function test_agent_can_update_pending_offer_price(): void
    {
        Http::fake();
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create(['telegram_id' => 900100200]);
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'price' => 5_000_000,
            'status' => OfferStatus::Pending,
        ]);

        $this->patchJson("/api/v1/agent/offers/{$offer->id}", [
            'price' => 4_500_000,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.price', '4500000.00')
            ->assertJsonPath('data.price_edit_count', 1)
            ->assertJsonPath('data.price_edits_remaining', 4)
            ->assertJsonPath('data.can_edit_price', true);

        $this->assertDatabaseHas('direct_chat_messages', [
            'type' => DirectChatMessage::TYPE_OFFER_PRICE_CHANGED,
        ]);
    }

    public function test_price_edit_after_accept_is_rejected(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::InProgress)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => 5_000_000,
        ]);

        $this->patchJson("/api/v1/agent/offers/{$offer->id}", [
            'price' => 4_000_000,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_price_edit_cap_is_enforced(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
            'price' => 5_000_000,
            'price_edit_count' => Offer::MAX_PRICE_EDITS,
        ]);

        $this->patchJson("/api/v1/agent/offers/{$offer->id}", [
            'price' => 4_000_000,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_blocked_chat_rejects_messages(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
        ]);

        $chatId = $this->actingAs($agent)->postJson("/api/v1/agent/offers/{$offer->id}/chat")
            ->assertOk()
            ->json('data.id');

        $this->actingAs($client)->postJson("/api/v1/direct-chats/{$chatId}/block")
            ->assertOk()
            ->assertJsonPath('data.can_write', false)
            ->assertJsonPath('data.blocked_by', $client->id);

        $this->actingAs($agent)->postJson("/api/v1/direct-chats/{$chatId}/messages", ['body' => 'Hello?'])
            ->assertUnprocessable();

        // Only the blocker can unblock.
        $this->actingAs($agent)->deleteJson("/api/v1/direct-chats/{$chatId}/block")
            ->assertUnprocessable();

        $this->actingAs($client)->deleteJson("/api/v1/direct-chats/{$chatId}/block")
            ->assertOk()
            ->assertJsonPath('data.can_write', true);
    }

    public function test_accept_auto_unblocks_direct_chat(): void
    {
        Http::fake();
        [$agent, , $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $clientToken = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
            'price' => 3_000_000,
        ]);

        $chat = DirectChat::factory()->between($client, $agent)->create([
            'agent_profile_id' => $profile->id,
            'order_id' => $order->id,
            'blocked_at' => now(),
            'blocked_by' => $client->id,
        ]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true], [
            'Authorization' => 'Bearer '.$clientToken,
        ])->assertOk();

        $chat->refresh();
        $this->assertNull($chat->blocked_at);
        $this->assertNull($chat->blocked_by);
        $this->assertDatabaseHas('direct_chat_messages', [
            'direct_chat_id' => $chat->id,
            'type' => DirectChatMessage::TYPE_OFFER_ACCEPTED,
        ]);
    }

    /** After an otklik both sides see each other's phone in the order thread — only while it is live. */
    public function test_both_sides_see_phones_in_order_thread_while_otklik_is_live(): void
    {
        [$agent, $agentToken, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create(['phone' => '+998901112233']);
        $clientToken = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($category)->for($client, 'client')->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
        ]);
        $chat = DirectChat::factory()->between($client, $agent)->create([
            'agent_profile_id' => $profile->id,
            'order_id' => $order->id,
        ]);

        $this->getJson("/api/v1/direct-chats/{$chat->id}", ['Authorization' => 'Bearer '.$agentToken])
            ->assertOk()
            ->assertJsonPath('data.chat.other_participant.phone', '+998901112233');

        // The client sees the agency's business number (profile), not the personal one.
        $profile->update(['phone' => '+998712000000']);
        app('auth')->forgetGuards();
        $this->getJson("/api/v1/direct-chats/{$chat->id}", ['Authorization' => 'Bearer '.$clientToken])
            ->assertOk()
            ->assertJsonPath('data.chat.other_participant.phone', '+998712000000');

        // Withdrawn / released otklik hides both again.
        $offer->update(['status' => OfferStatus::Withdrawn]);
        foreach ([$agentToken, $clientToken] as $token) {
            app('auth')->forgetGuards();
            $this->getJson("/api/v1/direct-chats/{$chat->id}", ['Authorization' => 'Bearer '.$token])
                ->assertOk()
                ->assertJsonPath('data.chat.other_participant.phone', null);
        }
    }

    public function test_marketplace_dm_never_exposes_the_client_phone(): void
    {
        [$agent, $agentToken, $profile] = $this->approvedAgent();
        $client = User::factory()->create(['phone' => '+998901112244']);
        $chat = DirectChat::factory()->between($client, $agent)->create([
            'agent_profile_id' => $profile->id,
            'order_id' => null,
        ]);

        $this->getJson("/api/v1/direct-chats/{$chat->id}", ['Authorization' => 'Bearer '.$agentToken])
            ->assertOk()
            ->assertJsonPath('data.chat.other_participant.phone', null);
    }

    public function test_agent_can_submit_interest_with_empty_body(): void
    {
        Http::fake();
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create(['telegram_id' => 900200300]);
        $order = Order::factory()->for($category)->for($client, 'client')->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertCreated()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.comment', null)
            ->assertJsonPath('data.is_interest', true)
            ->assertJsonPath('data.can_accept', false)
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
        $this->assertDatabaseHas('offers', [
            'order_id' => $order->id,
            'agent_id' => $agent->id,
            'price' => null,
            'comment' => null,
        ]);
        $this->assertDatabaseHas('direct_chats', [
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'order_id' => $order->id,
            'agent_profile_id' => $profile->id,
        ]);
    }

    public function test_accept_interest_is_rejected(): void
    {
        [$agent, , $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $clientToken = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true], [
            'Authorization' => 'Bearer '.$clientToken,
        ])->assertUnprocessable();

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
    }

    public function test_price_edit_on_interest_is_rejected(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->patchJson("/api/v1/agent/offers/{$offer->id}", [
            'price' => 4_000_000,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();

        $this->assertNull($offer->fresh()->price);
    }

    public function test_two_orders_same_pair_get_separate_threads(): void
    {
        [$agent, , $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();

        $orderA = Order::factory()->for($category)->for($client, 'client')->create();
        $orderB = Order::factory()->for($category)->for($client, 'client')->create();

        $offerA = Offer::factory()->interest()->for($orderA)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);
        $offerB = Offer::factory()->interest()->for($orderB)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $chatA = $this->actingAs($agent)->postJson("/api/v1/agent/offers/{$offerA->id}/chat")
            ->assertOk()
            ->json('data.id');

        $chatB = $this->actingAs($client)->postJson("/api/v1/offers/{$offerB->id}/chat")
            ->assertOk()
            ->json('data.id');

        $this->assertNotSame($chatA, $chatB);
        $this->assertDatabaseHas('direct_chats', ['id' => $chatA, 'order_id' => $orderA->id]);
        $this->assertDatabaseHas('direct_chats', ['id' => $chatB, 'order_id' => $orderB->id]);
    }

    public function test_marketplace_dm_stays_separate_from_order_thread(): void
    {
        [$agent, , $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $marketplaceId = $this->actingAs($client)->postJson("/api/v1/agents/{$profile->id}/direct-chat")
            ->assertOk()
            ->json('data.id');

        $orderChatId = $this->actingAs($agent)->postJson("/api/v1/agent/offers/{$offer->id}/chat")
            ->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->json('data.id');

        $this->assertNotSame($marketplaceId, $orderChatId);
        $this->assertDatabaseHas('direct_chats', [
            'id' => $marketplaceId,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'order_id' => null,
        ]);
        $this->assertSame(2, DirectChat::query()->count());
    }

    public function test_duplicate_marketplace_dm_is_blocked(): void
    {
        [, , $profile] = $this->approvedAgent();
        $client = User::factory()->create();

        $this->actingAs($client)->postJson("/api/v1/agents/{$profile->id}/direct-chat")
            ->assertOk();

        $this->actingAs($client)->postJson("/api/v1/agents/{$profile->id}/direct-chat")
            ->assertOk();

        $this->assertSame(1, DirectChat::query()->whereNull('order_id')->count());
    }

    public function test_agent_can_send_pricelist_and_interest_becomes_priced(): void
    {
        Http::fake();
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create(['telegram_id' => 900300400]);
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [
                ['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 2, 'unit_price' => 240_000],
                ['name' => 'Antikrajka', 'quantity' => 1, 'unit_price' => 6_000_000],
            ],
            'deadline_days' => 14,
            'accept_contract' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.is_interest', false)
            ->assertJsonPath('data.price', '6480000.00')
            ->assertJsonPath('data.deadline_days', 14)
            ->assertJsonPath('data.items.0.name', 'Backprint 27x98')
            ->assertJsonPath('data.items.0.line_total', '480000.00')
            ->assertJsonPath('data.items.1.unit', 'dona');

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'price' => 6_480_000, 'deadline_days' => 14]);
        $this->assertSame(2, $offer->items()->count());
        $this->assertDatabaseHas('direct_chat_messages', [
            'type' => DirectChatMessage::TYPE_OFFER_PRICELIST_SENT,
        ]);
    }

    public function test_client_can_accept_after_pricelist(): void
    {
        Http::fake();
        [$agent, , $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'Service', 'quantity' => 1, 'unit_price' => 3_000_000]],
            'deadline_days' => 7,
            'accept_contract' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.can_accept', true);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true])
            ->assertOk();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
    }

    public function test_sending_pricelist_replaces_previous_items(): void
    {
        Http::fake();
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [
                ['name' => 'A', 'quantity' => 1, 'unit_price' => 100_000],
                ['name' => 'B', 'quantity' => 1, 'unit_price' => 200_000],
            ],
            'deadline_days' => 10,
            'accept_contract' => true,
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'C', 'quantity' => 3, 'unit_price' => 50_000]],
            'deadline_days' => 5,
            'accept_contract' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.price', '150000.00')
            ->assertJsonPath('data.deadline_days', 5);

        $this->assertSame(1, $offer->items()->count());
        $this->assertSame('C', $offer->items()->first()->name);
    }

    public function test_pricelist_rejected_when_order_not_open(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::InProgress)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => 5_000_000,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
            'deadline_days' => 7,
            'accept_contract' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_pricelist_requires_deadline_days(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deadline_days');
    }

    public function test_pricelist_requires_at_least_one_item(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable();
    }

    public function test_agent_cannot_set_pricelist_on_others_offer(): void
    {
        [, $token, , $category] = $this->approvedAgent();
        [$other, , $otherProfile] = $this->approvedAgent($category);
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($other, 'agent')->create([
            'agent_profile_id' => $otherProfile->id,
        ]);

        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
            'deadline_days' => 7,
            'accept_contract' => true,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }
}
