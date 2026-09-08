<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\MxikCode;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\FiscalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The MXIK (IKPU) classifier catalogue: an operator maps a code to a service
 * category, and every pricelist row from then on carries the fiscal data an
 * OFD receipt (and a later partial refund) needs.
 */
class MxikCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => '11307014001000000',
            'package_code' => '1495862',
            'name_uz' => 'Reklama uskunalarini o\'rnatish',
            'name_ru' => 'Установка рекламного оборудования',
            'vat_rate' => 12,
            'unit' => 'dona',
        ], $overrides);
    }

    public function test_admin_creates_and_lists_codes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/admin/mxik-codes', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', '11307014001000000')
            ->assertJsonPath('data.package_code', '1495862')
            ->assertJsonPath('data.vat_rate', '12.00');

        $this->actingAs($admin)->getJson('/api/v1/admin/mxik-codes?search=113070')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_code_must_look_like_a_classifier_number(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/mxik-codes', $this->payload(['code' => 'ABC-123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_duplicate_code_is_refused(): void
    {
        MxikCode::factory()->create(['code' => '11307014001000000']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/mxik-codes', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_a_category_gets_exactly_one_default_code(): void
    {
        $admin = $this->admin();
        $category = Category::factory()->create();

        $first = $this->actingAs($admin)
            ->postJson('/api/v1/admin/mxik-codes', $this->payload(['category_ids' => [$category->id]]))
            ->assertCreated()
            ->json('data.id');

        // Assigning the same category to another code moves the default.
        $second = $this->actingAs($admin)->postJson('/api/v1/admin/mxik-codes', $this->payload([
            'code' => '10305001001000000',
            'name_uz' => 'Reklama xizmatlari',
            'category_ids' => [$category->id],
        ]))->assertCreated()->json('data.id');

        $this->assertSame(0, MxikCode::find($first)->categories()->count());
        $this->assertSame(1, MxikCode::find($second)->categories()->count());
        $this->assertSame(1, $category->defaultMxikCode()->count());
    }

    public function test_assigned_code_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $category = Category::factory()->create();
        $id = $this->actingAs($admin)
            ->postJson('/api/v1/admin/mxik-codes', $this->payload(['category_ids' => [$category->id]]))
            ->json('data.id');

        $this->actingAs($admin)->deleteJson("/api/v1/admin/mxik-codes/{$id}")
            ->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/v1/admin/mxik-codes/{$id}", ['category_ids' => []])
            ->assertOk();

        $this->actingAs($admin)->deleteJson("/api/v1/admin/mxik-codes/{$id}")->assertOk();
    }

    public function test_coverage_lists_categories_without_a_code(): void
    {
        $admin = $this->admin();
        $covered = Category::factory()->create();
        $uncovered = Category::factory()->create();
        $total = Category::query()->where('is_active', true)->count();

        $this->actingAs($admin)->postJson('/api/v1/admin/mxik-codes', $this->payload(['category_ids' => [$covered->id]]))
            ->assertCreated();

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/mxik-codes/coverage')
            ->assertOk()
            ->assertJsonPath('data.total', $total)
            ->assertJsonPath('data.covered', 1)
            ->json('data');

        $missingIds = array_column($data['missing'], 'id');
        $this->assertContains($uncovered->id, $missingIds);
        $this->assertNotContains($covered->id, $missingIds);
    }

    public function test_pricelist_rows_inherit_the_category_code(): void
    {
        $category = Category::factory()->create();
        $code = MxikCode::factory()->create([
            'code' => '11307014001000000',
            'package_code' => '1495862',
            'vat_rate' => 12,
        ]);
        $code->categories()->attach($category->id);

        $agent = User::factory()->create(['telegram_id' => 750100600]);
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($category);

        $order = Order::factory()->for($category)->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [['name' => 'Bilbord', 'unit' => 'dona', 'quantity' => 2, 'unit_price' => 500_000]],
            'deadline_days' => 7,
            'accept_contract' => true,
        ])->assertOk();

        $item = $offer->items()->firstOrFail();
        $this->assertSame('11307014001000000', $item->mxik_code);
        $this->assertSame('1495862', $item->package_code);
        $this->assertSame('12.00', (string) $item->vat_rate);
        $this->assertTrue($item->hasFiscalData());

        // …and the receipt lines can now be built for the gateway.
        $lines = app(FiscalService::class)->receiptLines($offer->fresh()->load('items'));
        $this->assertNotNull($lines);
        $this->assertSame('11307014001000000', $lines[0]['mxik']);
        $this->assertSame(100_000_000, $lines[0]['total']); // 1 000 000 so'm in tiyin
        $this->assertSame(12_000_000, $lines[0]['vat']);    // 12% of the line
    }

    public function test_receipt_lines_are_withheld_when_a_row_has_no_code(): void
    {
        $order = Order::factory()->status(OrderStatus::InProgress)->create();
        $offer = Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted, 'price' => 100_000]);
        $offer->items()->create([
            'name' => 'Xizmat', 'unit' => 'dona', 'quantity' => 1, 'unit_price' => 100_000, 'sort_order' => 0,
        ]);

        $this->assertNull(app(FiscalService::class)->receiptLines($offer->load('items')));
    }
}
