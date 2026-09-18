<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Enums\OfferStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An agent may pull back their own pending offer/interest. Distinct from
 * Rejected (the client picking someone else) — see Offer::canWithdraw().
 */
class WithdrawOfferTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string, 2: AgentProfile, 3: Category}
     */
    private function approvedAgent(?Category $category = null): array
    {
        $category ??= Category::factory()->create();
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();
        $profile->categories()->attach($category);

        return [$user, $user->createToken('test')->plainTextToken, $profile, $category];
    }

    public function test_agent_can_withdraw_their_own_pending_offer(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Pending,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/withdraw", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.can_withdraw', false);

        $this->assertSame(OfferStatus::Withdrawn, $offer->fresh()->status);
    }

    public function test_agent_cannot_withdraw_another_agents_offer(): void
    {
        [, $token] = $this->approvedAgent();
        [$otherAgent, , $otherProfile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        $offer = Offer::factory()->for($order)->for($otherAgent, 'agent')->create([
            'agent_profile_id' => $otherProfile->id,
            'status' => OfferStatus::Pending,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/withdraw", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();

        $this->assertSame(OfferStatus::Pending, $offer->fresh()->status);
    }

    public function test_an_accepted_offer_cannot_be_withdrawn(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/withdraw", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }

    public function test_an_already_withdrawn_offer_cannot_be_withdrawn_again(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        $offer = Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Withdrawn,
        ]);

        $this->postJson("/api/v1/agent/offers/{$offer->id}/withdraw", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }

    /**
     * Same rule that already applies to a rejected offer: the unique
     * (order_id, agent_id) row means a withdrawn offer cannot be replaced by
     * a fresh one on the same order.
     */
    public function test_agent_cannot_re_offer_on_the_same_order_after_withdrawing(): void
    {
        [$agent, $token, $profile, $category] = $this->approvedAgent();
        $order = Order::factory()->for($category)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->create([
            'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Withdrawn,
        ]);

        $this->postJson("/api/v1/agent/orders/{$order->id}/offers", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }
}
