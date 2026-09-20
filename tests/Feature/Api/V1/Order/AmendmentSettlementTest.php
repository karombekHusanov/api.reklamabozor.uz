<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\AmendmentStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\AgentProfile;
use App\Models\AmendmentEvent;
use App\Models\Category;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faza 3: an approved addendum applies at once; what it costs (or gives back)
 * lands on the order ledger and every step is on the audit trail.
 */
class AmendmentSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('files.disk'));
    }

    /**
     * A paid, active deal: 20 × 240 000 = 4 800 000 so'm.
     *
     * @return array{0: User, 1: User, 2: Order}
     */
    private function paidDeal(): array
    {
        $category = Category::factory()->create();
        $client = User::factory()->create();
        $agent = User::factory()->create(['telegram_id' => 740100500]);
        $profile = AgentProfile::factory()->for($agent)->approved()->create();
        $profile->categories()->attach($category);

        $order = Order::factory()->for($category)->for($client, 'client')
            ->status(OrderStatus::InProgress)
            ->create([
                'activated_at' => now(),
                'payment_state' => OrderPaymentState::Paid,
                'paid_at' => now(),
            ]);

        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => 4_800_000,
            'deadline_days' => 12,
        ]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98', 'unit' => 'dona',
            'quantity' => 20, 'unit_price' => 240_000, 'sort_order' => 0,
        ]);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'purpose' => PaymentPurpose::Order,
            'status' => PaymentStatus::Success,
            'amount' => 480_000_000,
            'paid_at' => now(),
        ]);

        return [$client, $agent, $order->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(int $qty): array
    {
        return [
            'items' => [['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => $qty, 'unit_price' => 240_000]],
            'deadline_days' => 12,
            'accept_contract' => true,
        ];
    }

    private function agree(User $proposer, User $approver, Order $order, int $qty): OrderAmendment
    {
        $this->actingAs($proposer)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal($qty))
            ->assertCreated();

        $amendment = OrderAmendment::query()->latest('id')->firstOrFail();

        $this->actingAs($approver)
            ->postJson("/api/v1/amendments/{$amendment->id}/approve", ['accept_contract' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        return $amendment->fresh();
    }

    public function test_extra_applies_at_once_and_becomes_a_debt(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();

        // 20 → 30 items = +2 400 000 so'm.
        $this->agree($client, $agent, $order, 30);

        $fresh = $order->fresh();
        $this->assertSame('7200000.00', (string) $fresh->acceptedOffer->price);
        $this->assertSame(240_000_000, $fresh->outstandingTiyin());
        $this->assertSame(OrderPaymentState::Unpaid, $fresh->payment_state);
        $this->assertNotNull($fresh->payment_due_at);

        $this->assertDatabaseHas('amendment_events', ['type' => AmendmentEvent::CHARGE_DUE]);
    }

    public function test_price_cut_on_a_paid_deal_creates_a_refund_obligation(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();

        // 20 → 15 items = −1 200 000 so'm.
        $amendment = $this->agree($agent, $client, $order, 15);

        $this->assertSame(OrderAmendment::REFUND_DUE, $amendment->refund_state);
        $this->assertSame('1200000.00', (string) $amendment->refund_amount);
        $this->assertDatabaseHas('amendment_events', ['type' => AmendmentEvent::REFUND_DUE]);

        // Operator hands the money back.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/refund", [
            'amount' => 1_200_000,
            'method' => 'cash',
            'reference' => 'KASSA-77',
            'note' => 'Naqd qaytarildi',
        ])
            ->assertOk()
            ->assertJsonPath('data.refund.state', OrderAmendment::REFUND_REFUNDED)
            ->assertJsonPath('data.refund.reference', 'KASSA-77');

        // Ledger is square again: paid money minus what went back.
        $this->assertSame(0, $order->fresh()->outstandingTiyin());
        $this->assertSame(OrderPaymentState::Paid, $order->fresh()->payment_state);
        $this->assertDatabaseHas('amendment_events', ['type' => AmendmentEvent::REFUND_PAID]);
    }

    public function test_partial_refund_is_refused(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();
        $amendment = $this->agree($agent, $client, $order, 15);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/refund", [
            'amount' => 500_000,
            'method' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(OrderAmendment::REFUND_DUE, $amendment->fresh()->refund_state);
    }

    public function test_refund_can_be_waived(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();
        $amendment = $this->agree($agent, $client, $order, 15);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/waive-refund", [
            'method' => 'cash',
            'note' => 'Mijoz keyingi ishga qoldirdi',
        ])
            ->assertOk()
            ->assertJsonPath('data.refund.state', OrderAmendment::REFUND_WAIVED);

        $this->assertDatabaseHas('amendment_events', ['type' => AmendmentEvent::REFUND_WAIVED]);
    }

    public function test_price_cut_on_an_unpaid_deal_only_lowers_the_debt(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();
        // Wipe the payment: the deal is active but nothing came in.
        Payment::query()->delete();
        $order->update(['payment_state' => OrderPaymentState::Unpaid, 'paid_at' => null]);

        $amendment = $this->agree($agent, $client, $order->fresh(), 15);

        $this->assertSame(OrderAmendment::REFUND_NONE, $amendment->refund_state);
        $this->assertSame(360_000_000, $order->fresh()->outstandingTiyin()); // 3 600 000 so'm
        $this->assertSame(OrderPaymentState::Unpaid, $order->fresh()->payment_state);
    }

    public function test_admin_detail_returns_the_audit_trail(): void
    {
        Http::fake();
        [$client, $agent, $order] = $this->paidDeal();
        $amendment = $this->agree($client, $agent, $order, 30);
        $admin = User::factory()->admin()->create();

        $types = $this->actingAs($admin)->getJson("/api/v1/admin/amendments/{$amendment->id}")
            ->assertOk()
            ->assertJsonPath('data.number', $amendment->number)
            ->json('data.events.*.type');

        foreach ([AmendmentEvent::PROPOSED, AmendmentEvent::APPROVED, AmendmentEvent::APPLIED, AmendmentEvent::CHARGE_DUE] as $expected) {
            $this->assertContains($expected, $types);
        }
    }

    public function test_sweep_reminds_before_the_deadline_then_expires(): void
    {
        Http::fake();
        [$client, , $order] = $this->paidDeal();
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(30))
            ->assertCreated();
        $amendment = OrderAmendment::query()->firstOrFail();

        // Deadline is close but not passed → one nudge, still pending.
        $amendment->update(['expires_at' => now()->addHours(6)]);
        $this->artisan('amendments:sweep')->assertSuccessful();

        $amendment->refresh();
        $this->assertNotNull($amendment->reminder_sent_at);
        $this->assertSame(AmendmentStatus::Pending, $amendment->status);

        // Same window again → no second nudge.
        $reminded = $amendment->reminder_sent_at;
        $this->artisan('amendments:sweep')->assertSuccessful();
        $this->assertTrue($reminded->equalTo($amendment->fresh()->reminder_sent_at));

        // Deadline passed → closed, and the order is free for a new proposal.
        $amendment->update(['expires_at' => now()->subMinute()]);
        $this->artisan('amendments:sweep')->assertSuccessful();

        $this->assertSame(AmendmentStatus::Expired, $amendment->fresh()->status);
        $this->assertDatabaseHas('amendment_events', ['type' => AmendmentEvent::EXPIRED]);
    }

    public function test_operator_can_expire_a_stale_proposal(): void
    {
        Http::fake();
        [$client, , $order] = $this->paidDeal();
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(30))
            ->assertCreated();
        $amendment = OrderAmendment::query()->firstOrFail();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/expire")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        // A closed proposal frees the order for a new one.
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(25))
            ->assertCreated();
    }
}
