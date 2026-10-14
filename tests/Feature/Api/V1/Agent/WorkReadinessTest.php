<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\OrderRoute;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An approved executor may respond to requests only once the profile lists
 * its service categories and its location on the map — either legal track.
 */
class WorkReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /**
     * @return array{0: AgentProfile, 1: array<string, string>}
     */
    private function executor(bool $individual = false): array
    {
        $user = User::factory()->create();
        $factory = AgentProfile::factory()->for($user)->approved();
        $profile = ($individual ? $factory->individual() : $factory)->create();

        return [$profile, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    /** Broadcast Tezkor request — open to every approved executor. */
    private function request(): Order
    {
        return Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tezkor]);
    }

    public function test_executor_without_categories_cannot_respond(): void
    {
        [$profile, $headers] = $this->executor();
        $this->assertFalse($profile->isReadyToWork());

        $this->postJson("/api/v1/agent/orders/{$this->request()->id}/offers", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_executor_without_a_map_location_cannot_respond(): void
    {
        [$profile, $headers] = $this->executor(individual: true);
        $profile->categories()->attach(Category::factory()->create());
        $profile->update(['lat' => null, 'lng' => null]);

        $this->postJson("/api/v1/agent/orders/{$this->request()->id}/offers", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_executor_with_categories_and_location_can_respond(): void
    {
        [$profile, $headers] = $this->executor(individual: true);
        $profile->categories()->attach(Category::factory()->create());

        $this->assertTrue($profile->fresh()->isReadyToWork());

        $this->postJson("/api/v1/agent/orders/{$this->request()->id}/offers", [], $headers)
            ->assertCreated();
    }
}
