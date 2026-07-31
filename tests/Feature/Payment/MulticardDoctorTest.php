<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MulticardDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_fails_when_callback_url_empty(): void
    {
        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://dev-mesh.multicard.uz',
            'services.multicard.callback_url' => '',
            'services.multicard.callback_sign' => 'both',
        ]);

        $this->artisan('multicard:doctor')->assertFailed();
    }

    public function test_doctor_passes_when_callback_reachable(): void
    {
        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://dev-mesh.multicard.uz',
            'services.multicard.callback_url' => 'https://tunnel.test/api/v1/payment/multicard/callback',
            'services.multicard.callback_sign' => 'both',
        ]);

        Http::fake([
            'https://tunnel.test/*' => Http::response(['success' => false], 403),
        ]);

        $this->artisan('multicard:doctor')->assertSuccessful();
    }

    public function test_doctor_fails_when_callback_unreachable(): void
    {
        config([
            'services.multicard.enabled' => true,
            'services.multicard.base_url' => 'https://dev-mesh.multicard.uz',
            'services.multicard.callback_url' => 'https://dead.test/api/v1/payment/multicard/callback',
            'services.multicard.callback_sign' => 'both',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('multicard:doctor')->assertFailed();
    }
}
