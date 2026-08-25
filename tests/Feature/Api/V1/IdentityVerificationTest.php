<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => Role::Client,
            'roles' => [Role::Client],
        ]);
    }

    private function enableMyId(): void
    {
        config([
            'services.myid.enabled' => true,
            'services.myid.base_url' => 'https://devmyid.uz',
            'services.myid.web_url' => 'https://web.myid.uz',
            'services.myid.client_id' => 'cid',
            'services.myid.client_secret' => 'secret',
            'services.myid.scope' => 'common_data',
            'services.myid.redirect_uri' => 'https://api.reklamabozor.uz/api/v1/identity/myid/callback',
            'services.telegram.mini_app_url' => 'https://app.reklamabozor.uz',
        ]);
    }

    public function test_session_endpoint_is_hidden_when_myid_is_disabled(): void
    {
        $user = $this->user();

        $this->postJson('/api/v1/me/identity/session', [], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertNotFound();
    }

    public function test_starting_a_session_returns_a_session_id(): void
    {
        $this->enableMyId();
        Http::fake([
            'devmyid.uz/api/v1/oauth2/access-token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'devmyid.uz/api/v1/web/sessions' => Http::response(['session_id' => 'sess-123']),
        ]);

        $user = $this->user();

        $this->postJson('/api/v1/me/identity/session', [], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])
            ->assertCreated()
            ->assertJsonPath('data.session_id', 'sess-123')
            ->assertJsonPath('data.web_url', 'https://web.myid.uz')
            ->assertJsonPath('data.verification.status', 'pending');

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'session_id' => 'sess-123',
            'status' => 'pending',
        ]);
    }

    public function test_finalize_grants_the_badge_on_a_verified_profile(): void
    {
        $this->enableMyId();
        Http::fake([
            'devmyid.uz/api/v1/oauth2/access-token' => Http::response(['access_token' => 'user-tok', 'expires_in' => 3600]),
            'devmyid.uz/api/v1/users/me' => Http::response([
                'result_code' => 1,
                'comparison_value' => 0.97,
                'reuid' => 'reuid-xyz',
                'profile' => [
                    'common_data' => [
                        'first_name' => 'Ali',
                        'last_name' => 'Valiyev',
                        'pinfl' => '30101003300128',
                    ],
                    'doc_data' => ['pass_data' => 'AB1234567'],
                ],
            ]),
        ]);

        $user = $this->user();

        $this->postJson('/api/v1/me/identity/verify', ['auth_code' => 'code-123'], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.pinfl', '30101003300128')
            ->assertJsonPath('data.verified_full_name', 'Valiyev Ali');

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$this->token($user)])
            ->assertJsonPath('data.identity_verified', true)
            ->assertJsonPath('data.identity_status', 'verified');
    }

    public function test_authorize_returns_a_myid_authorization_url(): void
    {
        $this->enableMyId();
        $user = $this->user();

        $response = $this->postJson('/api/v1/me/identity/authorize', [], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertOk();

        $url = $response->json('data.authorization_url');
        $this->assertStringContainsString('https://devmyid.uz/api/v1/oauth2/authorization', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=cid', $url);

        // State is persisted so the public callback can resolve the user.
        $verification = $user->fresh()->identityVerification;
        $this->assertNotNull($verification->state);
        $this->assertStringContainsString('state='.$verification->state, $url);
        $this->assertEquals('pending', $verification->status->value);
    }

    public function test_redirect_callback_grants_the_badge_by_state(): void
    {
        $this->enableMyId();
        $user = $this->user();

        // Start the redirect flow to mint a state (no HTTP yet).
        $this->postJson('/api/v1/me/identity/authorize', [], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])->assertOk();
        $state = $user->fresh()->identityVerification->state;

        Http::fake([
            'devmyid.uz/api/v1/oauth2/access-token' => Http::response(['access_token' => 'user-tok', 'expires_in' => 3600]),
            'devmyid.uz/api/v1/users/me' => Http::response([
                'result_code' => 1,
                'profile' => ['common_data' => ['first_name' => 'Ali', 'last_name' => 'Valiyev', 'pinfl' => '30101003300128']],
            ]),
        ]);

        // MyID redirects the browser back with ?code&state.
        $this->get('/api/v1/identity/myid/callback?code=code-123&state='.$state)
            ->assertRedirect('https://app.reklamabozor.uz/?identity=verified');

        $verification = $user->fresh()->identityVerification;
        $this->assertEquals('verified', $verification->status->value);
        $this->assertNull($verification->state); // single-use, cleared
    }

    public function test_redirect_callback_rejects_an_unknown_state(): void
    {
        $this->enableMyId();

        $this->get('/api/v1/identity/myid/callback?code=code-123&state=bogus')
            ->assertRedirect('https://app.reklamabozor.uz/?identity=failed');
    }

    public function test_finalize_marks_failed_when_checks_do_not_pass(): void
    {
        $this->enableMyId();
        Http::fake([
            'devmyid.uz/api/v1/oauth2/access-token' => Http::response(['access_token' => 'user-tok', 'expires_in' => 3600]),
            'devmyid.uz/api/v1/users/me' => Http::response([
                'result_code' => 3,
                'result_note' => 'Liveness failed',
            ]),
        ]);

        $user = $this->user();

        $this->postJson('/api/v1/me/identity/verify', ['auth_code' => 'code-123'], [
            'Authorization' => 'Bearer '.$this->token($user),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 3);

        $this->assertFalse($user->fresh()->isIdentityVerified());
    }
}
