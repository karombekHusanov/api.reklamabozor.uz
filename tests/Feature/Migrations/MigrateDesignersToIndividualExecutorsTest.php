<?php

namespace Tests\Feature\Migrations;

use App\Enums\ProviderType;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The retired designer role/track is folded into the agent role as individual
 * executors. Rows are written with raw queries — `designer` is no longer a
 * valid enum case, so the models can't hold it.
 */
class MigrateDesignersToIndividualExecutorsTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_14_100000_migrate_designers_to_individual_executors.php');
        $migration->up();
    }

    public function test_designers_become_individual_executors_under_the_agent_role(): void
    {
        $designer = User::factory()->create();
        DB::table('users')->where('id', $designer->id)->update([
            'role' => 'designer',
            'roles' => json_encode(['client', 'designer']),
        ]);
        $profile = AgentProfile::factory()->for($designer)->approved()->create();
        DB::table('agent_profiles')->where('id', $profile->id)->update(['provider_type' => 'designer']);

        // Held both: designer folds into the existing agent entry.
        $both = User::factory()->create();
        DB::table('users')->where('id', $both->id)->update([
            'role' => 'agent',
            'roles' => json_encode(['client', 'agent', 'designer']),
        ]);

        $untouched = User::factory()->create(['role' => Role::Client, 'roles' => [Role::Client]]);

        $this->migrate();

        $designer = $designer->fresh();
        $this->assertSame(Role::Agent, $designer->role);
        $this->assertSame(['client', 'agent'], $designer->roles->map->value->all());
        $this->assertSame(ProviderType::Individual, $profile->fresh()->provider_type);

        $this->assertSame(['client', 'agent'], $both->fresh()->roles->map->value->all());
        $this->assertSame(['client'], $untouched->fresh()->roles->map->value->all());
    }

    public function test_designer_rating_rows_are_rekeyed_or_dropped(): void
    {
        $rated = User::factory()->create();
        $dup = User::factory()->create();
        $row = fn (int $userId, string $role) => [
            'user_id' => $userId, 'role' => $role, 'agent_profile_id' => null,
            'stars' => 4.5, 'stars_count' => 2, 'grade' => 60, 'listing_boost' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('user_ratings')->insert([
            $row($rated->id, 'designer'),
            $row($dup->id, 'designer'),
            $row($dup->id, 'agent'),
        ]);

        $this->migrate();

        $this->assertSame(0, DB::table('user_ratings')->where('role', 'designer')->count());
        $this->assertSame(1, DB::table('user_ratings')->where('user_id', $rated->id)->where('role', 'agent')->count());
        $this->assertSame(1, DB::table('user_ratings')->where('user_id', $dup->id)->count());
    }
}
