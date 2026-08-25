<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\CategoryType;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 of the profile redesign: integrity guards + capacity stats.
 * See PROFILE_ARCHITECTURE.md §7, §9.
 */
class CapacityAndGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string, 2: AgentProfile, 3: Category} */
    private function approvedAgent(?Category $category = null): array
    {
        $category ??= Category::factory()->create(['type' => CategoryType::Agent]);
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return [$user, $user->createToken('test')->plainTextToken, $profile, $category];
    }

    // R1 — self-dealing guard.
    public function test_a_user_cannot_send_an_offer_to_their_own_order(): void
    {
        // The same account is both an approved provider and the client.
        [$user, $token, , $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->for($user, 'client')
            ->status(OrderStatus::New)->create();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertDatabaseCount('offers', 0);
    }

    // R5/R8 — category type is frozen onto the order at creation.
    public function test_creating_an_order_snapshots_the_category_type(): void
    {
        $client = User::factory()->create();
        $category = Category::factory()->create(['type' => CategoryType::Designer]);

        $order = app(OrderService::class)->create($client, [
            'category_id' => $category->id,
            'description' => 'Need a logo',
            'attachment_file_ids' => [],
            'lat' => null,
            'lng' => null,
        ]);

        $this->assertSame(CategoryType::Designer, $order->category_type);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'category_type' => 'designer',
        ]);
    }

    // Capacity stats read the frozen snapshot, grouped by category type.
    public function test_completed_work_is_counted_per_capacity(): void
    {
        $provider = User::factory()->create();
        $agentCat = Category::factory()->create(['type' => CategoryType::Agent]);
        $designCat = Category::factory()->create(['type' => CategoryType::Designer]);

        // 2 completed as agent, 1 as designer, plus noise that must not count.
        $this->completedOrderFor($provider, $agentCat);
        $this->completedOrderFor($provider, $agentCat);
        $this->completedOrderFor($provider, $designCat);
        // Pending (not accepted) — excluded.
        $open = Order::factory()->for($agentCat)->status(OrderStatus::New)->create();
        Offer::factory()->for($open)->for($provider, 'agent')
            ->create(['status' => OfferStatus::Pending]);

        $this->assertSame(
            ['agent' => 2, 'designer' => 1],
            $provider->completedWorkByCapacity(),
        );
    }

    private function completedOrderFor(User $provider, Category $category): void
    {
        $order = Order::factory()->for($category)
            ->status(OrderStatus::Completed)->create();
        Offer::factory()->for($order)->for($provider, 'agent')
            ->create(['status' => OfferStatus::Accepted]);
    }
}
