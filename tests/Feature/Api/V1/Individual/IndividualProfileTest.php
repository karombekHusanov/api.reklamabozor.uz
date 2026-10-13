<?php

namespace Tests\Feature\Api\V1\Individual;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderRoute;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IndividualProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('files.disk'));
        Storage::fake('local');
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function user(array $overrides = []): array
    {
        $user = User::factory()->create([
            'role' => 'client',
            'roles' => ['client'],
            'first_name' => 'Jamshid',
            'phone' => '+998901112233',
            ...$overrides,
        ]);

        return [$user, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return [
            'full_name' => 'Jamshid Karimov',
            'alt_contact' => '@jamshid',
            'passport_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'selfie_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'category_ids' => [Category::factory()->create()->id],
            ...$overrides,
        ];
    }

    public function test_individual_profile_is_created_pending_and_grants_agent_role(): void
    {
        [$user, $headers] = $this->user();

        $this->postJson('/api/v1/individual/profile', $this->payload($user), $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_individual', true)
            ->assertJsonPath('data.director_name', 'Jamshid Karimov')
            ->assertJsonPath('data.alt_contact', '@jamshid')
            // Legal-entity fields are hidden for individuals.
            ->assertJsonPath('data.inn', null)
            ->assertJsonPath('data.bank_name', null)
            ->assertJsonPath('data.legal_form', null)
            ->assertJsonPath('data.offer', null)
            ->assertJsonPath('data.contract', null);

        $profile = AgentProfile::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(ProviderType::Individual, $profile->provider_type);
        $this->assertSame(AgentProfileStatus::Pending, $profile->status);
        $this->assertNotNull($profile->selfie_file_id);
        $this->assertNotNull($profile->director_passport_file_id);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole(Role::Agent));
        $this->assertSame(Role::Agent, $fresh->role);
    }

    public function test_requires_passport_selfie_and_a_category(): void
    {
        [, $headers] = $this->user();

        $this->postJson('/api/v1/individual/profile', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'passport_file_id', 'selfie_file_id', 'category_ids']);
    }

    public function test_duplicate_profile_is_rejected(): void
    {
        [$user, $headers] = $this->user();
        AgentProfile::factory()->for($user)->individual()->approved()->create();

        $this->postJson('/api/v1/individual/profile', $this->payload($user), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');
    }

    public function test_resubmit_moves_a_rejected_profile_back_to_pending(): void
    {
        [$user, $headers] = $this->user();
        AgentProfile::factory()->for($user)->individual()->rejected()->create();

        $this->putJson('/api/v1/individual/profile', $this->payload($user, [
            'full_name' => 'Jamshid Qayta',
        ]), $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.director_name', 'Jamshid Qayta')
            ->assertJsonPath('data.rejection_reason', null);
    }

    public function test_resubmit_is_blocked_for_approved_profiles(): void
    {
        [$user, $headers] = $this->user();
        AgentProfile::factory()->for($user)->individual()->approved()->create();

        $this->putJson('/api/v1/individual/profile', $this->payload($user), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // --- Tender gate -------------------------------------------------------

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function provider(callable $state): array
    {
        [$user, $headers] = $this->user(['phone' => '+99890'.fake()->numerify('#######')]);
        $state(AgentProfile::factory()->for($user));

        return [$user, $headers];
    }

    public function test_individual_cannot_respond_to_a_tender_order(): void
    {
        [, $headers] = $this->provider(fn ($f) => $f->individual()->approved()->create());
        $order = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tender]);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order')
            ->assertJsonFragment(['Individual executors can respond to Tezkor requests only.']);

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_individual_can_respond_to_a_tezkor_order(): void
    {
        [, $headers] = $this->provider(fn ($f) => $f->individual()->approved()->create());
        $order = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tezkor]);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $headers)
            ->assertCreated();

        $this->assertDatabaseCount('offers', 1);
    }

    public function test_legal_entity_with_bank_requisites_can_respond_to_a_tender_order(): void
    {
        // Regression: the existing agency flow must keep working.
        [, $headers] = $this->provider(fn ($f) => $f->approved()->create());
        $order = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tender]);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $headers)
            ->assertCreated();

        $this->assertDatabaseCount('offers', 1);
    }

    public function test_legal_entity_without_bank_requisites_cannot_respond_to_a_tender_order(): void
    {
        [, $headers] = $this->provider(fn ($f) => $f->approved()->create([
            'bank_name' => null,
            'bank_account' => null,
            'mfo' => null,
        ]));
        $order = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tender]);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order')
            ->assertJsonFragment(['Complete your bank requisites before responding to Tender orders.']);

        $this->assertDatabaseCount('offers', 0);
    }

    // --- can_offer consistency (showcase detail) ---------------------------

    public function test_showcase_can_offer_matches_the_tender_gate(): void
    {
        [, $individualHeaders] = $this->provider(fn ($f) => $f->individual()->approved()->create());
        [, $agentHeaders] = $this->provider(fn ($f) => $f->approved()->create());

        $tender = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tender]);
        $tezkor = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tezkor]);

        // Individual: blocked on tender, allowed on tezkor.
        $this->getJson("/api/v1/orders/showcase/{$tender->id}", $individualHeaders)
            ->assertOk()->assertJsonPath('data.can_offer', false);
        $this->getJson("/api/v1/orders/showcase/{$tezkor->id}", $individualHeaders)
            ->assertOk()->assertJsonPath('data.can_offer', true);

        // Legal entity with bank requisites: allowed on tender. Reset the auth
        // guards so the next request resolves the agent's token, not the cached
        // individual from the requests above.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/orders/showcase/{$tender->id}", $agentHeaders)
            ->assertOk()->assertJsonPath('data.can_offer', true);
    }

    // --- deprecations ------------------------------------------------------

    public function test_designer_self_serve_route_is_disabled(): void
    {
        [$user, $headers] = $this->user();

        $this->postJson('/api/v1/designer/profile', [
            'category_ids' => [Category::factory()->designer()->create()->id],
        ], $headers)
            ->assertNotFound();
    }

    public function test_designer_role_is_not_self_selectable(): void
    {
        $this->assertNotContains(Role::Designer, Role::selfSelectable());
        $this->assertFalse(Role::Designer->isSelfSelectable());
    }
}
