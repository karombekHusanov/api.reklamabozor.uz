<?php

namespace Tests\Feature\Api\V1\Rating;

use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\UserRating;
use App\Services\Rating\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Variant 1 (1 user = 1 profile): a provider's reputation is a single combined
 * rating owned by their one profile, keyed under the profile's own kind — not
 * split or duplicated across held provider roles.
 */
class RatingAttributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_provider_rating_is_a_single_row_owned_by_the_profile(): void
    {
        // Holds both provider roles, but owns one agent-type profile.
        $user = User::factory()->agent()->create([
            'roles' => [Role::Client, Role::Agent, Role::Designer],
            'role_selected_at' => now(),
        ]);
        $profile = AgentProfile::factory()->approved()->for($user, 'user')->create();

        // A stale null-profile designer stub from before the profile existed.
        UserRating::create([
            'user_id' => $user->id,
            'role' => Role::Designer,
            'agent_profile_id' => null,
            'stars' => 5.00,
            'stars_count' => 0,
            'grade' => 50,
            'listing_boost' => 0,
        ]);

        app(RatingService::class)->recomputeForUser($user->id);

        // Exactly one provider row: agent, owned by the profile.
        $this->assertDatabaseHas('user_ratings', [
            'user_id' => $user->id,
            'role' => Role::Agent->value,
            'agent_profile_id' => $profile->id,
        ]);
        // The stale designer / null-profile provider row was pruned.
        $this->assertDatabaseMissing('user_ratings', [
            'user_id' => $user->id,
            'role' => Role::Designer->value,
        ]);
        $this->assertSame(
            1,
            UserRating::where('user_id', $user->id)
                ->whereIn('role', [Role::Agent->value, Role::Designer->value])
                ->count(),
        );

        // Client keeps its own role-keyed, profile-less row.
        $this->assertDatabaseHas('user_ratings', [
            'user_id' => $user->id,
            'role' => Role::Client->value,
            'agent_profile_id' => null,
        ]);

        // cachedRating() resolves unambiguously to that one provider row.
        $this->assertSame($profile->id, $profile->fresh()->cachedRating?->agent_profile_id);
    }

    public function test_me_rating_maps_a_provider_role_query_to_the_profile_row(): void
    {
        $user = User::factory()->agent()->create([
            'roles' => [Role::Client, Role::Agent, Role::Designer],
            'role_selected_at' => now(),
        ]);
        $profile = AgentProfile::factory()->approved()->for($user, 'user')->create();
        app(RatingService::class)->recomputeForUser($user->id);

        // Asking for the designer rating returns the profile's (agent-keyed) row.
        $this->getJson('/api/v1/me/rating?role=designer', $this->auth($user))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_profile_id', $profile->id)
            ->assertJsonPath('data.0.role', Role::Agent->value);
    }
}
