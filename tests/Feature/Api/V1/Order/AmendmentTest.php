<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\AmendmentStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmendmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
    }

    /**
     * Build an active deal: an in-progress order with an accepted priced offer.
     *
     * @return array{0: User, 1: User, 2: Offer}
     */
    private function activeDeal(int $qty = 20, int $unitPrice = 240_000, int $deadlineDays = 14): array
    {
        $category = Category::factory()->create();
        $client = User::factory()->create();
        $agent = User::factory()->create(['telegram_id' => 700100200]);
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($category);

        $order = Order::factory()->for($category)->for($client, 'client')
            ->status(OrderStatus::InProgress)->create();

        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => $qty * $unitPrice,
            'deadline_days' => $deadlineDays,
        ]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98',
            'unit' => 'dona',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'sort_order' => 0,
        ]);

        return [$client, $agent, $offer];
    }

    public function test_client_proposes_amendment_and_agent_approval_applies_it(): void
    {
        [$client, $agent, $offer] = $this->activeDeal();
        $order = $offer->order;

        // Same item name + same deadline → no operator needed. Quantity bumped.
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", [
            'items' => [['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 30, 'unit_price' => 240_000]],
            'deadline_days' => 14,
            'reason' => 'Kengaytirilgan hajm',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', AmendmentStatus::Pending->value)
            ->assertJsonPath('data.initiator_role', 'client')
            ->assertJsonPath('data.requires_operator', false)
            ->assertJsonPath('data.approvals.client', true)
            ->assertJsonPath('data.approvals.agent', false);

        $amendment = OrderAmendment::query()->firstOrFail();
        $this->assertSame('2400000.00', $amendment->extra_amount); // (30-20)*240000

        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', AmendmentStatus::Applied->value)
            ->assertJsonPath('data.approvals.agent', true);

        $amendment->refresh();
        $this->assertNotNull($amendment->applied_at);
        $this->assertNotNull($amendment->pdf_file_id);

        // The offer pricelist + total were rewritten.
        $offer->refresh();
        $this->assertSame('7200000.00', (string) $offer->price); // 30 * 240000
        $this->assertSame(30, (int) $offer->items()->first()->quantity);
    }

    public function test_deadline_change_requires_operator_approval(): void
    {
        [$client, $agent, $offer] = $this->activeDeal();
        $order = $offer->order;
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->postJson("/api/v1/orders/{$order->id}/amendments", [
            'items' => [['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 20, 'unit_price' => 240_000]],
            'deadline_days' => 30, // deadline changed → formal doc + operator
        ])
            ->assertCreated()
            ->assertJsonPath('data.requires_operator', true)
            ->assertJsonPath('data.requires_formal_doc', true);

        $amendment = OrderAmendment::query()->firstOrFail();

        // Client approves — still pending because the operator has not signed off.
        $this->actingAs($client)->postJson("/api/v1/amendments/{$amendment->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', AmendmentStatus::Pending->value);

        // Operator approval finalizes it.
        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', AmendmentStatus::Applied->value);
    }

    public function test_agent_can_reject_amendment(): void
    {
        [$client, $agent, $offer] = $this->activeDeal();
        $order = $offer->order;

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", [
            'items' => [['name' => 'Backprint 27x98', 'quantity' => 40, 'unit_price' => 240_000]],
            'deadline_days' => 14,
        ])->assertCreated();

        $amendment = OrderAmendment::query()->firstOrFail();

        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/reject", [
            'reason' => 'Juda ko\'p',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AmendmentStatus::Rejected->value);

        // Offer pricelist is untouched after a rejection.
        $this->assertSame(20, (int) $offer->items()->first()->quantity);
    }

    public function test_initiator_can_cancel_their_amendment(): void
    {
        [$client, , $offer] = $this->activeDeal();
        $order = $offer->order;

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", [
            'items' => [['name' => 'Backprint 27x98', 'quantity' => 25, 'unit_price' => 240_000]],
            'deadline_days' => 14,
        ])->assertCreated();

        $amendment = OrderAmendment::query()->firstOrFail();

        $this->actingAs($client)->postJson("/api/v1/amendments/{$amendment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', AmendmentStatus::Cancelled->value);
    }

    public function test_duplicate_pending_amendment_is_rejected(): void
    {
        [$client, , $offer] = $this->activeDeal();
        $order = $offer->order;

        $payload = [
            'items' => [['name' => 'Backprint 27x98', 'quantity' => 25, 'unit_price' => 240_000]],
            'deadline_days' => 14,
        ];

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $payload)->assertCreated();
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $payload)->assertUnprocessable();
    }

    public function test_amendment_only_allowed_on_active_order(): void
    {
        [$client, , $offer] = $this->activeDeal();
        $offer->order->update(['status' => OrderStatus::OffersSent]);

        $this->actingAs($client)->postJson("/api/v1/orders/{$offer->order->id}/amendments", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
            'deadline_days' => 14,
        ])->assertUnprocessable();
    }

    public function test_stranger_cannot_propose_amendment(): void
    {
        [, , $offer] = $this->activeDeal();
        $stranger = User::factory()->create(['telegram_id' => 555000111]);

        $this->actingAs($stranger)->postJson("/api/v1/orders/{$offer->order->id}/amendments", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
            'deadline_days' => 14,
        ])->assertForbidden();
    }

    public function test_amendment_requires_deadline(): void
    {
        [$client, , $offer] = $this->activeDeal();

        $this->actingAs($client)->postJson("/api/v1/orders/{$offer->order->id}/amendments", [
            'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 1_000_000]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deadline_days');
    }
}
