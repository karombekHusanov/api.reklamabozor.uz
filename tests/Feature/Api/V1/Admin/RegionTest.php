<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        return $admin->createToken('test')->plainTextToken;
    }

    public function test_admin_can_list_regions(): void
    {
        // Migration seeds 14 roots + 12 Tashkent districts.
        $response = $this->getJson('/api/v1/admin/regions?per_page=100', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.meta.total', 26)
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        ['id', 'code', 'name_uz', 'name_ru', 'is_active', 'sort_order', 'children_count', 'orders_count'],
                    ],
                    'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);
    }

    public function test_admin_can_filter_roots_only(): void
    {
        $this->getJson('/api/v1/admin/regions?roots=1&per_page=100', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 14);
    }

    public function test_admin_can_create_root_region(): void
    {
        $response = $this->postJson('/api/v1/admin/regions', [
            'name_uz' => 'Test viloyati',
            'name_ru' => 'Тестовая область',
            'sort_order' => 50,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name_uz', 'Test viloyati')
            ->assertJsonPath('data.code', 'test-viloyati')
            ->assertJsonPath('data.parent_id', null);

        $this->assertDatabaseHas('regions', [
            'code' => 'test-viloyati',
            'parent_id' => null,
        ]);
    }

    public function test_admin_can_create_district_under_root(): void
    {
        $root = Region::query()->where('code', 'andijon')->firstOrFail();

        $response = $this->postJson('/api/v1/admin/regions', [
            'name_uz' => 'Yangi tuman',
            'name_ru' => 'Новый район',
            'parent_id' => $root->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.code', 'yangi-tuman');
    }

    public function test_admin_cannot_nest_under_district(): void
    {
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();

        $this->postJson('/api/v1/admin/regions', [
            'name_uz' => 'Too deep',
            'name_ru' => 'Слишком глубоко',
            'parent_id' => $district->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_admin_can_update_and_toggle_region(): void
    {
        $token = $this->adminToken();
        $region = Region::factory()->create(['name_uz' => 'Old']);

        $this->patchJson("/api/v1/admin/regions/{$region->id}", [
            'name_uz' => 'New',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.name_uz', 'New')
            ->assertJsonPath('data.code', $region->code);

        $this->patchJson("/api/v1/admin/regions/{$region->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_update_null_sort_order_keeps_existing(): void
    {
        $region = Region::factory()->create(['sort_order' => 42]);

        $this->patchJson("/api/v1/admin/regions/{$region->id}", [
            'name_uz' => $region->name_uz,
            'name_ru' => $region->name_ru,
            'sort_order' => null,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 42);

        $this->assertDatabaseHas('regions', [
            'id' => $region->id,
            'sort_order' => 42,
        ]);
    }

    public function test_admin_cannot_reparent_region_with_orders(): void
    {
        $root = Region::query()->where('code', 'andijon')->firstOrFail();
        $district = Region::factory()->create(['parent_id' => $root->id]);
        Order::factory()->create([
            'region_id' => $root->id,
            'district_id' => $district->id,
        ]);

        $otherRoot = Region::query()->where('code', 'buxoro')->firstOrFail();

        $this->patchJson("/api/v1/admin/regions/{$district->id}", [
            'parent_id' => $otherRoot->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_admin_can_delete_unused_leaf_region(): void
    {
        $region = Region::factory()->create();

        $this->deleteJson("/api/v1/admin/regions/{$region->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk();

        $this->assertDatabaseMissing('regions', ['id' => $region->id]);
    }

    public function test_admin_cannot_delete_region_with_children(): void
    {
        $city = Region::query()->where('code', 'toshkent-shahri')->firstOrFail();

        $this->deleteJson("/api/v1/admin/regions/{$city->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['region']);
    }

    public function test_admin_cannot_delete_region_referenced_by_orders(): void
    {
        $region = Region::query()->where('code', 'andijon')->firstOrFail();
        Order::factory()->create(['region_id' => $region->id]);

        $this->deleteJson("/api/v1/admin/regions/{$region->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['region']);
    }

    public function test_admin_cannot_delete_district_referenced_by_orders(): void
    {
        $city = Region::query()->where('code', 'toshkent-shahri')->firstOrFail();
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();
        Order::factory()->create([
            'region_id' => $city->id,
            'district_id' => $district->id,
        ]);

        $this->deleteJson("/api/v1/admin/regions/{$district->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['region']);
    }

    public function test_non_admin_cannot_access_region_routes(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/regions', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }
}
