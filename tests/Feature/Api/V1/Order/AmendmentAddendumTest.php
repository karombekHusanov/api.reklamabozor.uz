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
use App\Models\OrderAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Faza 2: an amendment is a numbered addendum to the order's contract, read and
 * accepted click-wrap style by both parties.
 */
class AmendmentAddendumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake((string) config('files.disk'));
    }

    /**
     * @return array{0: User, 1: User, 2: Order}
     */
    private function activeDeal(): array
    {
        $category = Category::factory()->create();
        $client = User::factory()->create();
        $agent = User::factory()->create(['telegram_id' => 730100400]);
        $profile = AgentProfile::factory()->for($agent)->approved()->create(['company_name' => 'Nova Media']);
        $profile->categories()->attach($category);

        $order = Order::factory()->for($category)->for($client, 'client')
            ->status(OrderStatus::InProgress)
            ->create(['activated_at' => now()]);

        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
            'price' => 4_800_000,
            'deadline_days' => 12,
        ]);
        OfferItem::factory()->for($offer)->create([
            'name' => 'Backprint 27x98',
            'unit' => 'dona',
            'quantity' => 20,
            'unit_price' => 240_000,
            'sort_order' => 0,
        ]);

        return [$client, $agent, $order->fresh()];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(int $qty = 30): array
    {
        return [
            'items' => [['name' => 'Backprint 27x98', 'unit' => 'dona', 'quantity' => $qty, 'unit_price' => 240_000]],
            'deadline_days' => 12,
            'reason' => 'Hajm oshdi',
            'accept_contract' => true,
        ];
    }

    public function test_preview_returns_the_addendum_text_without_storing_anything(): void
    {
        [$client, , $order] = $this->activeDeal();

        $data = $this->actingAs($client)
            ->postJson("/api/v1/orders/{$order->id}/amendments/preview", $this->proposal())
            ->assertOk()
            ->json('data');

        $this->assertSame("Qo'shimcha kelishuv", $data['title']);
        $this->assertStringEndsWith('/DS1', $data['number']);
        $this->assertSame("RB-{$order->id}-".now()->format('Y'), $data['contract_number']);
        $this->assertStringContainsString('ajralmas qismi', $data['intro']);
        $this->assertSame('charge', $data['delta_direction']);
        $this->assertSame('2400000.00', $data['delta']);
        $this->assertSame(64, strlen($data['hash']));

        $this->assertSame(0, OrderAmendment::query()->count());
        $this->assertSame(0, ContractAcceptance::query()->whereNotNull('amendment_id')->count());
    }

    public function test_proposing_numbers_the_addendum_and_logs_the_initiator_acceptance(): void
    {
        [$client, , $order] = $this->activeDeal();

        $data = $this->actingAs($client)
            ->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal())
            ->assertCreated()
            ->json('data');

        $amendment = OrderAmendment::query()->firstOrFail();
        $this->assertSame("RB-{$order->id}-".now()->format('Y').'/DS1', $amendment->number);
        $this->assertSame(1, $amendment->sequence);
        $this->assertNotNull($amendment->contract_id);
        $this->assertNotNull($amendment->document_hash);
        $this->assertNotNull($amendment->expires_at);
        $this->assertNotNull($amendment->client_window_ends_at);

        $this->assertSame($amendment->number, $data['number']);
        $this->assertNotNull($data['acceptances']['client']);
        $this->assertNull($data['acceptances']['agent']);

        $acceptance = ContractAcceptance::query()->where('amendment_id', $amendment->id)->firstOrFail();
        $this->assertSame(ContractAcceptance::PARTY_CLIENT, $acceptance->party);
        $this->assertSame($amendment->document_hash, $acceptance->hash);
    }

    public function test_second_addendum_continues_the_numbering(): void
    {
        [$client, $agent, $order] = $this->activeDeal();

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(30))
            ->assertCreated();
        $first = OrderAmendment::query()->firstOrFail();

        $this->actingAs($agent)->postJson("/api/v1/amendments/{$first->id}/approve", ['accept_contract' => true])
            ->assertOk();

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal(35))
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2);

        $this->assertStringEndsWith('/DS2', OrderAmendment::query()->latest('id')->first()->number);
    }

    public function test_proposal_requires_the_click_wrap_confirmation(): void
    {
        [$client, , $order] = $this->activeDeal();
        $payload = $this->proposal();
        unset($payload['accept_contract']);

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        $this->assertSame(0, OrderAmendment::query()->count());
    }

    public function test_approval_requires_confirmation_and_matching_text(): void
    {
        [$client, $agent, $order] = $this->activeDeal();
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal())
            ->assertCreated();
        $amendment = OrderAmendment::query()->firstOrFail();

        // No confirmation.
        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        // Stale text.
        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/approve", [
            'accept_contract' => true,
            'document_hash' => str_repeat('0', 64),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_contract');

        $this->assertNull($amendment->fresh()->agent_approved_at);
    }

    public function test_both_parties_acceptances_are_logged_and_the_pdf_is_rendered(): void
    {
        [$client, $agent, $order] = $this->activeDeal();
        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $this->proposal())
            ->assertCreated();
        $amendment = OrderAmendment::query()->firstOrFail();

        $document = $this->actingAs($agent)->getJson("/api/v1/amendments/{$amendment->id}/document")
            ->assertOk()
            ->json('data');

        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/approve", [
            'accept_contract' => true,
            'document_hash' => $document['hash'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $amendment->refresh();
        $this->assertNotNull($amendment->pdf_file_id);
        $this->assertNotNull($amendment->applied_at);

        $parties = ContractAcceptance::query()->where('amendment_id', $amendment->id)
            ->pluck('party')->sort()->values()->all();
        $this->assertSame(['agent', 'client'], $parties);
    }

    public function test_operator_approval_is_logged_as_an_acceptance(): void
    {
        [$client, $agent, $order] = $this->activeDeal();
        $admin = User::factory()->admin()->create();

        // Deadline change → operator approval required.
        $payload = $this->proposal();
        $payload['deadline_days'] = 20;

        $this->actingAs($client)->postJson("/api/v1/orders/{$order->id}/amendments", $payload)
            ->assertCreated()
            ->assertJsonPath('data.requires_operator', true);

        $amendment = OrderAmendment::query()->firstOrFail();

        $this->actingAs($agent)->postJson("/api/v1/amendments/{$amendment->id}/approve", ['accept_contract' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($admin)->postJson("/api/v1/admin/amendments/{$amendment->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');

        $this->assertNotNull(
            ContractAcceptance::query()->where('amendment_id', $amendment->id)->where('party', 'operator')->first(),
        );
    }
}
