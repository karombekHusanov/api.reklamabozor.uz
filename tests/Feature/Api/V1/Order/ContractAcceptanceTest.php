<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\ContractAcceptance;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Click-wrap gate on the three-party per-order contract: the agent accepts it
 * when sending the pricelist, the client when accepting the offer, and both
 * taps are logged.
 */
class ContractAcceptanceTest extends TestCase
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
        $user = User::factory()->create(['telegram_id' => 710100300]);
        $profile = AgentProfile::factory()->for($user)->approved()->create([
            'company_name' => 'Nova Media',
            'inn' => '305128672',
        ]);
        $profile->categories()->attach($category);

        return [$user, $profile, $category];
    }

    /**
     * @return array{0: Order, 1: Offer, 2: User}
     */
    private function interestOffer(User $agent, AgentProfile $profile, Category $category): array
    {
        $client = User::factory()->create();
        $order = Order::factory()->for($category)->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->interest()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
        ]);

        return [$order, $offer, $client];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(): array
    {
        return [['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 2, 'unit_price' => 240_000]];
    }

    public function test_agent_previews_contract_built_from_draft_pricelist(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($agent)->postJson("/api/v1/agent/offers/{$offer->id}/contract-preview", [
            'items' => $this->items(),
            'deadline_days' => 14,
        ])
            ->assertOk()
            ->assertJsonPath('data.total', '480000.00')
            ->assertJsonPath('data.agent.company_name', 'Nova Media')
            ->assertJsonPath('data.items.0.line_total', '480000.00')
            ->assertJsonStructure(['data' => ['number', 'hash', 'intro', 'platform', 'client', 'sections']]);

        // Preview stores nothing.
        $this->assertSame(0, $offer->items()->count());
        $this->assertSame(0, ContractAcceptance::query()->count());
    }

    public function test_agent_cannot_preview_another_agents_offer(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer] = $this->interestOffer($agent, $profile, $category);
        $other = User::factory()->create(['telegram_id' => 710100999]);

        $this->actingAs($other)->postJson("/api/v1/agent/offers/{$offer->id}/contract-preview", [
            'items' => $this->items(),
            'deadline_days' => 14,
        ])->assertNotFound();
    }

    public function test_pricelist_requires_contract_acceptance(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => $this->items(),
            'deadline_days' => 14,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        $this->assertSame(0, $offer->items()->count());
        $this->assertTrue($offer->fresh()->isInterest());
        $this->assertSame(0, ContractAcceptance::query()->count());
    }

    public function test_sending_pricelist_logs_agent_acceptance(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => $this->items(),
            'deadline_days' => 14,
            'accept_contract' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.is_interest', false)
            ->assertJsonStructure(['data' => ['contract' => ['agent_accepted_at', 'client_accepted_at']]]);

        $acceptance = ContractAcceptance::query()->where('party', ContractAcceptance::PARTY_AGENT)->firstOrFail();
        $this->assertSame($agent->id, $acceptance->user_id);
        $this->assertSame($order->id, $acceptance->order_id);
        $this->assertSame($offer->id, $acceptance->offer_id);
        $this->assertSame('480000.00', $acceptance->total);
        $this->assertSame(64, strlen($acceptance->hash));
        $this->assertSame('Backprint 27x98', $acceptance->snapshot['items'][0]['name']);
        $this->assertNotNull($acceptance->accepted_at);
    }

    public function test_client_previews_contract_for_priced_offer(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer, $client] = $this->interestOffer($agent, $profile, $category);
        $offer->update(['price' => 480_000, 'deadline_days' => 14]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 2, 'unit_price' => 240_000, 'sort_order' => 0,
        ]);

        $this->actingAs($client)->getJson("/api/v1/offers/{$offer->id}/contract-preview")
            ->assertOk()
            ->assertJsonPath('data.total', '480000.00')
            ->assertJsonPath('data.deadline_label', '14 kun ('.now()->addDays(14)->format('d.m.Y').' gacha)');
    }

    public function test_client_cannot_preview_contract_for_interest_offer(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer, $client] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($client)->getJson("/api/v1/offers/{$offer->id}/contract-preview")
            ->assertUnprocessable();
    }

    public function test_other_user_cannot_preview_contract(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [, $offer] = $this->interestOffer($agent, $profile, $category);
        $offer->update(['price' => 480_000]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/v1/offers/{$offer->id}/contract-preview")
            ->assertNotFound();
    }

    public function test_accepting_offer_requires_contract_acceptance(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer, $client] = $this->interestOffer($agent, $profile, $category);
        $offer->update(['price' => 480_000]);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
        $this->assertSame(0, ContractAcceptance::query()->count());
    }

    public function test_accepting_offer_logs_client_acceptance_and_activates_order(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer, $client] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => $this->items(),
            'deadline_days' => 14,
            'accept_contract' => true,
        ])->assertOk();

        $preview = $this->actingAs($client)->getJson("/api/v1/offers/{$offer->id}/contract-preview")
            ->assertOk()
            ->json('data');

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept", [
            'accept_contract' => true,
            'contract_hash' => $preview['hash'],
        ])->assertOk();

        $this->assertSame(OfferStatus::Accepted, $offer->fresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);

        // Both parties' taps are on record against the same document.
        $acceptances = ContractAcceptance::query()->where('offer_id', $offer->id)->get();
        $this->assertCount(2, $acceptances);
        $this->assertSame(
            $acceptances->firstWhere('party', ContractAcceptance::PARTY_AGENT)->hash,
            $acceptances->firstWhere('party', ContractAcceptance::PARTY_CLIENT)->hash,
        );
        $this->assertSame($client->id, $acceptances->firstWhere('party', ContractAcceptance::PARTY_CLIENT)->user_id);
    }

    public function test_accept_is_rejected_when_the_contract_changed(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer, $client] = $this->interestOffer($agent, $profile, $category);
        $offer->update(['price' => 480_000, 'deadline_days' => 14]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => 2, 'unit_price' => 240_000, 'sort_order' => 0,
        ]);

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept", [
            'accept_contract' => true,
            'contract_hash' => str_repeat('0', 64),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
    }

    public function test_admin_order_detail_exposes_acceptance_audit_trail(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer, $client] = $this->interestOffer($agent, $profile, $category);
        $admin = User::factory()->admin()->create();

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => $this->items(),
            'deadline_days' => 14,
            'accept_contract' => true,
        ])->assertOk();

        $this->actingAs($client)->postJson("/api/v1/offers/{$offer->id}/accept", [
            'accept_contract' => true,
        ])->assertOk();

        $response = $this->actingAs($admin)->getJson("/api/v1/admin/orders/{$order->id}")->assertOk();

        $acceptances = collect($response->json('data.offers.0.contract_acceptances'));
        $this->assertCount(2, $acceptances);
        $this->assertSame(
            ['agent', 'client'],
            $acceptances->pluck('party')->sort()->values()->all(),
        );
        $this->assertSame($agent->id, $acceptances->firstWhere('party', 'agent')['user']['id']);
        $this->assertSame('480000.00', $acceptances->firstWhere('party', 'client')['total']);
        $this->assertNotNull($acceptances->firstWhere('party', 'client')['accepted_at']);
    }

    public function test_order_detail_exposes_offer_acceptance_state(): void
    {
        [$agent, $profile, $category] = $this->approvedAgent();
        [$order, $offer, $client] = $this->interestOffer($agent, $profile, $category);

        $this->actingAs($agent)->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => $this->items(),
            'deadline_days' => 14,
            'accept_contract' => true,
        ])->assertOk();

        $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.offers.0.contract.client_accepted_at', null)
            ->assertJsonStructure(['data' => ['offers' => [['contract' => ['agent_accepted_at']]]]]);

        $this->assertNotNull(
            $this->actingAs($client)->getJson("/api/v1/orders/{$order->id}")
                ->json('data.offers.0.contract.agent_accepted_at'),
        );
    }
}
