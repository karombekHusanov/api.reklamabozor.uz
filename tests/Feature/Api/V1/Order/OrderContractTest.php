<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\LegalEntityStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PersonType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Contract;
use App\Models\LegalEntityVerification;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
    }

    /**
     * @return array{0: User, 1: AgentProfile, 2: Category}
     */
    private function approvedAgent(): array
    {
        $category = Category::factory()->create();
        $user = User::factory()->create(['telegram_id' => 700100200]);
        $profile = AgentProfile::factory()->for($user)->approved()->create([
            'company_name' => 'Nova Media',
            'inn' => '305128672',
            'bank_account' => '20208000300000000001',
            'mfo' => '00450',
        ]);
        $profile->categories()->attach($category);

        return [$user, $profile, $category];
    }

    private function pricedOffer(Order $order, User $agent, AgentProfile $profile): Offer
    {
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
            'price' => 4_800_000,
            'deadline_days' => 14,
        ]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98',
            'unit' => 'dona',
            'quantity' => 20,
            'unit_price' => 240_000,
            'sort_order' => 0,
        ]);

        return $offer;
    }

    public function test_accepting_offer_generates_order_contract(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = $this->pricedOffer($order, $agent, $profile);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept")
            ->assertOk();

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);

        $contract = Contract::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($contract);
        $this->assertSame("RB-{$order->id}-".now()->format('Y'), $contract->number);
        $this->assertNotNull($contract->pdf_file_id);
        $this->assertNotNull($contract->hash);
        $this->assertSame('Nova Media', $contract->agent_snapshot['company_name']);
        $this->assertSame('Backprint 27x98', $contract->items_snapshot[0]['name']);
        $this->assertSame('4800000.00', $contract->total);
    }

    public function test_order_detail_exposes_contract_pdf(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = $this->pricedOffer($order, $agent, $profile);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.contract.number', "RB-{$order->id}-".now()->format('Y'))
            ->assertJsonPath('data.contract.total', '4800000.00')
            ->assertJsonStructure(['data' => ['contract' => ['pdf_url']]]);
    }

    public function test_contract_generation_is_idempotent(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = $this->pricedOffer($order, $agent, $profile);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

        // Re-running generation must not create a second contract.
        app(OrderContractService::class)->generateForOrder($order->fresh());

        $this->assertSame(1, Contract::query()->where('order_id', $order->id)->count());
    }

    public function test_legal_entity_client_requisites_snapshotted(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        $client = User::factory()->create(['person_type' => PersonType::LegalEntity]);
        LegalEntityVerification::factory()->for($client)->create([
            'status' => LegalEntityStatus::Approved,
            'company_name' => 'WINTOP TRADING',
            'inn' => '307128672',
        ]);
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = $this->pricedOffer($order, $agent, $profile);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

        $contract = Contract::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertTrue($contract->client_snapshot['is_legal_entity']);
        $this->assertSame('WINTOP TRADING', $contract->client_snapshot['company_name']);
        $this->assertSame('307128672', $contract->client_snapshot['inn']);
    }
}
