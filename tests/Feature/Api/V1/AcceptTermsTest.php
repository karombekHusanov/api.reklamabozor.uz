<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_needs_to_accept_terms(): void
    {
        $user = User::factory()->create(['accepted_terms_version' => null]);

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.needs_terms', true)
            ->assertJsonPath('data.terms_version', 'v1')
            ->assertJsonPath('data.accepted_terms_version', null);
    }

    public function test_accepting_terms_records_version_and_clears_the_gate(): void
    {
        $user = User::factory()->create(['accepted_terms_version' => null]);

        $this->actingAs($user)->postJson('/api/v1/me/accept-terms')
            ->assertOk()
            ->assertJsonPath('data.needs_terms', false)
            ->assertJsonPath('data.accepted_terms_version', 'v1');

        $user->refresh();
        $this->assertSame('v1', $user->accepted_terms_version);
        $this->assertNotNull($user->accepted_terms_at);
    }

    public function test_outdated_accepted_version_reprompts(): void
    {
        $user = User::factory()->create(['accepted_terms_version' => 'v0']);

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.needs_terms', true);
    }

    public function test_accept_terms_requires_auth(): void
    {
        $this->postJson('/api/v1/me/accept-terms')->assertUnauthorized();
    }
}
