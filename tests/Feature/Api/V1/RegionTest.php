<?php

namespace Tests\Feature\Api\V1;

use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionTest extends TestCase
{
    use RefreshDatabase;

    private function userToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_regions_require_authentication(): void
    {
        $this->getJson('/api/v1/regions')->assertUnauthorized();
    }

    public function test_lists_active_roots_with_nested_districts(): void
    {
        Region::factory()->inactive()->create(['name_uz' => 'Hidden viloyat']);

        $response = $this->getJson('/api/v1/regions', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(14, 'data');

        $tashkent = collect($response->json('data'))
            ->first(fn (array $r) => $r['code'] === 'toshkent-shahri');

        $this->assertNotNull($tashkent);
        $this->assertCount(12, $tashkent['districts']);
        $this->assertArrayHasKey('name_uz', $tashkent['districts'][0]);
        $this->assertArrayNotHasKey('is_active', $tashkent);
        $this->assertArrayNotHasKey('parent_id', $tashkent['districts'][0]);

        $codes = collect($response->json('data'))->pluck('code');
        $this->assertFalse($codes->contains(fn (string $c) => str_starts_with($c, 'hidden')));
    }

    public function test_inactive_districts_are_omitted(): void
    {
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();
        $district->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/regions', [
            'Authorization' => 'Bearer '.$this->userToken(),
        ]);

        $tashkent = collect($response->json('data'))
            ->first(fn (array $r) => $r['code'] === 'toshkent-shahri');

        $this->assertCount(11, $tashkent['districts']);
        $this->assertFalse(
            collect($tashkent['districts'])->contains(fn (array $d) => $d['code'] === 'chilonzor'),
        );
    }
}
