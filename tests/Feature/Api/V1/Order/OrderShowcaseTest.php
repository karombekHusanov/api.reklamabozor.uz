<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\Hashtag;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShowcaseTest extends TestCase
{
    use RefreshDatabase;

    // ── List ────────────────────────────────────────────────────────────

    public function test_showcase_is_public_and_returns_recent_orders_with_counters(): void
    {
        $order = Order::factory()->status(OrderStatus::OffersSent)->create();
        OrderView::create(['order_id' => $order->id, 'user_id' => User::factory()->create()->id]);
        OrderView::create(['order_id' => $order->id, 'user_id' => User::factory()->create()->id]);
        Offer::factory()->for($order)->create();

        $this->getJson('/api/v1/orders/showcase')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.views_count', 2)
            ->assertJsonPath('data.0.offers_count', 1)
            ->assertJsonPath('data.0.category.id', $order->category_id);
    }

    public function test_showcase_list_includes_minimal_client(): void
    {
        $client = User::factory()->create(['first_name' => 'Kamola']);
        Order::factory()->for($client, 'client')->status(OrderStatus::New)->create();

        $response = $this->getJson('/api/v1/orders/showcase')->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayHasKey('client', $row);
        $this->assertSame($client->id, $row['client']['id']);
        $this->assertSame('Kamola', $row['client']['first_name']);
        $this->assertArrayHasKey('avatar', $row['client']);
        $this->assertArrayNotHasKey('attachment_files', $row);
        $this->assertArrayHasKey('attachments_count', $row);
    }

    public function test_showcase_list_includes_attachments_count(): void
    {
        $client = User::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        Order::factory()
            ->for($client, 'client')
            ->status(OrderStatus::New)
            ->create(['attachment_file_ids' => [$file->id]]);

        $row = $this->getJson('/api/v1/orders/showcase')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(1, $row['attachments_count']);
        $this->assertArrayNotHasKey('attachment_files', $row);
    }

    public function test_showcase_hides_cancelled_orders(): void
    {
        Order::factory()->status(OrderStatus::Cancelled)->create();
        $live = Order::factory()->status(OrderStatus::New)->create();

        $this->getJson('/api/v1/orders/showcase')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id);
    }

    public function test_showcase_respects_the_limit(): void
    {
        Order::factory()->count(5)->status(OrderStatus::New)->create();

        $this->getJson('/api/v1/orders/showcase?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_showcase_filters_by_title_search(): void
    {
        Order::factory()->status(OrderStatus::New)->create(['title' => 'LED banner kampaniya']);
        Order::factory()->status(OrderStatus::New)->create(['title' => 'SMM paket']);

        $this->getJson('/api/v1/orders/showcase?q=banner&limit=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'LED banner kampaniya');
    }

    public function test_showcase_filters_by_description_search(): void
    {
        Order::factory()->status(OrderStatus::New)->create([
            'title' => 'Tashqi reklama',
            'description' => 'LED banner kerak Yunusobodda',
        ]);
        Order::factory()->status(OrderStatus::New)->create([
            'title' => 'SMM paket',
            'description' => 'Instagram kontent',
        ]);

        $this->getJson('/api/v1/orders/showcase?q=yunusobod&limit=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Tashqi reklama');
    }

    public function test_showcase_filters_by_category_region_and_date(): void
    {
        $category = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $region = Region::factory()->create();
        $otherRegion = Region::factory()->create();

        $match = Order::factory()->for($category)->status(OrderStatus::New)->create([
            'region_id' => $region->id,
            'created_at' => now()->subDays(2),
        ]);
        Order::factory()->for($otherCategory)->status(OrderStatus::New)->create([
            'region_id' => $region->id,
            'created_at' => now()->subDays(2),
        ]);
        Order::factory()->for($category)->status(OrderStatus::New)->create([
            'region_id' => $otherRegion->id,
            'created_at' => now()->subDays(2),
        ]);
        Order::factory()->for($category)->status(OrderStatus::New)->create([
            'region_id' => $region->id,
            'created_at' => now()->subDays(40),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $this->getJson(
            '/api/v1/orders/showcase?category_ids='.$category->id
            .'&region_id='.$region->id
            .'&created_from='.$from
            .'&created_to='.$to
            .'&limit=20'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_showcase_filters_by_multiple_category_ids(): void
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        $other = Category::factory()->create();

        $a = Order::factory()->for($first)->status(OrderStatus::New)->create();
        $b = Order::factory()->for($second)->status(OrderStatus::New)->create();
        Order::factory()->for($other)->status(OrderStatus::New)->create();

        $ids = $this->getJson(
            '/api/v1/orders/showcase?category_ids='.$first->id.','.$second->id
            .'&limit=20'
        )
            ->assertOk()
            ->json('data.*.id');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_showcase_search_matches_hashtag_label(): void
    {
        $tag = Hashtag::factory()->create([
            'slug' => 'led-ekran',
            'label' => 'LED ekran',
            'is_active' => true,
        ]);
        $withTag = Order::factory()->status(OrderStatus::New)->create(['title' => 'Boshqa title']);
        $withTag->hashtags()->attach($tag->id);
        Order::factory()->status(OrderStatus::New)->create(['title' => 'Oddiy buyurtma']);

        $this->getJson('/api/v1/orders/showcase?q=%23led&limit=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $withTag->id);
    }

    // ── Detail ──────────────────────────────────────────────────────────

    /**
     * Create an approved agent user serving the given category.
     *
     * @return array{0: User, 1: string}
     */
    private function approvedProvider(Category $category): array
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_detail_returns_full_payload_for_authenticated_user(): void
    {
        $client = User::factory()->create(['first_name' => 'Kamola']);
        $order = Order::factory()
            ->for($client, 'client')
            ->status(OrderStatus::New)
            ->create(['description' => str_repeat('A', 300)]);

        $viewer = User::factory()->create();
        $token = $viewer->createToken('test')->plainTextToken;

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $data = $response->json('data');
        $this->assertSame($order->id, $data['id']);
        $this->assertSame(str_repeat('A', 300), $data['description']);
        $this->assertSame($client->id, $data['client']['id']);
        $this->assertSame('Kamola', $data['client']['first_name']);
        $this->assertArrayHasKey('avatar', $data['client']);
        $this->assertArrayHasKey('views_count', $data);
        $this->assertArrayHasKey('offers_count', $data);
        $this->assertArrayHasKey('attachment_files', $data);
        $this->assertNull($data['my_offer']);
        $this->assertArrayNotHasKey('budget_min', $data);
        $this->assertArrayNotHasKey('budget_max', $data);
    }

    public function test_detail_includes_attachment_files_by_default(): void
    {
        $client = User::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $order = Order::factory()
            ->for($client, 'client')
            ->status(OrderStatus::New)
            ->create(['attachment_file_ids' => [$file->id]]);

        $viewer = User::factory()->create();
        $token = $viewer->createToken('test')->plainTextToken;

        $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.attachment_files')
            ->assertJsonPath('data.attachment_files.0.id', $file->id);
    }

    public function test_detail_hides_attachment_files_when_flag_false(): void
    {
        $client = User::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $order = Order::factory()
            ->for($client, 'client')
            ->status(OrderStatus::New)
            ->create([
                'attachment_file_ids' => [$file->id],
                'show_files_in_showcase' => false,
            ]);

        // Even the order owner must not see files on the showcase detail page.
        $token = $client->createToken('test')->plainTextToken;

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertArrayHasKey('attachment_files', $response->json('data'));
        $this->assertSame([], $response->json('data.attachment_files'));
    }

    public function test_detail_can_offer_true_for_approved_matching_provider(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedProvider($category);
        $order = Order::factory()->for($category)->status(OrderStatus::New)->create();

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertTrue($response->json('data.can_offer'));
        $this->assertNull($response->json('data.my_offer'));
    }

    public function test_detail_can_offer_false_for_plain_client(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->status(OrderStatus::New)->create();

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertFalse($response->json('data.can_offer'));
    }

    public function test_detail_my_offer_populated_when_already_bid(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedProvider($category);
        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()
            ->for($order)
            ->for($agent, 'agent')
            ->create(['price' => 3_000_000, 'comment' => 'My bid']);

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $myOffer = $response->json('data.my_offer');
        $this->assertNotNull($myOffer);
        $this->assertSame($offer->id, $myOffer['id']);
        $this->assertEquals(3_000_000, $myOffer['price']);
        $this->assertSame('My bid', $myOffer['comment']);
        $this->assertFalse($response->json('data.can_offer'));
    }

    public function test_detail_cancelled_returns_404(): void
    {
        $order = Order::factory()->status(OrderStatus::Cancelled)->create();
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertNotFound();
    }

    public function test_detail_requires_authentication(): void
    {
        $order = Order::factory()->status(OrderStatus::New)->create();

        $this->getJson("/api/v1/orders/showcase/{$order->id}")
            ->assertUnauthorized();
    }

    public function test_detail_records_view_for_provider_viewing_others_order(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedProvider($category);
        $order = Order::factory()->for($category)->status(OrderStatus::New)->create();

        $this->assertDatabaseCount('order_views', 0);

        $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertDatabaseHas('order_views', [
            'order_id' => $order->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_detail_does_not_record_view_for_order_owner(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::New)->create();

        $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertDatabaseCount('order_views', 0);
    }

    public function test_detail_does_not_record_view_for_non_provider(): void
    {
        $viewer = User::factory()->create();
        $token = $viewer->createToken('test')->plainTextToken;
        $order = Order::factory()->status(OrderStatus::New)->create();

        $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertDatabaseCount('order_views', 0);
    }

    public function test_detail_can_offer_false_when_order_not_open(): void
    {
        $category = Category::factory()->create();
        [$agent, $token] = $this->approvedProvider($category);
        $order = Order::factory()
            ->for($category)
            ->status(OrderStatus::InProgress)
            ->create();

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertFalse($response->json('data.can_offer'));
    }

    public function test_detail_can_offer_false_for_unapproved_provider(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->create(); // pending, not approved
        $token = $user->createToken('test')->plainTextToken;
        $order = Order::factory()->for($category)->status(OrderStatus::New)->create();

        $response = $this->getJson(
            "/api/v1/orders/showcase/{$order->id}",
            ['Authorization' => "Bearer $token"],
        )->assertOk();

        $this->assertFalse($response->json('data.can_offer'));
    }
}
