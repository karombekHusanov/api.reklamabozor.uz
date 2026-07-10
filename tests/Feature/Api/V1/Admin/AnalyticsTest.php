<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string}
     */
    private function admin(): array
    {
        $user = User::factory()->create(['role' => Role::Admin]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_analytics_requires_admin_role(): void
    {
        $client = User::factory()->create(['role' => Role::Client]);
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/analytics', $this->auth($token))->assertForbidden();
        $this->getJson('/api/v1/admin/analytics')->assertForbidden();
        $this->getJson('/api/v1/admin/analytics/activity')->assertForbidden();
    }

    public function test_analytics_returns_dashboard_payload(): void
    {
        [, $token] = $this->admin();

        $order = Order::factory()->create(['status' => OrderStatus::InProgress]);
        Offer::factory()->create([
            'order_id' => $order->id,
            'status' => OfferStatus::Accepted,
            'price' => 1_500_000,
        ]);

        $this->getJson('/api/v1/admin/analytics?period=30d', $this->auth($token))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period' => ['days', 'from', 'to'],
                    'ops' => ['kyc_pending', 'reviews_pending', 'stuck_orders', 'dead_orders'],
                    'kpis' => [
                        'users' => ['total', 'new'],
                        'agents' => ['approved', 'pending'],
                        'orders' => ['total', 'open', 'new'],
                        'gmv' => ['total', 'period'],
                        'rating' => ['average', 'count'],
                    ],
                    'trends' => ['labels', 'orders', 'offers', 'registrations', 'gmv'],
                    'funnel' => ['statuses', 'total', 'auto_completed'],
                    'liquidity' => [
                        'avg_offers_per_order',
                        'orders_with_offers_pct',
                        'avg_time_to_first_offer_hours',
                        'avg_views_per_order',
                        'dead_orders',
                    ],
                    'categories',
                    'agents',
                ],
            ])
            ->assertJsonPath('data.period.days', 30)
            ->assertJsonPath('data.kpis.orders.total', 1)
            ->assertJsonPath('data.kpis.gmv.total', 1_500_000);
    }

    public function test_analytics_rejects_invalid_period(): void
    {
        [, $token] = $this->admin();

        $this->getJson('/api/v1/admin/analytics?period=365d', $this->auth($token))
            ->assertUnprocessable();
    }

    public function test_activity_returns_events_feed(): void
    {
        [, $token] = $this->admin();

        $order = Order::factory()->create();
        Offer::factory()->create(['order_id' => $order->id]);

        $response = $this->getJson('/api/v1/admin/analytics/activity', $this->auth($token))
            ->assertOk();

        $events = $response->json('data');

        $this->assertIsArray($events);
        $this->assertNotEmpty($events);
        $this->assertContains('order_created', array_column($events, 'type'));
    }
}
