<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CategoryType;
use App\Enums\OrderRoute;
use App\Jobs\SendNewOrderNotification;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\MxikCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\FiscalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Nested categories: tree columns, admin tree rules, public tree shape and
 * subtree-based provider matching.
 */
class CategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    /** @return array<string, string> */
    private function adminHeaders(): array
    {
        $admin = User::factory()->admin()->create();

        return ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];
    }

    /** @return array<string, string> */
    private function userHeaders(User $user): array
    {
        // The guard memoizes the resolved user per app instance; several users
        // take turns within one test.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    /**
     * Dizayn → Logotip → Minimalist.
     *
     * @return array{0: Category, 1: Category, 2: Category}
     */
    private function chain(): array
    {
        $root = Category::factory()->create(['name_uz' => 'Dizayn', 'name_ru' => 'Дизайн', 'sort_order' => 1]);
        $child = Category::factory()->childOf($root)->create(['name_uz' => 'Logotip', 'name_ru' => 'Логотип']);
        $leaf = Category::factory()->childOf($child)->create(['name_uz' => 'Minimalist', 'name_ru' => 'Минимализм']);

        return [$root, $child, $leaf];
    }

    // ------------------------------------------------------------------
    // Model
    // ------------------------------------------------------------------

    public function test_tree_columns_are_maintained_on_create(): void
    {
        [$root, $child, $leaf] = $this->chain();

        $this->assertSame('/'.$root->id.'/', $root->fresh()->path);
        $this->assertSame(0, $root->fresh()->depth);
        $this->assertSame("/{$root->id}/{$child->id}/", $child->fresh()->path);
        $this->assertSame(1, $child->fresh()->depth);
        $this->assertSame("/{$root->id}/{$child->id}/{$leaf->id}/", $leaf->fresh()->path);
        $this->assertSame(2, $leaf->fresh()->depth);

        $this->assertSame([$root->id, $child->id, $leaf->id], $leaf->fresh()->ancestorIdsWithSelf());
        $this->assertSame([$root->id, $child->id], $leaf->fresh()->ancestorIds());
        $this->assertSame('Dizayn › Logotip › Minimalist', $leaf->fresh()->fullName('uz'));
        $this->assertSame('Дизайн › Логотип › Минимализм', $leaf->fresh()->fullName('ru'));
        $this->assertEqualsCanonicalizing([$root->id, $child->id, $leaf->id], $root->fresh()->subtreeIds());
        $this->assertEqualsCanonicalizing([$child->id, $leaf->id], Category::descendantIdsOf([$child->id]));
    }

    public function test_child_inherits_the_root_type(): void
    {
        $root = Category::factory()->designer()->create();
        $child = Category::factory()->create(['parent_id' => $root->id, 'type' => CategoryType::Agent]);

        $this->assertSame(CategoryType::Designer, $child->fresh()->type);
    }

    // ------------------------------------------------------------------
    // Admin API
    // ------------------------------------------------------------------

    public function test_admin_creates_a_sub_category_without_a_type(): void
    {
        $root = Category::factory()->designer()->create();

        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'Logotip',
            'name_ru' => 'Логотип',
            'parent_id' => $root->id,
        ], $this->adminHeaders())
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $root->id)
            ->assertJsonPath('data.depth', 1)
            ->assertJsonPath('data.type', 'designer')
            ->assertJsonPath('data.full_name_uz', $root->name_uz.' › Logotip');
    }

    public function test_root_category_still_requires_a_type(): void
    {
        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'X',
            'name_ru' => 'X',
        ], $this->adminHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_catch_all_cannot_take_children_or_be_moved(): void
    {
        $headers = $this->adminHeaders();
        $other = Category::query()->where('is_other', true)->where('type', CategoryType::Agent)->firstOrFail();
        $root = Category::factory()->create();

        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'X', 'name_ru' => 'X', 'parent_id' => $other->id,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->patchJson("/api/v1/admin/categories/{$other->id}", ['parent_id' => $root->id], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_moving_a_category_under_itself_or_a_descendant_is_rejected(): void
    {
        $headers = $this->adminHeaders();
        [$root, $child, $leaf] = $this->chain();

        $this->patchJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $root->id], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->patchJson("/api/v1/admin/categories/{$root->id}", ['parent_id' => $leaf->id], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame("/{$root->id}/{$child->id}/{$leaf->id}/", $leaf->fresh()->path);
    }

    public function test_reparenting_rewrites_the_subtree_paths_depth_and_type(): void
    {
        $headers = $this->adminHeaders();
        [$root, $child, $leaf] = $this->chain();
        $newRoot = Category::factory()->designer()->create(['name_uz' => 'Brending']);

        // Move "Logotip" (with "Minimalist" under it) below a designer root.
        $this->patchJson("/api/v1/admin/categories/{$child->id}", ['parent_id' => $newRoot->id], $headers)
            ->assertOk()
            ->assertJsonPath('data.parent_id', $newRoot->id)
            ->assertJsonPath('data.depth', 1)
            ->assertJsonPath('data.type', 'designer')
            ->assertJsonPath('data.full_name_uz', 'Brending › Logotip');

        $leaf->refresh();
        $this->assertSame("/{$newRoot->id}/{$child->id}/{$leaf->id}/", $leaf->path);
        $this->assertSame(2, $leaf->depth);
        $this->assertSame(CategoryType::Designer, $leaf->type);
        $this->assertSame('Brending › Logotip › Minimalist', $leaf->fullName('uz'));

        // And back to the root level.
        $this->patchJson("/api/v1/admin/categories/{$child->id}", ['parent_id' => null], $headers)
            ->assertOk()
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.depth', 0);

        $this->assertSame("/{$child->id}/{$leaf->id}/", $leaf->fresh()->path);
        $this->assertSame(1, $leaf->fresh()->depth);
        $this->assertSame([$root->id], $root->fresh()->subtreeIds());
    }

    public function test_changing_a_root_type_cascades_and_a_child_type_is_ignored(): void
    {
        $headers = $this->adminHeaders();
        [$root, $child, $leaf] = $this->chain();

        $this->patchJson("/api/v1/admin/categories/{$root->id}", ['type' => 'designer'], $headers)->assertOk();
        $this->assertSame(CategoryType::Designer, $leaf->fresh()->type);

        $this->patchJson("/api/v1/admin/categories/{$child->id}", ['type' => 'agent'], $headers)
            ->assertOk()
            ->assertJsonPath('data.type', 'designer');
    }

    public function test_category_with_children_cannot_be_deleted(): void
    {
        [$root, $child] = $this->chain();

        $this->deleteJson("/api/v1/admin/categories/{$root->id}", [], $this->adminHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $root->id]);
    }

    public function test_deactivation_cascades_and_activation_needs_an_active_parent(): void
    {
        $headers = $this->adminHeaders();
        [$root, $child, $leaf] = $this->chain();

        $this->patchJson("/api/v1/admin/categories/{$root->id}/active", ['is_active' => false], $headers)
            ->assertOk();

        $this->assertFalse($child->fresh()->is_active);
        $this->assertFalse($leaf->fresh()->is_active);

        $this->patchJson("/api/v1/admin/categories/{$leaf->id}/active", ['is_active' => true], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        // Re-activating the root does not silently re-enable the subtree.
        $this->patchJson("/api/v1/admin/categories/{$root->id}/active", ['is_active' => true], $headers)->assertOk();
        $this->assertFalse($child->fresh()->is_active);

        $this->patchJson("/api/v1/admin/categories/{$child->id}/active", ['is_active' => true], $headers)->assertOk();
        $this->assertTrue($child->fresh()->is_active);

        // A new active child under an inactive parent is refused too.
        $this->patchJson("/api/v1/admin/categories/{$child->id}/active", ['is_active' => false], $headers)->assertOk();
        $this->postJson('/api/v1/admin/categories', [
            'name_uz' => 'X', 'name_ru' => 'X', 'parent_id' => $child->id, 'is_active' => true,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_admin_tree_endpoint_nests_children_and_includes_inactive(): void
    {
        [$root, $child, $leaf] = $this->chain();
        $leaf->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/admin/categories/tree?type=agent', $this->adminHeaders())->assertOk();

        $node = collect($response->json('data'))->firstWhere('id', $root->id);
        $this->assertNotNull($node);
        $this->assertSame($child->id, $node['children'][0]['id']);
        $this->assertSame($leaf->id, $node['children'][0]['children'][0]['id']);
        $this->assertFalse($node['children'][0]['children'][0]['is_active']);

        // Every returned root really is a root.
        foreach ($response->json('data') as $row) {
            $this->assertNull($row['parent_id']);
        }
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function test_public_list_is_flat_in_tree_order_with_full_names(): void
    {
        [$root, $child, $leaf] = $this->chain();
        $sibling = Category::factory()->childOf($root)->create(['name_uz' => 'Banner', 'sort_order' => 0]);
        $hidden = Category::factory()->childOf($root)->inactive()->create(['name_uz' => 'Hidden']);
        // Child of an inactive parent never shows, even if flagged active.
        $orphan = Category::factory()->create(['parent_id' => $hidden->id, 'is_active' => true]);

        $response = $this->getJson('/api/v1/categories?type=agent', $this->userHeaders(User::factory()->create()))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($hidden->id, $ids);
        $this->assertNotContains($orphan->id, $ids);

        $this->assertSame(
            [$root->id, $sibling->id, $child->id, $leaf->id],
            array_values(array_filter($ids, fn ($id) => in_array($id, [$root->id, $sibling->id, $child->id, $leaf->id], true))),
        );

        $leafRow = collect($response->json('data'))->firstWhere('id', $leaf->id);
        $this->assertSame(2, $leafRow['depth']);
        $this->assertSame($child->id, $leafRow['parent_id']);
        $this->assertSame([$root->id, $child->id, $leaf->id], $leafRow['path_ids']);
        $this->assertSame('Dizayn › Logotip › Minimalist', $leafRow['full_name_uz']);
        $this->assertArrayNotHasKey('children', $leafRow);

        // Catch-all stays last.
        $rows = $response->json('data');
        $this->assertTrue(end($rows)['is_other']);
    }

    public function test_public_list_can_return_a_nested_tree(): void
    {
        [$root, $child, $leaf] = $this->chain();

        $response = $this->getJson('/api/v1/categories?type=agent&tree=1', $this->userHeaders(User::factory()->create()))
            ->assertOk();

        $node = collect($response->json('data'))->firstWhere('id', $root->id);
        $this->assertSame($child->id, $node['children'][0]['id']);
        $this->assertSame($leaf->id, $node['children'][0]['children'][0]['id']);
        $this->assertSame([], $node['children'][0]['children'][0]['children']);
    }

    // ------------------------------------------------------------------
    // Subtree matching
    // ------------------------------------------------------------------

    public function test_provider_listing_a_parent_serves_orders_in_its_subtree(): void
    {
        Queue::fake();

        [$root, , $leaf] = $this->chain();
        $unrelated = Category::factory()->create(['name_uz' => 'Radio']);

        $served = User::factory()->create();
        $servedProfile = AgentProfile::factory()->for($served)->approved()->create();
        $servedProfile->categories()->attach($root);

        $other = User::factory()->create();
        $otherProfile = AgentProfile::factory()->for($other)->approved()->create();
        $otherProfile->categories()->attach($unrelated);

        $client = User::factory()->create();
        $this->postJson('/api/v1/orders', [
            'description' => 'Minimalist logotip kerak, oq fonda, uch variant.',
            'category_id' => $leaf->id,
            'route' => OrderRoute::Tezkor->value,
            'lat' => 41.3111,
            'lng' => 69.2797,
        ], $this->userHeaders($client))->assertCreated();

        $order = Order::query()->where('category_id', $leaf->id)->firstOrFail();

        // Notified: only the provider whose listed category covers the leaf.
        Queue::assertPushed(SendNewOrderNotification::class, 1);
        Queue::assertPushed(SendNewOrderNotification::class, fn ($job) => $job->recipientId === $served->id);

        // Showcase CTA + feed + submit follow the same rule.
        $this->getJson("/api/v1/orders/showcase/{$order->id}", $this->userHeaders($served))
            ->assertOk()
            ->assertJsonPath('data.can_offer', true);
        $this->getJson("/api/v1/orders/showcase/{$order->id}", $this->userHeaders($other))
            ->assertOk()
            ->assertJsonPath('data.can_offer', false);

        $this->assertContains($order->id, collect($this->getJson('/api/v1/agent/orders', $this->userHeaders($served))->json('data.items'))->pluck('id')->all());
        $this->assertNotContains($order->id, collect($this->getJson('/api/v1/agent/orders', $this->userHeaders($other))->json('data.items'))->pluck('id')->all());

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->userHeaders($other))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->userHeaders($served))
            ->assertCreated();
    }

    public function test_provider_listing_a_leaf_does_not_serve_the_parent(): void
    {
        [$root, , $leaf] = $this->chain();

        $agent = User::factory()->create();
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($leaf);

        // Root is served (by the leaf listing? no — only ancestors count), so
        // an order in the root is a broadcast until someone lists the root.
        $this->assertNull($agent->providerProfileForCategory($root->id));
        $this->assertTrue($root->fresh()->shouldBroadcastToAllProviders());
        $this->assertFalse($leaf->fresh()->shouldBroadcastToAllProviders());
    }

    public function test_showcase_category_filter_includes_the_subtree(): void
    {
        [$root, , $leaf] = $this->chain();
        $inRoot = Order::factory()->create(['category_id' => $root->id]);
        $inLeaf = Order::factory()->create(['category_id' => $leaf->id]);
        $elsewhere = Order::factory()->create(['category_id' => Category::factory()->create()->id]);

        $ids = collect($this->getJson("/api/v1/orders/showcase?category_ids={$root->id}")->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($inRoot->id, $ids);
        $this->assertContains($inLeaf->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
    }

    public function test_marketplace_category_filter_matches_ancestors_and_descendants(): void
    {
        [$root, $child, $leaf] = $this->chain();

        $listsRoot = AgentProfile::factory()->approved()->create();
        $listsRoot->categories()->attach($root);
        $listsLeaf = AgentProfile::factory()->approved()->create();
        $listsLeaf->categories()->attach($leaf);
        $listsOther = AgentProfile::factory()->approved()->create();
        $listsOther->categories()->attach(Category::factory()->create());

        $ids = collect($this->getJson("/api/v1/agents?category_ids={$child->id}")->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($listsRoot->id, $ids);
        $this->assertContains($listsLeaf->id, $ids);
        $this->assertNotContains($listsOther->id, $ids);
    }

    public function test_default_mxik_code_is_inherited_from_the_nearest_ancestor(): void
    {
        [$root, $child, $leaf] = $this->chain();
        $rootCode = MxikCode::factory()->create();
        $childCode = MxikCode::factory()->create();
        $root->defaultMxikCode()->attach($rootCode);
        $child->defaultMxikCode()->attach($childCode);

        $fiscal = app(FiscalService::class);

        $this->assertSame($childCode->id, $fiscal->defaultFor($leaf->fresh())?->id);
        $this->assertSame($childCode->id, $fiscal->defaultFor($child->fresh())?->id);
        $this->assertSame($rootCode->id, $fiscal->defaultFor($root->fresh())?->id);

        $coverage = $fiscal->coverage();
        $this->assertNotContains($leaf->id, collect($coverage['missing'])->pluck('id')->all());
    }
}
