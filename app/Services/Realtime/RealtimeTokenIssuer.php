<?php

namespace App\Services\Realtime;

use App\Enums\CategoryType;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Issues Centrifugo connection JWTs (HS256). Channels are server-side
 * subscriptions (`channels` claim): the client can't pick or forge them, so
 * only approved agencies land in the agents presence channel.
 */
class RealtimeTokenIssuer
{
    /** @return array{token: string, expires_at: Carbon} */
    public function issue(User $user): array
    {
        $expiresAt = now()->addMinutes((int) config('realtime.token_ttl_minutes'));

        $channels = [config('realtime.channels.pulse')];
        $isAgency = AgentProfile::query()
            ->approved()
            ->serving(CategoryType::Agent)
            ->where('user_id', $user->id)
            ->exists();
        if ($isAgency) {
            $channels[] = config('realtime.channels.agents');
        }

        $token = $this->encode([
            'sub' => (string) $user->id,
            'exp' => $expiresAt->getTimestamp(),
            'channels' => $channels,
        ]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @param  array<string, mixed>  $claims */
    private function encode(array $claims): string
    {
        $segments = [
            $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode($claims)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), (string) config('realtime.hmac_secret'), true);
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
