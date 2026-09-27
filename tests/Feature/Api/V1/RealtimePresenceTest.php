<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimePresenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-hmac-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'realtime.enabled' => true,
            'realtime.hmac_secret' => self::SECRET,
            'realtime.api_url' => 'http://centrifugo.test/api',
            'realtime.api_key' => 'api-key',
            'realtime.ws_url' => 'wss://ws.test/connection/websocket',
        ]);
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", self::SECRET, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $signature, 'JWT signature must verify with the Centrifugo secret');

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    private function fakeCentrifugo(int $pulse, int $agents): void
    {
        Http::fake(function (Request $request) use ($pulse, $agents) {
            if (str_ends_with($request->url(), '/presence_stats')) {
                $users = $request['channel'] === 'live:agents' ? $agents : $pulse;

                return Http::response(['result' => ['num_clients' => $users, 'num_users' => $users]]);
            }

            return Http::response(['result' => []]);
        });
    }

    public function test_client_token_subscribes_only_to_the_pulse_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $data = $this->postJson('/api/v1/realtime/token')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.ws_url', 'wss://ws.test/connection/websocket')
            ->json('data');

        $claims = $this->claims($data['token']);
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame(['live:pulse'], $claims['channels']);
        $this->assertGreaterThan(now()->timestamp, $claims['exp']);
    }

    public function test_approved_agency_token_also_joins_the_agents_channel(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->create([
            'user_id' => $user->id,
            'status' => AgentProfileStatus::Approved,
        ]);
        $profile->categories()->attach(Category::factory()->create());
        Sanctum::actingAs($user);

        $token = $this->postJson('/api/v1/realtime/token')->assertOk()->json('data.token');

        $this->assertSame(['live:pulse', 'live:agents'], $this->claims($token)['channels']);
    }

    public function test_token_endpoint_reports_disabled_and_requires_auth(): void
    {
        $this->postJson('/api/v1/realtime/token')->assertUnauthorized();

        config(['realtime.enabled' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/realtime/token')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonMissingPath('data.token');
    }

    public function test_live_stats_use_centrifugo_presence_and_publish_to_clients(): void
    {
        $this->fakeCentrifugo(pulse: 1234, agents: 17);

        $this->artisan('stats:publish-live')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/publish')
            && $r->hasHeader('X-API-Key', 'api-key')
            && $r['channel'] === 'live:pulse'
            && $r['data']['stats']['users_online'] === 1234);

        $this->getJson('/api/v1/stats/live')
            ->assertOk()
            ->assertJsonPath('data.users_online', 1234)
            ->assertJsonPath('data.agents_online', 17);
    }

    public function test_online_counts_are_null_when_centrifugo_is_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->getJson('/api/v1/stats/live')
            ->assertOk()
            ->assertJsonPath('data.users_online', null)
            ->assertJsonPath('data.agents_online', null)
            ->assertJsonPath('data.active_orders', 0);
    }

    public function test_requests_do_not_write_token_last_used_at_while_realtime_is_on(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('t')->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNull($user->tokens()->first()->last_used_at);

        config(['realtime.enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotNull($user->tokens()->first()->last_used_at);
    }
}
