<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
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

        $response = $this->getJson('/api/v1/categories?type=agent', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');

        foreach ($response->json('data') as $category) {
            $this->assertSame('agent', $category['type']);
            $this->assertTrue($category['is_active']);
        }
    }

    public function test_filters_by_designer_type(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->designer()->create();

        $this->getJson('/api/v1/categories?type=designer', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'designer');
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
