<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Enums\PersonType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\LegalEntityVerification;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza 1: Tender access (manager-granted) + the Tezkor route (open otkliks,
 * the client picks the agency).
 */
class TezkorAndTenderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): array
    {
        // The token guard memoises the resolved user per app instance; drop it
        // so consecutive requests in one test can act as different users.
        app('auth')->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function agent(?Category $category = null): User
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        if ($category !== null) {
            $profile->categories()->attach($category);
        }

        return $user;
    }

    private function verifiedLegalClient(): User
    {
        $client = User::factory()->create(['person_type' => PersonType::LegalEntity]);
        LegalEntityVerification::factory()->approved()->for($client)->create();

        return $client;
    }

    private function grantedClient(): User
    {
        $client = $this->verifiedLegalClient();
        $client->forceFill(['tender_access_at' => now()])->save();

        return $client;
    }

    private function tezkorOrder(?User $client = null): Order
    {
        return Order::factory()
            ->for($client ?? User::factory()->create(), 'client')
            ->status(OrderStatus::New)
            ->create(['category_id' => null, 'route' => OrderRoute::Tezkor, 'payment_state' => OrderPaymentState::NotRequired]);
    }

    // --- Tender access -------------------------------------------------------

    public function test_unpermitted_account_cannot_create_a_tender(): void
    {
        Http::fake();
        $client = User::factory()->create();

        $this->postJson('/api/v1/orders', ['description' => 'Banner kerak', 'route' => 'tender'], $this->auth($client))
            ->assertForbidden();

        $this->assertSame(0, Order::count());
    }

    public function test_unpermitted_account_defaults_to_tezkor(): void
    {
        Http::fake();
        $client = User::factory()->create();

        $this->postJson('/api/v1/orders', ['description' => 'Banner kerak'], $this->auth($client))
            ->assertCreated()
            ->assertJsonPath('data.route', 'tezkor')
            ->assertJsonPath('data.payment_state', 'not_required')
            ->assertJsonPath('data.claim', null)
            ->assertJsonPath('data.can_close', true);
    }

    public function test_permitted_account_creates_both_routes_and_defaults_to_tender(): void
    {
        Http::fake();
        $client = $this->grantedClient();
        $headers = $this->auth($client);

        $this->postJson('/api/v1/orders', ['description' => 'Katta loyiha'], $headers)
            ->assertCreated()->assertJsonPath('data.route', 'tender');
        $this->postJson('/api/v1/orders', ['description' => 'Katta loyiha', 'route' => 'tender'], $headers)
            ->assertCreated()->assertJsonPath('data.route', 'tender');
        $this->postJson('/api/v1/orders', ['description' => 'Tezkor ish', 'route' => 'tezkor'], $headers)
            ->assertCreated()->assertJsonPath('data.route', 'tezkor');
    }

    public function test_me_exposes_tender_access_status(): void
    {
        $plain = User::factory()->create();
        $this->getJson('/api/v1/auth/me', $this->auth($plain))
            ->assertJsonPath('data.can_create_tender', false)
            ->assertJsonPath('data.tender_access_status', 'none')
            ->assertJsonMissingPath('data.tender_access');

        $pending = User::factory()->create(['person_type' => PersonType::LegalEntity]);
        LegalEntityVerification::factory()->for($pending)->create();
        $this->getJson('/api/v1/auth/me', $this->auth($pending))
            ->assertJsonPath('data.tender_access_status', 'pending');

        $this->getJson('/api/v1/auth/me', $this->auth($this->grantedClient()))
            ->assertJsonPath('data.can_create_tender', true)
            ->assertJsonPath('data.tender_access_status', 'granted');
    }

    public function test_grant_requires_a_verified_legal_entity(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $individual = User::factory()->create();

        $this->postJson("/api/v1/admin/users/{$individual->id}/tender-access", ['note' => 'Suhbat o\'tdi'], $this->auth($admin))
            ->assertUnprocessable();
        $this->assertFalse($individual->fresh()->canCreateTender());
    }

    public function test_grant_requires_a_note_and_records_the_audit_trail(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $client = $this->verifiedLegalClient();
        $client->forceFill(['telegram_id' => 555000111])->save();
        $headers = $this->auth($admin);

        $this->postJson("/api/v1/admin/users/{$client->id}/tender-access", [], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $this->postJson("/api/v1/admin/users/{$client->id}/tender-access", ['note' => 'Suhbat o\'tdi'], $headers)
            ->assertOk()
            ->assertJsonPath('data.can_create_tender', true)
            ->assertJsonPath('data.tender_access.granted_by', $admin->id)
            ->assertJsonPath('data.tender_access.note', 'Suhbat o\'tdi');

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 555000111
            && str_contains($request['text'] ?? '', 'Tender'));
    }

    public function test_non_admin_cannot_grant(): void
    {
        $client = $this->verifiedLegalClient();

        $this->postJson("/api/v1/admin/users/{$client->id}/tender-access", ['note' => 'x y z'], $this->auth($client))
            ->assertForbidden();
    }

    public function test_revoke_blocks_new_tenders_but_keeps_in_flight_ones(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $client = $this->grantedClient();
        $tender = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();

        $this->deleteJson("/api/v1/admin/users/{$client->id}/tender-access", [], $this->auth($admin))
            ->assertOk()
            ->assertJsonPath('data.can_create_tender', false)
            ->assertJsonPath('data.tender_access_status', 'revoked');

        $this->postJson('/api/v1/orders', ['description' => 'Yana', 'route' => 'tender'], $this->auth($client))
            ->assertForbidden();

        // The tender that was already running is untouched and still accepts offers.
        $this->assertSame(OrderRoute::Tender, $tender->fresh()->route);
        $offer = Offer::factory()->for($tender)->create(['price' => 1_000_000]);
        $this->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true], $this->auth($client))
            ->assertOk();
    }

    public function test_admin_users_can_be_filtered_by_tender_access(): void
    {
        $admin = User::factory()->admin()->create();
        $granted = $this->grantedClient();
        User::factory()->create();

        $response = $this->getJson('/api/v1/admin/users?tender_access=granted', $this->auth($admin))->assertOk();

        $this->assertSame([$granted->id], collect($response->json('data.items'))->pluck('id')->all());
    }

    // --- Tezkor: open otkliks, client picks ---------------------------------

    public function test_any_number_of_agents_can_respond_to_a_tezkor_request(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();
        $first = $this->agent();
        $second = $this->agent();
        $third = $this->agent();

        foreach ([$first, $second, $third] as $agent) {
            $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))
                ->assertCreated()->assertJsonPath('data.is_interest', true);
        }

        $fresh = $order->fresh();
        $this->assertNull($fresh->claimed_agent_id);
        $this->assertSame(OrderStatus::OffersSent, $fresh->status);
        $this->assertSame(3, $order->offers()->where('status', OfferStatus::Pending)->count());

        // Still one otklik per agent.
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($first))
            ->assertUnprocessable();
    }

    public function test_tezkor_otklik_rejects_a_price(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", ['price' => 500000], $this->auth($this->agent()))
            ->assertUnprocessable();

        $this->assertSame(0, $order->offers()->count());
    }

    public function test_feed_stays_open_after_an_otklik_and_filters_by_route(): void
    {
        Http::fake();
        $tezkor = $this->tezkorOrder();
        $tender = Order::factory()->status(OrderStatus::New)->create(['category_id' => null]);
        $first = $this->agent();
        $second = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$tezkor->id}/offers", [], $this->auth($first))->assertCreated();

        $rows = collect($this->getJson('/api/v1/agent/orders?route=tezkor', $this->auth($second))
            ->assertOk()->json('data'));
        $this->assertSame([$tezkor->id], $rows->pluck('id')->all());
        $this->assertTrue($rows[0]['can_offer']);
        $this->assertArrayNotHasKey('claimed', $rows[0]);

        $tenders = collect($this->getJson('/api/v1/agent/orders?route=tender', $this->auth($second))->json('data'));
        $this->assertSame([$tender->id], $tenders->pluck('id')->all());

        $this->getJson("/api/v1/orders/showcase/{$tezkor->id}", $this->auth($second))
            ->assertJsonPath('data.can_offer', true);
    }

    public function test_client_sees_all_otkliks_and_can_pick(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $first = $this->agent();
        $second = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($first))->assertCreated();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($second))->assertCreated();

        $this->getJson("/api/v1/orders/{$order->id}", $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.claim', null)
            ->assertJsonPath('data.can_close', true)
            ->assertJsonCount(2, 'data.offers')
            ->assertJsonPath('data.offers.0.can_accept', false);
    }

    public function test_client_picks_an_agency_and_the_rest_are_rejected(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $picked = $this->agent();
        $picked->forceFill(['phone' => '+998901234567'])->save();
        $other = $this->agent();

        // Nothing to pick before anyone responds.
        $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => 999], $this->auth($client))
            ->assertUnprocessable();

        $pickedOfferId = $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($picked))
            ->assertCreated()->json('data.id');
        $otherOfferId = $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($other))
            ->assertCreated()->json('data.id');

        // offer_id is required, and only the owner may close.
        $this->postJson("/api/v1/orders/{$order->id}/close", [], $this->auth($client))->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => $pickedOfferId], $this->auth($other))
            ->assertNotFound();

        $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => $pickedOfferId], $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_close', false)
            ->assertJsonPath('data.claim.agent_id', $picked->id)
            ->assertJsonPath('data.claim.agent.phone', '+998901234567');

        $fresh = $order->fresh();
        $this->assertSame($picked->id, $fresh->claimed_agent_id);
        $this->assertSame(OfferStatus::Accepted, Offer::find($pickedOfferId)->status);
        $this->assertSame(OfferStatus::Rejected, Offer::find($otherOfferId)->status);
        $this->assertSame(0, $fresh->payouts()->count());
        $this->assertSame(0, $fresh->documents()->count());
        $this->assertNull($fresh->contract()->first());

        // Already closed.
        $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => $otherOfferId], $this->auth($client))
            ->assertUnprocessable();
    }

    public function test_client_cannot_pick_a_withdrawn_otklik(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();
        $offerId = $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))
            ->assertCreated()->json('data.id');

        $this->getJson("/api/v1/agent/offers/{$offerId}", $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.order.route', 'tezkor')
            ->assertJsonPath('data.can_withdraw', true);

        $this->postJson("/api/v1/agent/offers/{$offerId}/withdraw", [], $this->auth($agent))->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/close", ['offer_id' => $offerId], $this->auth($client))
            ->assertUnprocessable();
    }

    public function test_agent_release_and_close_routes_are_gone(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();
        $agent = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();

        $this->postJson("/api/v1/agent/orders/{$order->id}/release", [], $this->auth($agent))->assertNotFound();
        $this->postJson("/api/v1/agent/orders/{$order->id}/close", [], $this->auth($agent))->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/release", [], $this->auth($order->client))->assertNotFound();
    }

    public function test_tezkor_accept_pay_pricelist_and_amendment_paths_are_422(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();
        $offer = $order->offers()->first();
        $item = ['name' => 'Banner', 'quantity' => 1, 'unit_price' => 100000];

        $this->postJson("/api/v1/offers/{$offer->id}/accept", ['accept_contract' => true], $this->auth($client))
            ->assertUnprocessable();
        $this->getJson("/api/v1/offers/{$offer->id}/contract-preview", $this->auth($client))->assertUnprocessable();
        $this->putJson("/api/v1/agent/offers/{$offer->id}/pricelist", [
            'items' => [$item], 'deadline_days' => 5, 'accept_contract' => true,
        ], $this->auth($agent))->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/pay/offline", ['method' => 'cash', 'percent' => 100], $this->auth($client))
            ->assertUnprocessable();
        $this->postJson("/api/v1/orders/{$order->id}/amendments", [
            'items' => [$item], 'deadline_days' => 5, 'accept_contract' => true,
        ], $this->auth($client))->assertUnprocessable();

        $this->assertSame(OrderStatus::OffersSent, $order->fresh()->status);
        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
    }
}
