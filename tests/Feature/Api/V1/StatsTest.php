<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenUsedAt(User $user, Carbon $when): void
    {
        $user->createToken('test');
        $user->tokens()->update(['last_used_at' => $when]);
    }

    public function test_live_stats_is_public_and_has_the_expected_shape(): void
    {
        Cache::flush();

        $this->getJson('/api/v1/stats/live')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'users_online',
                    'agents_online',
                    'agencies_total',
                    'designers_total',
                    'orders_today',
                    'active_orders',
                ],
            ]);
    }

    public function test_live_stats_counts_online_users_agents_and_platform_totals(): void
    {
        Cache::flush();

        // Online client — token used within the 10-minute window.
        $this->tokenUsedAt(User::factory()->create(), now()->subMinutes(2));

        // Offline user — token used 30 minutes ago (must be excluded).
        $this->tokenUsedAt(User::factory()->create(), now()->subMinutes(30));

        // Online approved agent — counts as an "agency" by serving an
        // agent-type category (capability, not provider_type).
        $agentUser = User::factory()->create();
        $this->tokenUsedAt($agentUser, now()->subMinute());
        $profile = AgentProfile::factory()->create([
            'user_id' => $agentUser->id,
            'status' => AgentProfileStatus::Approved,
        ]);
        $profile->categories()->attach(Category::factory()->create());

        // Orders: one active (new, also created today), one completed.
        Order::factory()->create(['status' => OrderStatus::New]);
        Order::factory()->create(['status' => OrderStatus::Completed]);

        $this->getJson('/api/v1/stats/live')
            ->assertOk()
            ->assertJsonPath('data.users_online', 2)
            ->assertJsonPath('data.agents_online', 1)
            ->assertJsonPath('data.agencies_total', 1)
            ->assertJsonPath('data.orders_today', 2)
            ->assertJsonPath('data.active_orders', 1);
    }
}
