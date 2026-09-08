<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function userToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_categories_require_authentication(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }

    public function test_lists_active_agent_categories_only(): void
    {
        Category::factory()->count(3)->create();
        Category::factory()->inactive()->create();
        Category::factory()->designer()->create();

        // Migration seeds one agent "Boshqa" catch-all → 3 factory + 1 seeded.
        $response = $this->getJson('/api/v1/categories?type=agent', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(4, 'data');

        foreach ($response->json('data') as $category) {
            $this->assertSame('agent', $category['type']);
            $this->assertTrue($category['is_active']);
            $this->assertArrayHasKey('is_other', $category);
        }

        $this->assertTrue(
            collect($response->json('data'))->contains(fn (array $c) => $c['is_other'] === true),
        );
    }

    public function test_filters_by_designer_type(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->designer()->create();

        // Migration seeds one designer "Boshqa" → 1 factory + 1 seeded.
        $this->getJson('/api/v1/categories?type=designer', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.type', 'designer');
    }

    public function test_exposes_the_category_image_url(): void
    {
        $file = File::factory()->create();
        Category::factory()->create(['image_file_id' => $file->id, 'name_uz' => 'Bannerlar']);

        $response = $this->getJson('/api/v1/categories?type=agent', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ])->assertOk();

        $withImage = collect($response->json('data'))
            ->firstWhere('name_uz', 'Bannerlar');

        $this->assertSame($file->id, $withImage['image_file_id']);
        $this->assertSame($file->url(), $withImage['image']);

        // A category without an image still renders — the mini app falls back to its icon.
        $withoutImage = collect($response->json('data'))
            ->first(fn (array $c) => $c['name_uz'] !== 'Bannerlar');

        $this->assertNull($withoutImage['image']);
    }

    public function test_rejects_invalid_type(): void
    {
        $this->getJson('/api/v1/categories?type=banana', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }
}
