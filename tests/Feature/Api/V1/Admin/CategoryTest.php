<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\File;
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

        // Migration seeds agent + designer "Boshqa" → 3 factory + 2 seeded.
        $response = $this->getJson('/api/v1/admin/categories', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.meta.total', 5)
            ->assertJsonCount(5, 'data.items');
    }

    public function test_admin_can_filter_categories_by_type(): void
    {
        Category::factory()->count(2)->create(['type' => CategoryType::Agent]);
        Category::factory()->designer()->create();

        // Designer filter: 1 factory + 1 seeded "Boshqa".
        $this->getJson('/api/v1/admin/categories?type=designer', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
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

    public function test_admin_cannot_delete_or_deactivate_other_category(): void
    {
        $token = $this->adminToken();
        $other = Category::query()->where('is_other', true)->where('type', CategoryType::Agent)->firstOrFail();

        $this->deleteJson("/api/v1/admin/categories/{$other->id}", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);

        $this->patchJson("/api/v1/admin/categories/{$other->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);

        $this->assertDatabaseHas('categories', [
            'id' => $other->id,
            'is_other' => true,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_create_category_with_image(): void
    {
        $file = File::factory()->create();

        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'Bannerlar',
            'name_ru' => 'Баннеры',
            'type' => 'agent',
            'image_file_id' => $file->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.image_file_id', $file->id)
            ->assertJsonPath('data.image', $file->url());

        $this->assertDatabaseHas('categories', [
            'name_uz' => 'Bannerlar',
            'image_file_id' => $file->id,
        ]);
    }

    public function test_admin_can_replace_and_clear_the_category_image(): void
    {
        $file = File::factory()->create();
        $replacement = File::factory()->create();
        $category = Category::factory()->create(['image_file_id' => $file->id]);
        $token = $this->adminToken();

        $this->patchJson("/api/v1/admin/categories/{$category->id}", [
            'image_file_id' => $replacement->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.image_file_id', $replacement->id);

        $this->patchJson("/api/v1/admin/categories/{$category->id}", [
            'image_file_id' => null,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.image_file_id', null)
            ->assertJsonPath('data.image', null);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'image_file_id' => null,
        ]);
    }

    public function test_category_image_must_reference_an_existing_file(): void
    {
        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'SMM',
            'name_ru' => 'СММ',
            'type' => 'agent',
            'image_file_id' => 999999,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image_file_id');
    }

    public function test_deleting_the_image_file_keeps_the_category(): void
    {
        $file = File::factory()->create();
        $category = Category::factory()->create(['image_file_id' => $file->id]);

        $file->delete();

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'image_file_id' => null,
        ]);
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
