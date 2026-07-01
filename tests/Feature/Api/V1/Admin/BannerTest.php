<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\AgentProfile;
use App\Models\Banner;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerTest extends TestCase
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

    public function test_admin_can_list_banners(): void
    {
        Banner::factory()->count(2)->create();
        Banner::factory()->inactive()->create();

        $this->getJson('/api/v1/admin/banners', [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonCount(3, 'data.items');
    }

    public function test_admin_can_create_product_banner(): void
    {
        $file = File::factory()->create();

        $this->postJson('/api/v1/admin/banners', [
            'title' => 'Summer sale',
            'subtitle' => 'Up to 50% off',
            'type' => 'product',
            'target_id' => 42,
            'image_file_id' => $file->id,
            'sort_order' => 5,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Summer sale')
            ->assertJsonPath('data.type', 'product')
            ->assertJsonPath('data.target_id', 42)
            ->assertJsonPath('data.sort_order', 5);

        $this->assertDatabaseHas('banners', [
            'title' => 'Summer sale',
            'type' => 'product',
            'target_id' => 42,
            'image_file_id' => $file->id,
        ]);
    }

    public function test_admin_can_create_agent_banner_with_existing_agent(): void
    {
        $file = File::factory()->create();
        $profile = AgentProfile::factory()->create();

        $this->postJson('/api/v1/admin/banners', [
            'type' => 'agent',
            'target_id' => $profile->id,
            'image_file_id' => $file->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'agent')
            ->assertJsonPath('data.target_id', $profile->id);
    }

    public function test_agent_banner_requires_existing_agent_target(): void
    {
        $file = File::factory()->create();

        $this->postJson('/api/v1/admin/banners', [
            'type' => 'agent',
            'target_id' => 999999,
            'image_file_id' => $file->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_id']);
    }

    public function test_create_banner_requires_type_and_target(): void
    {
        $file = File::factory()->create();

        $this->postJson('/api/v1/admin/banners', [
            'image_file_id' => $file->id,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'target_id']);
    }

    public function test_create_banner_requires_image(): void
    {
        $this->postJson('/api/v1/admin/banners', [
            'title' => 'No image',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image_file_id']);
    }

    public function test_admin_can_update_banner(): void
    {
        $banner = Banner::factory()->create(['title' => 'Old']);

        $this->patchJson("/api/v1/admin/banners/{$banner->id}", [
            'title' => 'New',
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_admin_can_toggle_banner_active_state(): void
    {
        $banner = Banner::factory()->create(['is_active' => true]);

        $this->patchJson("/api/v1/admin/banners/{$banner->id}/active", [
            'is_active' => false,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'is_active' => false]);
    }

    public function test_admin_can_delete_banner(): void
    {
        $banner = Banner::factory()->create();

        $this->deleteJson("/api/v1/admin/banners/{$banner->id}", [], [
            'Authorization' => 'Bearer '.$this->adminToken(),
        ])->assertOk();

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
    }

    public function test_non_admin_cannot_manage_banners(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/banners', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }
}
