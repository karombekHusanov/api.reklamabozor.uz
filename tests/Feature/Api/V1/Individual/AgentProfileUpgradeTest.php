<?php

namespace Tests\Feature\Api\V1\Individual;

use App\Enums\AgentContractStatus;
use App\Enums\AgentProfileStatus;
use App\Enums\AgentProfileUpgradeStatus;
use App\Enums\OrderRoute;
use App\Enums\PersonType;
use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\AgentProfileUpgrade;
use App\Models\Category;
use App\Models\File;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An approved individual executor raised to the legal-entity track: the
 * request is reviewed while the profile keeps working, and approval upgrades
 * the SAME profile (reputation, categories, history stay).
 */
class AgentProfileUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('files.disk'));
        Storage::fake((string) config('files.private_disk'));
        Http::fake();
    }

    /**
     * @return array{0: User, 1: AgentProfile, 2: array<string, string>}
     */
    private function individual(AgentProfileStatus $status = AgentProfileStatus::Approved): array
    {
        $user = User::factory()->create([
            'role' => Role::Agent,
            'roles' => [Role::Client, Role::Agent],
            'phone' => '+998901112233',
        ]);
        $profile = AgentProfile::factory()->for($user)->individual()->create([
            'status' => $status,
            'company_name' => null,
            'director_name' => 'Jamshid Karimov',
        ]);

        return [$user, $profile, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return [
            'company_name' => 'Karimov Media',
            'legal_form' => 'MChJ',
            'inn' => '123456789',
            'legal_address' => 'Toshkent, Chilonzor 1',
            'director_name' => 'Jamshid Karimov',
            'director_pinfl' => '12345678901234',
            'director_position' => 'Direktor',
            'director_passport' => 'AA1234567',
            'director_passport_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'registration_certificate_file_id' => File::factory()->for($user, 'uploader')->create()->id,
            'bank_name' => 'Kapitalbank',
            'bank_account' => '20208000123456789012',
            'mfo' => '01158',
            'phone' => '+998901112233',
            'accept_offer' => true,
            ...$overrides,
        ];
    }

    private function adminHeaders(): array
    {
        // Requests in one test share the resolved user — drop the cached one.
        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => Role::Admin, 'roles' => [Role::Admin]]);

        return ['Authorization' => 'Bearer '.$admin->createToken('a')->plainTextToken];
    }

    // --- request ------------------------------------------------------------

    public function test_approved_individual_submits_a_pending_request_and_keeps_working(): void
    {
        [$user, $profile, $headers] = $this->individual();

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.company_name', 'Karimov Media');

        // The profile itself is untouched: still an approved individual.
        $profile->refresh();
        $this->assertSame(ProviderType::Individual, $profile->provider_type);
        $this->assertSame(AgentProfileStatus::Approved, $profile->status);
        $this->assertNull($profile->company_name);

        $upgrade = AgentProfileUpgrade::sole();
        $this->assertNotNull($upgrade->offer_accepted_at);
        $this->assertSame(config('legal.agent_offer_version'), $upgrade->offer_version);

        $this->getJson('/api/v1/agent/profile', $headers)
            ->assertOk()
            ->assertJsonPath('data.upgrade.can_request', false)
            ->assertJsonPath('data.upgrade.latest.status', 'pending');
    }

    public function test_resubmitting_while_pending_revises_the_same_request(): void
    {
        [$user, , $headers] = $this->individual();

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)->assertCreated();
        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user, ['company_name' => 'Karimov Group']), $headers)
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Karimov Group');

        $this->assertSame(1, AgentProfileUpgrade::count());
    }

    public function test_request_requires_the_full_legal_kyc_and_the_offer(): void
    {
        [$user, , $headers] = $this->individual();

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user, [
            'inn' => null,
            'bank_account' => null,
            'accept_offer' => false,
        ]), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['inn', 'bank_account', 'accept_offer']);
    }

    public function test_only_an_approved_individual_can_request(): void
    {
        // Pending individual: not yet working — converts via the agent form instead.
        [$user, , $headers] = $this->individual(AgentProfileStatus::Pending);
        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');

        // Already a legal entity.
        $agent = AgentProfile::factory()->approved()->create();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($agent->user), [
            'Authorization' => 'Bearer '.$agent->user->createToken('t')->plainTextToken,
        ])->assertUnprocessable()->assertJsonValidationErrors('profile');

        $this->assertSame(0, AgentProfileUpgrade::count());
    }

    // --- review -------------------------------------------------------------

    public function test_approval_raises_the_same_profile_to_the_legal_track(): void
    {
        [$user, $profile, $headers] = $this->individual();
        $category = Category::factory()->create();
        $profile->categories()->attach($category);

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)->assertCreated();
        $upgrade = AgentProfileUpgrade::sole();

        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/approve", [], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $profile->refresh();
        $this->assertSame(ProviderType::Agent, $profile->provider_type);
        $this->assertSame(AgentProfileStatus::Approved, $profile->status);
        $this->assertSame('Karimov Media', $profile->company_name);
        $this->assertSame('20208000123456789012', $profile->bank_account);
        $this->assertSame(AgentContractStatus::Approved, $profile->contract_status);
        $this->assertTrue($profile->hasAcceptedCurrentOffer());
        $this->assertTrue($profile->canBidOnTender());
        // Same row: categories (and everything keyed by agent_profile_id) stay.
        $this->assertSame([$category->id], $profile->categories()->pluck('categories.id')->all());
        $this->assertSame(1, AgentProfile::where('user_id', $user->id)->count());

        $user = $user->fresh();
        $this->assertSame(PersonType::LegalEntity, $user->effectivePersonType());
        $this->assertTrue($user->isVerifiedLegalEntity());

        // Tender is open now.
        $tender = Order::factory()->create(['category_id' => null, 'route' => OrderRoute::Tender]);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/agent/orders/{$tender->id}/offers", [], $headers)->assertCreated();
    }

    public function test_rejection_leaves_the_profile_untouched_and_allows_a_new_request(): void
    {
        [$user, $profile, $headers] = $this->individual();

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)->assertCreated();
        $upgrade = AgentProfileUpgrade::sole();

        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/reject", [], $this->adminHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_reason');

        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/reject", [
            'rejection_reason' => 'Guvohnoma o\'qilmaydi.',
        ], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(ProviderType::Individual, $profile->fresh()->provider_type);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/agent/profile', $headers)
            ->assertJsonPath('data.upgrade.can_request', true)
            ->assertJsonPath('data.upgrade.latest.status', 'rejected')
            ->assertJsonPath('data.upgrade.latest.rejection_reason', 'Guvohnoma o\'qilmaydi.');

        // A fresh request is opened; the rejected one stays as history.
        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)->assertCreated();
        $this->assertSame(2, AgentProfileUpgrade::count());
    }

    public function test_a_reviewed_request_cannot_be_reviewed_again(): void
    {
        $upgrade = AgentProfileUpgrade::factory()->create();
        $admin = $this->adminHeaders();

        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/approve", [], $admin)->assertOk();
        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/approve", [], $admin)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/admin/agent-upgrades/{$upgrade->id}/reject", ['rejection_reason' => 'x'], $admin)
            ->assertUnprocessable();
    }

    public function test_admin_queue_filters_by_status_and_is_admin_only(): void
    {
        AgentProfileUpgrade::factory()->create();
        AgentProfileUpgrade::factory()->create(['status' => AgentProfileUpgradeStatus::Rejected, 'rejection_reason' => 'x']);

        $this->getJson('/api/v1/admin/agent-upgrades?status=pending', $this->adminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'pending')
            ->assertJsonPath('data.items.0.agent_profile.provider_type', 'individual');

        [, , $headers] = $this->individual();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/agent-upgrades', $headers)->assertForbidden();
    }

    public function test_upgrade_requests_notify_ops_and_the_executor(): void
    {
        config(['services.telegram.admin_chat_id' => '-100123', 'services.telegram.admin_events' => '*']);
        [$user, , $headers] = $this->individual();
        $user->forceFill(['telegram_id' => 555000111])->save();

        $this->postJson('/api/v1/agent/profile/upgrade', $this->payload($user), $headers)->assertCreated();
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) == '-100123'
            && str_contains($request['text'] ?? '', 'Karimov Media'));

        $this->postJson('/api/v1/admin/agent-upgrades/'.AgentProfileUpgrade::sole()->id.'/approve', [], $this->adminHeaders())
            ->assertOk();
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) == 555000111
            && str_contains($request['text'] ?? '', 'Yuridik maqom tasdiqlandi'));
    }
}
