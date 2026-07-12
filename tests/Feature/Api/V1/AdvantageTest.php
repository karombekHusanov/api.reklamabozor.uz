<?php

namespace Tests\Feature\Api\V1;

use App\Models\Advantage;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvantageTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_only_active_entries(): void
    {
        Advantage::factory()->count(2)->create();
        Advantage::factory()->inactive()->create();
        $user = User::factory()->create();

        $this->getJson('/api/v1/advantages', [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_provider_picks_advantages_shown_on_public_profile(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $picked = Advantage::factory()->count(2)->create();

        $this->patchJson('/api/v1/agent/profile', [
            'advantage_ids' => $picked->pluck('id')->all(),
        ], ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->assertOk()
            ->assertJsonCount(2, 'data.advantages');

        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.advantages');
    }

    public function test_picking_more_than_six_is_rejected(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->approved()->create();
        $ids = Advantage::factory()->count(7)->create()->pluck('id')->all();

        $this->patchJson('/api/v1/agent/profile', [
            'advantage_ids' => $ids,
        ], ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('advantage_ids');
    }

    public function test_workflow_steps_are_saved_and_public(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();

        $this->patchJson('/api/v1/agent/profile', [
            'workflow_steps' => [
                ['title' => 'Suhbat', 'description' => 'Ehtiyojni aniqlaymiz'],
                ['title' => 'Taklif'],
            ],
        ], ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->assertOk()
            ->assertJsonPath('data.workflow_steps.0.title', 'Suhbat');

        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow_steps.1.title', 'Taklif');
    }

    public function test_admin_crud(): void
    {
        $admin = User::factory()->admin()->create();
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        $created = $this->postJson('/api/v1/admin/advantages', [
            'name_uz' => 'Tez bajarish',
            'name_ru' => 'Быстро',
            'icon' => 'timer',
        ], $headers)->assertCreated()->json('data');

        $this->patchJson("/api/v1/admin/advantages/{$created['id']}", ['is_active' => false], $headers)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/admin/advantages', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.used_by_count', 0);

        $this->deleteJson("/api/v1/admin/advantages/{$created['id']}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('advantages', ['id' => $created['id']]);
    }

    public function test_admin_routes_reject_non_admins(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/admin/advantages', ['name_uz' => 'X', 'name_ru' => 'X', 'icon' => 'x'], [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
        ])->assertForbidden();
    }
}
