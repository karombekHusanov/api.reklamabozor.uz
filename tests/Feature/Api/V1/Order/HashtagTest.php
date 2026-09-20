<?php

namespace Tests\Feature\Api\V1\Order;

use App\Models\Category;
use App\Models\File;
use App\Models\Hashtag;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HashtagTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string}
     */
    private function authedUser(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    /**
     * @return array{lat: float, lng: float, location_label: string}
     */
    private function locationPayload(): array
    {
        return [
            'lat' => 41.311081,
            'lng' => 69.279716,
            'location_label' => 'Toshkent',
        ];
    }

    /**
     * @param  list<string>  $hashtags
     */
    private function placeOrder(string $token, Category $category, File $file, array $hashtags = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'budget' => 1000000,
            'category_id' => $category->id,
            'title' => 'Test project',
            'description' => 'Need outdoor ads.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
            'hashtags' => $hashtags,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_order_create_attaches_normalized_hashtags(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $response = $this->placeOrder($token, $category, $file, ['#Banner', 'LED', ' Coral ', 'banner']);

        $response
            ->assertCreated()
            ->assertJsonCount(3, 'data.hashtags');

        $slugs = collect($response->json('data.hashtags'))->pluck('slug')->sort()->values()->all();
        $this->assertSame(['banner', 'coral', 'led'], $slugs);

        $this->assertDatabaseHas('hashtags', ['slug' => 'banner', 'usage_count' => 1]);
        $this->assertDatabaseHas('hashtags', ['slug' => 'led', 'usage_count' => 1]);
        $this->assertDatabaseHas('hashtags', ['slug' => 'coral', 'usage_count' => 1]);
    }

    public function test_order_rejects_more_than_five_hashtags(): void
    {
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->placeOrder($token, $category, $file, ['a', 'b', 'c', 'd', 'e', 'f'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hashtags']);
    }

    public function test_showcase_filters_by_hashtag_slug(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file1 = File::factory()->create(['uploaded_by' => $client->id]);
        $file2 = File::factory()->create(['uploaded_by' => $client->id]);

        $this->placeOrder($token, $category, $file1, ['led'])->assertCreated();
        $this->placeOrder($token, $category, $file2, ['banner'])->assertCreated();

        $response = $this->getJson('/api/v1/orders/showcase?hashtag=led&limit=20');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertCount(1, $ids);
        $this->assertTrue(
            Order::query()->whereKey($ids->all())->whereHas('hashtags', fn ($q) => $q->where('slug', 'led'))->exists()
        );
    }

    public function test_hashtag_suggest_returns_active_by_usage(): void
    {
        Hashtag::factory()->create(['slug' => 'banner', 'label' => 'banner', 'usage_count' => 5]);
        Hashtag::factory()->create(['slug' => 'led', 'label' => 'led', 'usage_count' => 10]);
        Hashtag::factory()->inactive()->create(['slug' => 'old', 'label' => 'old', 'usage_count' => 99]);

        $response = $this->getJson('/api/v1/hashtags');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertSame(['led', 'banner'], $slugs);
        $this->assertNotContains('old', $slugs);
    }

    public function test_admin_can_merge_hashtags(): void
    {
        Http::fake();
        [$client, $clientToken] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->placeOrder($clientToken, $category, $file, ['banner', 'baner'])->assertCreated();

        $source = Hashtag::query()->where('slug', 'baner')->firstOrFail();
        $target = Hashtag::query()->where('slug', 'banner')->firstOrFail();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/hashtags/{$source->id}/merge", [
                'target_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'banner');

        $this->assertDatabaseHas('hashtags', ['id' => $source->id, 'is_active' => false, 'usage_count' => 0]);
        $this->assertSame(1, $target->fresh()->orders()->count());
        $this->assertSame(1, (int) $target->fresh()->usage_count);
    }

    public function test_admin_can_deactivate_hashtag(): void
    {
        $admin = User::factory()->admin()->create();
        $tag = Hashtag::factory()->create(['slug' => 'coral', 'label' => 'coral']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/hashtags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('hashtags', ['id' => $tag->id, 'is_active' => false]);
    }
}
