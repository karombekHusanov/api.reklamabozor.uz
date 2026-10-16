<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * AUDIT_2026-10-16 §2.1 — admin login brute-force guard: a tight per-IP route
 * throttle plus an email+IP lockout inside AuthService.
 */
class AdminLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    private function attempt(string $password, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/admin/login', [
                'email' => 'admin@example.com',
                'password' => $password,
            ]);
    }

    public function test_admin_login_route_has_its_own_five_per_minute_throttle(): void
    {
        $this->admin();

        $this->attempt('wrong')
            ->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '5');
    }

    public function test_telegram_login_route_has_its_own_throttle(): void
    {
        $this->postJson('/api/v1/auth/telegram', ['telegram_id' => 4242, 'first_name' => 'T'])
            ->assertCreated()
            ->assertHeader('X-RateLimit-Limit', '20');
    }

    public function test_sixth_attempt_from_one_ip_is_rejected_by_the_route_throttle(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('wrong')->assertUnprocessable();
        }

        $this->attempt('wrong')->assertStatus(429);
    }

    public function test_email_is_locked_after_five_failures_even_with_the_right_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin();

        for ($i = 0; $i < AuthService::ADMIN_LOGIN_MAX_ATTEMPTS; $i++) {
            $this->attempt('wrong')
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
        }

        $this->attempt('correct-password')
            ->assertStatus(429)
            ->assertJsonPath('errors.email.0', fn (string $message): bool => str_contains($message, 'Too many login attempts'));

        // Still locked while the lockout window runs.
        $this->travel(5)->minutes();
        $this->attempt('correct-password')->assertStatus(429);

        // Lockout expires after 15 minutes.
        $this->travel(11)->minutes();
        $this->attempt('correct-password')->assertOk();
    }

    public function test_successful_login_clears_the_failure_counter(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin();

        for ($i = 0; $i < 4; $i++) {
            $this->attempt('wrong')->assertUnprocessable();
        }

        $this->attempt('correct-password')->assertOk();

        // Four more failures would have tripped the lock had the counter survived.
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('wrong')->assertUnprocessable();
        }

        $this->attempt('correct-password')->assertOk();
    }

    public function test_lockout_is_scoped_to_the_email_and_ip_pair(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin();

        for ($i = 0; $i < AuthService::ADMIN_LOGIN_MAX_ATTEMPTS; $i++) {
            $this->attempt('wrong', '203.0.113.10')->assertUnprocessable();
        }

        $this->attempt('correct-password', '203.0.113.10')->assertStatus(429);

        // The same admin from another network is not locked out.
        $this->attempt('correct-password', '203.0.113.20')->assertOk();
    }

    public function test_unknown_email_failures_are_also_counted(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < AuthService::ADMIN_LOGIN_MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/admin/login', [
                'email' => 'nobody@example.com',
                'password' => 'guess-'.$i,
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/admin/login', [
            'email' => 'nobody@example.com',
            'password' => 'guess-final',
        ])->assertStatus(429);
    }
}
