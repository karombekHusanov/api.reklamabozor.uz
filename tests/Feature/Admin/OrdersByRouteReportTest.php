<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderRoute;
use App\Enums\Role;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersByRouteReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    private function orders(User $client, int $tender, int $tezkor, $at = null): void
    {
        foreach ([[OrderRoute::Tender, $tender], [OrderRoute::Tezkor, $tezkor]] as [$route, $n]) {
            Order::factory()->count($n)->create([
                'client_id' => $client->id,
                'route' => $route,
                'created_at' => $at ?? now(),
            ]);
        }
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->getJson('/api/v1/admin/reports/orders-by-route', ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->assertForbidden();
    }

    public function test_groups_counts_and_sorts_by_tezkor(): void
    {
        $a = User::factory()->create(['first_name' => 'Aaa']);
        $b = User::factory()->create(['first_name' => 'Bbb', 'tender_access_at' => now()]);
        User::factory()->create(); // no orders -> excluded
        $this->orders($a, 3, 1);
        $this->orders($b, 1, 4);

        $data = $this->getJson('/api/v1/admin/reports/orders-by-route', $this->admin())
            ->assertOk()->json('data');

        $this->assertSame(['tender' => 4, 'tezkor' => 5], $data['totals']);
        $this->assertCount(2, $data['items']);
        $this->assertSame($b->id, $data['items'][0]['client_id']);
        $this->assertTrue($data['items'][0]['can_create_tender']);
        $this->assertSame(4, $data['items'][0]['tezkor']);
        $this->assertFalse($data['items'][1]['can_create_tender']);
        $this->assertSame(3, $data['items'][1]['tender']);
        $this->assertSame(2, $data['meta']['total']);
    }

    public function test_granted_filter_excludes_revoked_and_ungranted(): void
    {
        $granted = User::factory()->create(['tender_access_at' => now()]);
        $revoked = User::factory()->create(['tender_access_at' => now()->subDay(), 'tender_access_revoked_at' => now()]);
        $none = User::factory()->create();
        foreach ([$granted, $revoked, $none] as $u) {
            $this->orders($u, 1, 1);
        }

        $data = $this->getJson('/api/v1/admin/reports/orders-by-route?tender_access=granted', $this->admin())
            ->assertOk()->json('data');

        $this->assertSame([$granted->id], array_column($data['items'], 'client_id'));
        $this->assertSame(['tender' => 1, 'tezkor' => 1], $data['totals']);
    }

    public function test_date_filter_applies_to_order_created_at(): void
    {
        $u = User::factory()->create();
        $this->orders($u, 0, 2, now()->subDays(30));
        $this->orders($u, 0, 1, now());

        $data = $this->getJson('/api/v1/admin/reports/orders-by-route?from='.now()->subDays(2)->toDateString(), $this->admin())
            ->assertOk()->json('data');

        $this->assertSame(1, $data['totals']['tezkor']);

        $data = $this->getJson('/api/v1/admin/reports/orders-by-route?to='.now()->subDays(10)->toDateString(), $this->admin())
            ->json('data');
        $this->assertSame(2, $data['totals']['tezkor']);
    }

    public function test_pagination(): void
    {
        foreach (range(1, 3) as $i) {
            $this->orders(User::factory()->create(), 0, $i);
        }
        $data = $this->getJson('/api/v1/admin/reports/orders-by-route?per_page=2&page=2', $this->admin())->json('data');
        $this->assertCount(1, $data['items']);
        $this->assertSame(2, $data['meta']['last_page']);
        $this->assertSame(6, $data['totals']['tezkor']);
    }
}
