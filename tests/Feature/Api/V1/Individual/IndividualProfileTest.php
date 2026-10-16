<?php

namespace Tests\Feature\Api\V1\Individual;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderRoute;
use App\Enums\PersonType;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\Order;
use App\Models\User;
use App\Services\Agent\AgentProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IndividualProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('files.disk'));
        Storage::fake((string) config('files.private_disk'));
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
        // Ready to work: a service category (the factory sets a map pin).
        $user->profile()->first()?->categories()->attach(Category::factory()->create());

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

    public function test_designer_role_and_track_no_longer_exist(): void
    {
        $this->assertNull(Role::tryFrom('designer'));
        $this->assertNull(ProviderType::tryFrom('designer'));
        $this->assertSame([Role::Client, Role::Seller], Role::selfSelectable());
    }

    // --- legal nature follows the KYC track --------------------------------

    public function test_individual_executor_reads_as_an_individual_not_a_legal_entity(): void
    {
        [$user] = $this->provider(fn ($f) => $f->individual()->approved()->create());
        $user->grantRole(Role::Agent);
        $user->role = Role::Agent;
        $user->save();
        $user = $user->fresh();

        $this->assertTrue($user->isIndividualExecutor());
        $this->assertFalse($user->hasRoleBoundLegalStatus());
        $this->assertSame(PersonType::Individual, $user->effectivePersonType());
        $this->assertFalse($user->isVerifiedLegalEntity());
        $this->assertNull($user->legalEntityStatus());
    }

    public function test_legal_entity_executor_reads_as_a_verified_legal_entity(): void
    {
        [$user] = $this->provider(fn ($f) => $f->approved()->create());
        $user->grantRole(Role::Agent);
        $user->role = Role::Agent;
        $user->save();
        $user = $user->fresh();

        $this->assertFalse($user->isIndividualExecutor());
        $this->assertSame(PersonType::LegalEntity, $user->effectivePersonType());
        $this->assertTrue($user->isVerifiedLegalEntity());
    }

    public function test_individual_executor_cannot_request_legal_entity_verification(): void
    {
        [$user, $headers] = $this->provider(fn ($f) => $f->individual()->approved()->create());
        $user->forceFill(['role' => Role::Agent, 'roles' => [Role::Client, Role::Agent], 'person_type' => PersonType::LegalEntity])->save();

        $this->postJson('/api/v1/me/legal-entity', ['inn' => '123456789'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('person_type');
    }

    // --- each form edits only its own track --------------------------------

    // Service-level: the HTTP form needs the full legal payload, which the
    // upgrade tests cover end to end.
    public function test_agent_form_switches_a_not_yet_approved_individual_to_the_legal_track(): void
    {
        [$user] = $this->provider(fn ($f) => $f->individual()->rejected()->create());

        $profile = app(AgentProfileService::class)->resubmit($user->profile, ['company_name' => 'X MChJ']);

        $this->assertSame(ProviderType::Agent, $profile->provider_type);
        $this->assertSame(AgentProfileStatus::Pending, $profile->status);
        $this->assertSame('X MChJ', $profile->company_name);
    }

    public function test_agent_form_cannot_resubmit_an_approved_individual(): void
    {
        [$user] = $this->provider(fn ($f) => $f->individual()->approved()->create());

        try {
            app(AgentProfileService::class)->resubmit($user->profile, ['company_name' => 'X MChJ']);
            $this->fail('Expected approved individuals to be routed to the upgrade request.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('profile', $e->errors());
        }

        $profile = $user->profile->fresh();
        $this->assertSame(ProviderType::Individual, $profile->provider_type);
        $this->assertSame(AgentProfileStatus::Approved, $profile->status);
    }

    public function test_individual_form_cannot_downgrade_a_legal_entity_application(): void
    {
        [$user, $headers] = $this->provider(fn ($f) => $f->rejected()->create());

        $this->putJson('/api/v1/individual/profile', $this->payload($user), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');

        $this->assertSame(ProviderType::Agent, $user->profile->fresh()->provider_type);
    }
}
