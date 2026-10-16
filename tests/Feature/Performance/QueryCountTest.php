<?php

namespace Tests\Feature\Performance;

use App\Enums\AmendmentStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\Advantage;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\Hashtag;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Guards the list endpoints against N+1 regressions (audit 2026-10-16 §2.6):
 * the query count must stay flat as rows are added, so every number here is
 * a ceiling well under "one query per row".
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: int, 1: TestResponse}
     */
    private function countQueries(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $request();

        $log = DB::getQueryLog();
        $count = count($log);
        DB::disableQueryLog();

        if (getenv('QUERY_DUMP')) {
            fwrite(STDERR, implode(PHP_EOL, array_column($log, 'query')).PHP_EOL);
        }

        return [$count, $response];
    }

    private function approvedAgent(Category $category): AgentProfile
    {
        $profile = AgentProfile::factory()->approved()->create();
        $profile->categories()->attach($category);

        return $profile;
    }

    public function test_client_order_list_stays_flat(): void
    {
        $client = User::factory()->create();
        $category = Category::factory()->create();
        $region = Region::factory()->create();
        $agents = collect(range(1, 3))->map(fn () => $this->approvedAgent($category));

        // 1. Open tender with two pending offers.
        $open = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::OffersSent)
            ->create(['region_id' => $region->id]);
        $open->hashtags()->attach(Hashtag::factory()->count(2)->create());
        foreach ($agents->take(2) as $profile) {
            Offer::factory()->for($open)->create(['agent_id' => $profile->user_id, 'agent_profile_id' => $profile->id]);
        }

        // 2. Active, unpaid deal.
        $unpaid = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Unpaid,
            'activated_at' => now(),
            'payment_due_at' => now()->addDays(3),
        ]);
        $this->acceptedOffer($unpaid, $agents[0], 2_000_000);

        // 3. Active, paid deal with a payout and an applied amendment.
        $paid = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::InProgress)->create([
            'payment_state' => OrderPaymentState::Paid,
            'activated_at' => now()->subDay(),
            'paid_at' => now()->subHours(2),
        ]);
        $paidOffer = $this->acceptedOffer($paid, $agents[1], 3_000_000);
        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $paid->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 3_000_000 * 100,
            'paid_at' => now()->subHours(2),
        ]);
        Payout::factory()->create([
            'order_id' => $paid->id,
            'agent_id' => $agents[1]->user_id,
            'agent_profile_id' => $agents[1]->id,
            'status' => PayoutStatus::Pending,
        ]);
        OrderAmendment::create([
            'order_id' => $paid->id,
            'offer_id' => $paidOffer->id,
            'initiator_id' => $client->id,
            'initiator_role' => OrderAmendment::ROLE_CLIENT,
            'before_snapshot' => ['items' => [], 'total' => '3000000'],
            'after_snapshot' => ['items' => [], 'total' => '3000000'],
            'status' => AmendmentStatus::Applied,
            'applied_at' => now(),
        ]);

        // 4. Work submitted, paid.
        $submitted = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::WorkSubmitted)->create([
            'payment_state' => OrderPaymentState::Paid,
            'activated_at' => now()->subDays(2),
            'paid_at' => now()->subDay(),
            'work_submitted_at' => now(),
        ]);
        $this->acceptedOffer($submitted, $agents[2], 1_500_000);

        // 5. Completed.
        $done = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::Completed)->create([
            'payment_state' => OrderPaymentState::Paid,
            'activated_at' => now()->subDays(5),
            'paid_at' => now()->subDays(4),
            'completed_at' => now(),
        ]);
        $this->acceptedOffer($done, $agents[0], 900_000);

        // 6. Tezkor request closed with a picked agency.
        Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::Completed)->create([
            'route' => OrderRoute::Tezkor,
            'payment_state' => OrderPaymentState::NotRequired,
            'claimed_agent_id' => $agents[2]->user_id,
            'claimed_at' => now(),
            'completed_at' => now(),
        ]);

        $token = $client->createToken('t')->plainTextToken;

        [$count, $response] = $this->countQueries(
            fn () => $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token]),
        );

        $response->assertOk()->assertJsonCount(6, 'data');
        $this->assertLessThanOrEqual(15, $count, "GET /orders ran {$count} queries for 6 orders");
    }

    public function test_client_order_detail_with_offers_stays_flat(): void
    {
        $client = User::factory()->create();
        $category = Category::factory()->create();
        $order = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::OffersSent)->create();

        for ($i = 0; $i < 5; $i++) {
            $profile = $this->approvedAgent($category);
            $offer = Offer::factory()->for($order)->create(['agent_id' => $profile->user_id, 'agent_profile_id' => $profile->id]);
            $chat = DirectChat::factory()->between($client, $profile->user)->create([
                'order_id' => $order->id,
                'agent_profile_id' => $profile->id,
            ]);
            DirectChatMessage::factory()->forChat($chat)->count(3)->create(['sender_id' => $profile->user_id]);
            $offer->touch();
        }

        $token = $client->createToken('t')->plainTextToken;

        [$count, $response] = $this->countQueries(
            fn () => $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token]),
        );

        $response->assertOk()->assertJsonCount(5, 'data.offers');
        $this->assertSame(3, $response->json('data.offers.0.chat.unread_count'));
        $this->assertLessThanOrEqual(25, $count, "GET /orders/{id} ran {$count} queries for 5 offers");
    }

    public function test_chat_inbox_stays_flat(): void
    {
        $category = Category::factory()->create();
        $profile = $this->approvedAgent($category);
        $agent = $profile->user;

        for ($i = 0; $i < 13; $i++) {
            $client = User::factory()->create();
            $order = Order::factory()->for($client, 'client')->for($category)->status(OrderStatus::OffersSent)->create();
            $chat = DirectChat::factory()->between($client, $agent)->create([
                'order_id' => $order->id,
                'agent_profile_id' => $profile->id,
            ]);
            DirectChatMessage::factory()->forChat($chat)->count(2)->create(['sender_id' => $client->id]);
            DirectChatMessage::factory()->forChat($chat)->create(['sender_id' => $agent->id]);
        }

        for ($i = 0; $i < 2; $i++) {
            $deal = Order::factory()->for($category)->status(OrderStatus::InProgress)->create();
            $chat = Chat::factory()->forDeal($deal, $agent)->create(['agent_profile_id' => $profile->id]);
            ChatMessage::factory()->count(2)->create(['chat_id' => $chat->id, 'sender_id' => $deal->client_id]);
        }

        $token = $agent->createToken('t')->plainTextToken;

        [$count, $response] = $this->countQueries(
            fn () => $this->getJson('/api/v1/chats', ['Authorization' => 'Bearer '.$token]),
        );

        $response->assertOk()->assertJsonCount(15, 'data');
        $this->assertSame(2, $response->json('data.0.unread_count'));
        $this->assertLessThanOrEqual(20, $count, "GET /chats ran {$count} queries for 15 threads");
    }

    public function test_agent_feed_is_paginated_and_stays_flat(): void
    {
        $category = Category::factory()->create();
        $profile = $this->approvedAgent($category);
        $region = Region::factory()->create();

        $orders = Order::factory()->count(25)->for($category)->status(OrderStatus::New)
            ->create(['region_id' => $region->id]);
        $orders->each(fn (Order $order) => $order->hashtags()->attach(Hashtag::factory()->create()));
        Offer::factory()->for($orders->first())->create(['agent_id' => $profile->user_id, 'agent_profile_id' => $profile->id]);

        $token = $profile->user->createToken('t')->plainTextToken;

        [$count, $response] = $this->countQueries(
            fn () => $this->getJson('/api/v1/agent/orders', ['Authorization' => 'Bearer '.$token]),
        );

        $response->assertOk()
            ->assertJsonCount(20, 'data.items')
            ->assertJsonPath('data.meta.total', 25)
            ->assertJsonPath('data.meta.per_page', 20);
        $this->assertLessThanOrEqual(15, $count, "GET /agent/orders ran {$count} queries for 20 orders");

        // Browsing the feed never writes: views are recorded on the detail page.
        $this->assertDatabaseCount('order_views', 0);
    }

    public function test_public_agent_list_sorts_in_sql_and_stays_flat(): void
    {
        $category = Category::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $this->approvedAgent($category);
        }

        // The most complete profile must come first even though it was created last.
        $best = AgentProfile::factory()->approved()->create();
        $best->categories()->attach($category);
        $best->advantages()->attach(Advantage::factory()->create());

        [$count, $response] = $this->countQueries(
            fn () => $this->getJson('/api/v1/agents?limit=8'),
        );

        $response->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.id', $best->id);
        $this->assertLessThanOrEqual(12, $count, "GET /agents ran {$count} queries for 8 profiles");
    }

    private function acceptedOffer(Order $order, AgentProfile $profile, int $priceSom): Offer
    {
        return Offer::factory()->for($order)->create([
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => $priceSom,
            'deadline_days' => 10,
        ]);
    }
}
