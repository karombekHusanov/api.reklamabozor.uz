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
 * Faza 1: Tender access (manager-granted) + the Tezkor exclusive-claim route.
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
            ->assertJsonPath('data.can_release', false);
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

    // --- Tezkor claim --------------------------------------------------------

    public function test_first_agent_claims_and_second_gets_409(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();
        $first = $this->agent();
        $second = $this->agent();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($first))
            ->assertCreated()->assertJsonPath('data.is_interest', true);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($second))
            ->assertStatus(409);

        $fresh = $order->fresh();
        $this->assertSame($first->id, $fresh->claimed_agent_id);
        $this->assertNotNull($fresh->claimed_at);
        $this->assertSame(OrderStatus::OffersSent, $fresh->status);
        $this->assertSame(1, $order->offers()->count());
    }

    public function test_tezkor_claim_rejects_a_price(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", ['price' => 500000], $this->auth($this->agent()))
            ->assertUnprocessable();

        $this->assertNull($order->fresh()->claimed_agent_id);
    }

    public function test_feed_flags_claimed_orders_and_filters_by_route(): void
    {
        Http::fake();
        $claimed = $this->tezkorOrder();
        $tender = Order::factory()->status(OrderStatus::New)->create(['category_id' => null]);
        $first = $this->agent();
        $second = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$claimed->id}/offers", [], $this->auth($first))->assertCreated();

        $rows = collect($this->getJson('/api/v1/agent/orders?route=tezkor', $this->auth($second))
            ->assertOk()->json('data'));
        $this->assertSame([$claimed->id], $rows->pluck('id')->all());
        $this->assertTrue($rows[0]['claimed']);
        $this->assertFalse($rows[0]['claimed_by_me']);
        $this->assertFalse($rows[0]['can_offer']);

        $mine = collect($this->getJson('/api/v1/agent/orders?route=tezkor', $this->auth($first))->json('data'));
        $this->assertTrue($mine[0]['claimed_by_me']);

        $tenders = collect($this->getJson('/api/v1/agent/orders?route=tender', $this->auth($second))->json('data'));
        $this->assertSame([$tender->id], $tenders->pluck('id')->all());

        $this->getJson("/api/v1/orders/showcase/{$claimed->id}", $this->auth($second))
            ->assertJsonPath('data.can_offer', false)
            ->assertJsonPath('data.claimed', true);
    }

    public function test_client_sees_the_claiming_agent_with_phone(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();
        $agent->forceFill(['phone' => '+998901234567'])->save();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();

        $this->getJson("/api/v1/orders/{$order->id}", $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.claim.agent_id', $agent->id)
            ->assertJsonPath('data.claim.agent.phone', '+998901234567')
            ->assertJsonPath('data.can_release', true)
            ->assertJsonPath('data.can_close', true)
            ->assertJsonPath('data.offers.0.can_accept', false);
    }

    public function test_client_release_reopens_the_request(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();
        $other = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();

        $this->postJson("/api/v1/orders/{$order->id}/release", [], $this->auth($client))
            ->assertOk()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.claim', null);

        $this->assertNull($order->fresh()->claimed_agent_id);
        $this->assertSame(OfferStatus::Withdrawn, $order->offers()->first()->status);

        // Open for everyone again.
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($other))->assertCreated();
        $this->assertSame($other->id, $order->fresh()->claimed_agent_id);
    }

    public function test_agent_release_reopens_the_request(): void
    {
        Http::fake();
        $order = $this->tezkorOrder();
        $agent = $this->agent();
        $outsider = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();

        $this->postJson("/api/v1/agent/orders/{$order->id}/release", [], $this->auth($outsider))->assertNotFound();

        $this->postJson("/api/v1/agent/orders/{$order->id}/release", [], $this->auth($agent))
            ->assertOk()->assertJsonPath('data.claimed', false);

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertNull($order->fresh()->claimed_agent_id);
    }

    public function test_client_closes_a_claimed_request_without_payout_or_acts(): void
    {
        Http::fake();
        $client = User::factory()->create();
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();

        // Nothing to close before someone claims.
        $this->postJson("/api/v1/orders/{$order->id}/close", [], $this->auth($client))->assertUnprocessable();

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();

        $this->postJson("/api/v1/orders/{$order->id}/close", [], $this->auth($client))
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(0, $order->payouts()->count());
        $this->assertSame(0, $order->documents()->count());
        $this->assertNull($order->contract()->first());
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

    public function test_stale_claim_reminder_reaches_the_client_once(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $client = User::factory()->create(['telegram_id' => 777000222]);
        $order = $this->tezkorOrder($client);
        $agent = $this->agent();
        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $this->auth($agent))->assertCreated();
        $order->forceFill(['claimed_at' => now()->subDays(4)])->save();

        $this->artisan('orders:remind-stale')->assertSuccessful();
        $this->assertNotNull($order->fresh()->stale_reminder_sent_at);
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 777000222
            && str_contains($request['text'] ?? '', 'band'));

        $this->artisan('orders:remind-stale')->assertSuccessful();
    }
}
