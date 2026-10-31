<?php

namespace Tests\Feature\Api\V1\Placement;

use App\Enums\AdPlacementStatus;
use App\Enums\OrderRoute;
use App\Jobs\SendNewOrderNotification;
use App\Models\AdPlacement;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Orders built from screens picked on the map: Tender machinery without the
 * per-account gate, "LED ekran" category, snapshots, and routing (directed to
 * the owning agency when all screens are theirs, broadcast otherwise).
 */
class PlacementOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Map ordering is off by default (business model pending) — these tests
        // cover the machinery behind the flag.
        config(['placements.ordering_enabled' => true]);
    }

    public function test_placements_are_rejected_while_map_ordering_is_off(): void
    {
        config(['placements.ordering_enabled' => false]);
        $client = User::factory()->create();
        $placement = AdPlacement::factory()->create();

        $this->postJson('/api/v1/orders', [
            'description' => 'LED reklama, 2 hafta davomida',
            'placements' => [$placement->id],
        ], $this->auth($client))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['placements']);

        $this->assertSame(0, Order::count());
    }

    private function auth(User $user): array
    {
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function ledCategory(): Category
    {
        return AdPlacement::defaultCategory(true);
    }

    private function approvedAgent(Category $category): User
    {
        $user = User::factory()->create(['telegram_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999)]);
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return $user;
    }

    public function test_ordinary_client_creates_a_placement_order_on_tender_without_the_gate(): void
    {
        Http::fake();
        Queue::fake();
        $client = User::factory()->create();
        $this->assertFalse($client->canCreateTender());

        [$a, $b] = AdPlacement::factory()->count(2)->create(['lat' => 41.30, 'lng' => 69.28])->all();

        $response = $this->postJson('/api/v1/orders', [
            'description' => 'Yangi kafe uchun LED reklama, 2 hafta',
            'placements' => [$b->id, $a->id],
            'deadline_from' => now()->addDay()->toDateString(),
            'deadline_to' => now()->addDays(14)->toDateString(),
            'budget' => 5_000_000,
        ], $this->auth($client));

        $response->assertCreated()
            ->assertJsonPath('data.route', OrderRoute::Tender->value)
            ->assertJsonPath('data.category.id', $this->ledCategory()->id)
            ->assertJsonCount(2, 'data.placements')
            // Order of selection is kept.
            ->assertJsonPath('data.placements.0.placement_id', $b->id)
            ->assertJsonPath('data.placements.0.address', $b->address)
            ->assertJsonPath('data.placements.0.owner_name', $b->owner_name_raw)
            ->assertJsonPath('data.placements.1.placement_id', $a->id)
            ->assertJsonPath('data.target_agent', null);

        $order = Order::query()->firstOrFail();
        $this->assertNotNull($order->lat, 'no pin sent → centred on the screens');
        $this->assertEqualsWithDelta(41.30, (float) $order->lat, 0.001);
        $this->assertNotNull($order->payment_state);
        $this->assertSame(2, $order->placements()->count());
        $this->assertSame(now()->addDay()->toDateString(), $order->placements()->first()->date_from?->toDateString());
    }

    public function test_tezkor_requested_with_placements_is_forced_to_tender(): void
    {
        Http::fake();
        Queue::fake();
        $client = User::factory()->create();
        $placement = AdPlacement::factory()->create();

        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'route' => 'tezkor',
            'placements' => [$placement->id],
            'lat' => 41.31,
            'lng' => 69.28,
        ], $this->auth($client))
            ->assertCreated()
            ->assertJsonPath('data.route', 'tender')
            ->assertJsonPath('data.tezkor_billing', null);
    }

    public function test_hidden_or_unknown_placements_are_rejected(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $hidden = AdPlacement::factory()->status(AdPlacementStatus::Hidden)->create();

        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => [$hidden->id],
        ], $this->auth($client))->assertUnprocessable()->assertJsonValidationErrors(['placements.0']);

        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => [999_999],
        ], $this->auth($client))->assertUnprocessable();

        // Without placements the pin is still required.
        $this->postJson('/api/v1/orders', ['description' => 'Banner kerak'], $this->auth($client))
            ->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);

        $this->assertSame(0, Order::count());
    }

    public function test_broadcast_reaches_only_agencies_serving_the_led_category(): void
    {
        Http::fake();
        Queue::fake();
        $led = $this->ledCategory();
        $ledAgent = $this->approvedAgent($led);
        $outdoorAgent = $this->approvedAgent($led->parent); // parent category serves the subtree
        $smmAgent = $this->approvedAgent(Category::factory()->create(['name_uz' => 'SMM']));

        $client = User::factory()->create();
        $placement = AdPlacement::factory()->create();

        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => [$placement->id],
        ], $this->auth($client))->assertCreated();

        $recipients = collect(Queue::pushed(SendNewOrderNotification::class))->map(fn ($job) => $job->recipientId)->all();
        $this->assertEqualsCanonicalizing([$ledAgent->id, $outdoorAgent->id], $recipients);
        $this->assertNotContains($smmAgent->id, $recipients);
    }

    public function test_screens_of_one_platform_owner_make_a_directed_order(): void
    {
        Http::fake();
        Queue::fake();
        $led = $this->ledCategory();
        $owner = $this->approvedAgent($led);
        $other = $this->approvedAgent($led);
        $ownerProfile = $owner->profile;

        $screens = AdPlacement::factory()->count(2)->ownedBy($ownerProfile)->create();

        $client = User::factory()->create();
        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => $screens->pluck('id')->all(),
        ], $this->auth($client))
            ->assertCreated()
            ->assertJsonPath('data.target_agent.id', $owner->id)
            ->assertJsonPath('data.placements.0.owner_name', $ownerProfile->company_name);

        $recipients = collect(Queue::pushed(SendNewOrderNotification::class))->map(fn ($job) => $job->recipientId)->all();
        $this->assertSame([$owner->id], $recipients);
        $this->assertNotContains($other->id, $recipients);
    }

    public function test_mixed_owners_stay_a_broadcast(): void
    {
        Http::fake();
        Queue::fake();
        $led = $this->ledCategory();
        $owner = $this->approvedAgent($led);

        $mine = AdPlacement::factory()->ownedBy($owner->profile)->create();
        $unclaimed = AdPlacement::factory()->create();

        $client = User::factory()->create();
        $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => [$mine->id, $unclaimed->id],
        ], $this->auth($client))
            ->assertCreated()
            ->assertJsonPath('data.target_agent', null);
    }

    public function test_agent_feed_and_showcase_detail_expose_placements(): void
    {
        Http::fake();
        Queue::fake();
        $led = $this->ledCategory();
        $agent = $this->approvedAgent($led);
        $client = User::factory()->create();
        $placement = AdPlacement::factory()->create();

        $orderId = $this->postJson('/api/v1/orders', [
            'description' => 'LED ekranda reklama kerak',
            'placements' => [$placement->id],
        ], $this->auth($client))->assertCreated()->json('data.id');

        $this->getJson('/api/v1/agent/orders', $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.items.0.placements.0.placement_id', $placement->id)
            ->assertJsonPath('data.items.0.placements.0.lat', fn ($lat) => $lat !== null);

        $this->getJson('/api/v1/orders/showcase/'.$orderId, $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.placements.0.address', $placement->address);

        $this->getJson('/api/v1/orders', $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.0.placements.0.placement_id', $placement->id);
    }
}
