<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The API has no web login page, so an unauthenticated request must get a
 * JSON 401 whatever the Accept header says — never a redirect to a missing
 * `login` route (which rendered as a 500 for clients without
 * `Accept: application/json`, e.g. a plain browser tab or curl).
 */
class GuestApiResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_without_json_accept_header_gets_401_not_500(): void
    {
        $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_guest_file_download_without_accept_header_gets_401(): void
    {
        $this->get('/api/v1/files/1', ['Accept' => '*/*'])
            ->assertStatus(401);
    }

    public function test_guest_with_json_accept_header_still_gets_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
