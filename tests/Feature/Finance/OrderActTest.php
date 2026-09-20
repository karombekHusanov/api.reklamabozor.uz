<?php

namespace Tests\Feature\Finance;

use App\Enums\OrderDocumentType;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use App\Services\Order\OrderActService;
use App\Support\MoneyInWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The acts that close an order for the bookkeeping: what was delivered
 * (client ↔ agent) and what the platform charged for it (platform ↔ agent).
 */
class OrderActTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: User, 2: User} */
    private function completedDeal(int $priceSom = 1_000_000): array
    {
        config(['payments.commission_percent' => 7]);

        $profile = AgentProfile::factory()->create(['company_name' => 'MIRON']);
        $client = User::factory()->create();

        $order = Order::factory()->for($client, 'client')->status(OrderStatus::Completed)->create([
            'activated_at' => now()->subDays(5),
            'completed_at' => now(),
        ]);

        $offer = Offer::factory()->for($order)->accepted()->create([
            'price' => $priceSom,
            'agent_id' => $profile->user_id,
            'agent_profile_id' => $profile->id,
        ]);

        OfferItem::factory()->for($offer)->create([
            'name' => 'Banner',
            'unit' => 'dona',
            'quantity' => 2,
            'unit_price' => $priceSom / 2,
        ]);

        return [$order->fresh(), $client, $profile->user];
    }

    public function test_completed_order_gets_both_acts(): void
    {
        [$order] = $this->completedDeal();

        $documents = app(OrderActService::class)->generateForOrder($order);

        $this->assertCount(2, $documents);

        $work = $documents[OrderDocumentType::WorkAct->value];
        $this->assertStringEndsWith('/ACT', $work->number);
        $this->assertNotNull($work->pdfFile);
        $this->assertSame('1000000.00', $work->total);

        // Commission is the platform's cut of the deal, not the deal itself.
        $commission = $documents[OrderDocumentType::CommissionAct->value];
        $this->assertSame('70000.00', $commission->total);
        $this->assertEquals(930_000, $commission->snapshot['net_amount']);
    }

    public function test_generation_is_idempotent(): void
    {
        [$order] = $this->completedDeal();
        $acts = app(OrderActService::class);

        $first = $acts->generateForOrder($order);
        $second = $acts->generateForOrder($order->fresh());

        $this->assertSame(
            $first[OrderDocumentType::WorkAct->value]->id,
            $second[OrderDocumentType::WorkAct->value]->id,
        );
        $this->assertSame(2, OrderDocument::query()->count());
    }

    public function test_an_unfinished_order_has_no_acts(): void
    {
        [$order] = $this->completedDeal();
        $order->update(['status' => OrderStatus::InProgress]);

        $this->assertSame([], app(OrderActService::class)->generateForOrder($order->fresh()));
    }

    public function test_client_downloads_the_work_act_but_not_the_commission_act(): void
    {
        [$order, $client] = $this->completedDeal();
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/orders/{$order->id}/documents", [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $types = array_column($response->json('data.items'), 'type');

        $this->assertSame(['work_act'], $types);
        $this->assertNotNull($response->json('data.items.0.pdf_url'));
    }

    public function test_agent_downloads_both_acts(): void
    {
        [$order, , $agent] = $this->completedDeal();
        $token = $agent->createToken('t')->plainTextToken;

        $response = $this->getJson("/api/v1/orders/{$order->id}/documents", [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['work_act', 'commission_act'],
            array_column($response->json('data.items'), 'type'),
        );
    }

    public function test_client_order_payload_hides_the_commission_act(): void
    {
        [$order, $client] = $this->completedDeal();
        app(OrderActService::class)->generateForOrder($order);

        $response = $this->getJson("/api/v1/orders/{$order->id}", [
            'Authorization' => 'Bearer '.$client->createToken('t')->plainTextToken,
        ])->assertOk();

        $this->assertSame(['work_act'], array_column($response->json('data.documents'), 'type'));
    }

    public function test_a_stranger_cannot_read_the_acts(): void
    {
        [$order] = $this->completedDeal();
        $other = User::factory()->create();

        $this->getJson("/api/v1/orders/{$order->id}/documents", [
            'Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken,
        ])->assertStatus(403);
    }

    public function test_admin_reads_everything(): void
    {
        [$order] = $this->completedDeal();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->getJson("/api/v1/orders/{$order->id}/documents", [
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
        ])->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_backfill_command_covers_older_orders(): void
    {
        [$order] = $this->completedDeal();

        $this->artisan('orders:generate-acts')->assertExitCode(0);

        $this->assertSame(2, $order->documents()->count());

        $this->artisan('orders:generate-acts')
            ->expectsOutputToContain('already has its acts')
            ->assertExitCode(0);
    }

    public function test_amounts_are_spelled_out_for_the_act(): void
    {
        $this->assertSame('bir million bir yuz yigirma ming', MoneyInWords::words(1_120_000));
        $this->assertSame('nol', MoneyInWords::words(0));
        $this->assertSame("70 000 so'm (yetmish ming so'm)", MoneyInWords::som(70_000));
    }
}
