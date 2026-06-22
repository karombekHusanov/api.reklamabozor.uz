<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
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

    public function test_admin_can_list_categories(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->designer()->create();

        $response = $this->getJson('/api/v1/admin/categories', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonCount(3, 'data.items');
    }

    public function test_admin_can_filter_categories_by_type(): void
    {
        Category::factory()->count(2)->create(['type' => CategoryType::Agent]);
        Category::factory()->designer()->create();

        $this->getJson('/api/v1/admin/categories?type=designer', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.type', 'designer');
    }

    public function test_admin_can_create_category(): void
    {
        $response = $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'SMM',
            'name_ru' => 'СММ',
            'type' => 'agent',
            'sort_order' => 10,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name_uz', 'SMM')
            ->assertJsonPath('data.type', 'agent')
            ->assertJsonPath('data.sort_order', 10);

        $this->assertDatabaseHas('categories', [
            'name_uz' => 'SMM',
            'type' => 'agent',
        ]);
    }

    public function test_admin_can_update_category(): void
    {
        $category = Category::factory()->create(['name_uz' => 'Old']);

        $this->patchJson("/api/v1/admin/categories/{$category->id}", [
            'name_uz' => 'New',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.name_uz', 'New');
    }

    public function test_admin_can_toggle_category_active_state(): void
    {
        $category = Category::factory()->create(['is_active' => true]);

        $this->patchJson("/api/v1/admin/categories/{$category->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_delete_unused_category(): void
    {
        $category = Category::factory()->create();

        $this->deleteJson("/api/v1/admin/categories/{$category->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_non_admin_cannot_access_category_routes(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/categories', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }
}
