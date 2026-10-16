<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUDIT_2026-10-16 §1.2 — only the local reverse proxy may rewrite the client
 * IP through X-Forwarded-For; any other caller's header is ignored.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/_test/ip', fn (Request $request): string => (string) $request->ip());
    }

    public function test_forwarded_for_from_the_local_proxy_is_honoured(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertOk()
            ->assertSee('198.51.100.7');
    }

    public function test_forwarded_for_from_an_untrusted_client_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertOk()
            ->assertSee('203.0.113.5')
            ->assertDontSee('198.51.100.7');
    }

    public function test_trusted_proxies_are_configurable(): void
    {
        config(['proxies.trusted' => '203.0.113.5, 10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertOk()
            ->assertSee('198.51.100.7');

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.8'])
            ->assertOk()
            ->assertSee('198.51.100.8');
    }
}
