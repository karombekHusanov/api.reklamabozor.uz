<?php

namespace App\Services\MyId;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates the optional MyID identity check and maps its result onto the
 * user's verification record + badge. The gateway details live in MyIdClient;
 * this service owns the domain flow (start session → finalize → grant badge).
 */
class IdentityVerificationService
{
    public function __construct(
        private readonly MyIdClient $client,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.myid.enabled') || $this->simulate();
    }

    /**
     * Dev/test mode: the card is shown and "verify" grants a fake verified
     * identity WITHOUT calling MyID (no contract creds needed). MUST be off in
     * real production. PROFILE_ARCHITECTURE / MyID docs.
     */
    public function simulate(): bool
    {
        return (bool) config('services.myid.simulate');
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('MyID identity verification is disabled.');
        }
    }

    /**
     * Grant a simulated verified identity (dev/test only). Bypasses MyID and
     * marks the user verified with placeholder data derived from their account.
     */
    public function simulateVerify(User $user): IdentityVerification
    {
        if (! $this->simulate()) {
            throw new RuntimeException('MyID simulate mode is disabled.');
        }

        $fullName = trim($user->first_name.' '.($user->last_name ?? '')) ?: 'Test User';

        /** @var IdentityVerification $verification */
        $verification = $user->identityVerification()->updateOrCreate([], [
            'status' => IdentityVerificationStatus::Verified,
            'verified_full_name' => $fullName,
            'pinfl' => null,
            'pass_data' => null,
            'comparison_value' => 0.99,
            'myid_reuid' => 'simulated',
            'failure_code' => null,
            'failure_note' => null,
            'state' => null,
            'state_expires_at' => null,
            'verified_at' => now(),
        ]);

        return $verification;
    }

    /**
     * Start (or restart) a WebSDK session for the user. Returns the record with
     * a fresh `session_id` the mini app iframe needs. Idempotent: reuses the
     * user's single row, resetting it to pending.
     */
    public function startSession(User $user, ?string $ipAddress = null): IdentityVerification
    {
        $this->assertEnabled();

        $session = $this->client->createWebSession(array_filter([
            'max_retries' => 3,
            'external_id' => (string) $user->id,
            'ip_address' => $ipAddress,
        ]));

        $sessionId = Arr::get($session, 'session_id');

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('MyID did not return a session_id.');
        }

        /** @var IdentityVerification $verification */
        $verification = $user->identityVerification()->updateOrCreate([], [
            'status' => IdentityVerificationStatus::Pending,
            'session_id' => $sessionId,
            'failure_code' => null,
            'failure_note' => null,
        ]);

        return $verification;
    }

    /**
     * Redirect (fallback) flow: mint a CSRF `state`, store it on the user's
     * record, and return the MyID authorization URL for the frontend to open
     * via WebApp.openLink. Used when the camera iframe is blocked in the
     * Telegram WebView. The public callback later resolves the user by `state`.
     */
    public function authorize(User $user): string
    {
        $this->assertEnabled();

        $redirectUri = (string) config('services.myid.redirect_uri');

        if ($redirectUri === '') {
            throw new RuntimeException('MYID_REDIRECT_URI is not configured.');
        }

        $state = Str::random(48);

        $user->identityVerification()->updateOrCreate([], [
            'status' => IdentityVerificationStatus::Pending,
            'state' => $state,
            'state_expires_at' => now()->addMinutes(5),
            'failure_code' => null,
            'failure_note' => null,
        ]);

        return $this->client->authorizationUrl($state, $redirectUri);
    }

    /**
     * Public callback of the redirect flow: resolve the pending record by its
     * (single-use, unexpired) `state`, then finalize with the matching
     * redirect_uri. Returns null when the state is unknown or expired.
     */
    public function finalizeByState(string $state, string $code): ?IdentityVerification
    {
        $this->assertEnabled();

        $verification = IdentityVerification::query()
            ->where('state', $state)
            ->where('state_expires_at', '>', now())
            ->first();

        if ($verification === null) {
            return null;
        }

        // Single-use: clear the state regardless of the outcome.
        $verification->forceFill(['state' => null, 'state_expires_at' => null])->save();

        return $this->finalize($verification->user, $code, (string) config('services.myid.redirect_uri'));
    }

    /**
     * Finalize after the user completes MyID: exchange the auth_code for the
     * verified government profile (server-side authoritative), then grant or
     * fail the badge. Never trusts the frontend's success signal. `redirectUri`
     * is required in the redirect flow (must match the authorization request).
     */
    public function finalize(User $user, string $authCode, ?string $redirectUri = null): IdentityVerification
    {
        $this->assertEnabled();

        $verification = $user->identityVerification()->firstOrNew([]);

        $tokens = $this->client->exchangeAuthCode($authCode, $redirectUri);
        $accessToken = Arr::get($tokens, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            return $this->markFailed($verification, null, 'No access token from MyID.');
        }

        $profile = $this->client->fetchUser($accessToken);

        return $this->applyProfile($verification, $profile);
    }

    /**
     * Map a MyID profile payload onto the record. Presence of a PINFL (or at
     * least a verified name) with a non-failing result_code counts as verified.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyProfile(IdentityVerification $verification, array $payload): IdentityVerification
    {
        // The profile may be nested under `profile` (inplace) or flat (/users/me).
        $profile = Arr::get($payload, 'profile', $payload);
        $common = Arr::get($profile, 'common_data', $profile);

        $pinfl = Arr::get($common, 'pinfl');
        $fullName = trim(implode(' ', array_filter([
            Arr::get($common, 'last_name'),
            Arr::get($common, 'first_name'),
            Arr::get($common, 'middle_name'),
        ])));
        $passData = Arr::get($profile, 'doc_data.pass_data') ?? Arr::get($payload, 'pass_data');
        $comparison = Arr::get($payload, 'comparison_value');
        $reuid = Arr::get($payload, 'reuid');
        $resultCode = Arr::get($payload, 'result_code');

        // result_code 1 = all checks passed. When the field is absent (pure
        // OAuth /users/me), fall back to "did we actually get an identity?".
        $passed = $resultCode === 1 || ($resultCode === null && ($pinfl || $fullName !== ''));

        if (! $passed) {
            return $this->markFailed(
                $verification,
                is_int($resultCode) ? $resultCode : null,
                is_string(Arr::get($payload, 'result_note')) ? Arr::get($payload, 'result_note') : null,
            );
        }

        $verification->fill([
            'status' => IdentityVerificationStatus::Verified,
            'pinfl' => is_string($pinfl) ? $pinfl : null,
            'verified_full_name' => $fullName !== '' ? $fullName : null,
            'pass_data' => is_string($passData) ? $passData : null,
            'comparison_value' => is_numeric($comparison) ? (float) $comparison : null,
            'myid_reuid' => is_string($reuid) ? $reuid : null,
            'failure_code' => null,
            'failure_note' => null,
            'verified_at' => now(),
        ]);
        $verification->save();

        return $verification;
    }

    private function markFailed(IdentityVerification $verification, ?int $code, ?string $note): IdentityVerification
    {
        $verification->fill([
            'status' => IdentityVerificationStatus::Failed,
            'failure_code' => $code,
            'failure_note' => $note,
            'verified_at' => null,
        ]);
        $verification->save();

        return $verification;
    }
}
