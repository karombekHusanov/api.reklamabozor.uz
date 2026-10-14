<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OfferStatus;
use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Location\LocationPrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public surfaces show agencies and requests as a 1 km circle; the exact point
 * (and street address) is revealed only to the client and the executor once
 * work has started between them.
 */
class LocationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 41.311081;

    private const LNG = 69.279716;

    private function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array<string, string> */
    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    /** @return array{0: User, 1: AgentProfile} */
    private function agency(): array
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create([
            'lat' => self::LAT, 'lng' => self::LNG, 'location_label' => 'Amir Temur ko\'chasi 1',
        ]);

        return [$user, $profile];
    }

    private function order(OrderStatus $status = OrderStatus::OffersSent, OrderRoute $route = OrderRoute::Tender): Order
    {
        return Order::factory()->status($status)->create([
            'route' => $route, 'lat' => self::LAT, 'lng' => self::LNG, 'location_label' => 'Chilonzor 9-kvartal, 12-uy',
        ]);
    }

    // --- the circle itself ----------------------------------------------------

    public function test_circle_is_stable_contains_the_point_and_moves_with_it(): void
    {
        $a = LocationPrivacy::approximate(self::LAT, self::LNG, 'agent:1');
        $b = LocationPrivacy::approximate(self::LAT, self::LNG, 'agent:1');

        $this->assertSame($a, $b);
        $this->assertSame(1000, $a['radius_m']);
        $this->assertLessThanOrEqual(700.5, $this->meters(self::LAT, self::LNG, $a['lat'], $a['lng']));
        $this->assertNotEquals([self::LAT, self::LNG], [$a['lat'], $a['lng']]);

        // Another entity at the same spot, or the same entity after a move, gets another offset.
        $this->assertNotSame($a, LocationPrivacy::approximate(self::LAT, self::LNG, 'agent:2'));
        $moved = LocationPrivacy::approximate(self::LAT + 0.01, self::LNG, 'agent:1');
        $this->assertNotEqualsWithDelta($a['lat'] + 0.01, $moved['lat'], 0.00001);

        $this->assertNull(LocationPrivacy::approximate(null, null, 'agent:1'));
    }

    public function test_public_area_keeps_district_and_city_only(): void
    {
        $this->assertSame(
            'Yangi hayot tumani, Toshkent',
            LocationPrivacy::publicArea('Сохил буйи улица, Южная промзона "Сергели", Yangi hayot tumani, Toshkent, 100000, Oʻzbekiston'),
        );
        $this->assertSame(
            'Юнусабадский район, Toshkent',
            LocationPrivacy::publicArea('Министерство юстиции, 1, Brodvey, Юнусабадский район, Toshkent, 100012, Oʻzbekiston'),
        );
        $this->assertSame('Toshkent', LocationPrivacy::publicArea('Amir Temur ko\'chasi, Toshkent'));
        $this->assertSame('Samarqand', LocationPrivacy::publicArea('Samarqand'));
        // A single hand-typed part with a number is a street address — withheld.
        $this->assertNull(LocationPrivacy::publicArea('Amir Temur ko\'chasi 1'));
        $this->assertNull(LocationPrivacy::publicArea(null));
    }

    // --- public agencies ------------------------------------------------------

    public function test_public_agency_shows_a_circle_and_only_district_and_city(): void
    {
        [, $profile] = $this->agency();
        $profile->update(['location_label' => 'Amir Temur ko\'chasi 1, Yunusobod tumani, Toshkent, 100000, Oʻzbekiston']);

        $data = $this->getJson("/api/v1/agents/{$profile->id}")->assertOk()->json('data');

        $this->assertSame(1000, $data['location_radius_m']);
        $this->assertSame('Yunusobod tumani, Toshkent', $data['location_label']);
        $this->assertNotEquals(self::LAT, (float) $data['lat']);
        $this->assertLessThan(1000, $this->meters(self::LAT, self::LNG, $data['lat'], $data['lng']));
    }

    public function test_nearby_distance_is_rounded_to_100_metres(): void
    {
        $this->agency();

        $distance = $this->getJson('/api/v1/agents/nearby?lat='.self::LAT.'&lng='.self::LNG)
            ->assertOk()
            ->json('data.0.distance_m');

        $this->assertSame(0, $distance % 100);
    }

    // --- requests as executors see them ------------------------------------

    public function test_executor_without_a_started_deal_sees_the_request_as_a_circle(): void
    {
        [$agent, $profile] = $this->agency();
        $order = $this->order();
        $profile->categories()->attach($order->category_id);
        $offer = Offer::factory()->create(['order_id' => $order->id, 'agent_id' => $agent->id, 'agent_profile_id' => $profile->id]);

        $this->getJson("/api/v1/agent/offers/{$offer->id}", $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.order.location_radius_m', 1000)
            ->assertJsonPath('data.order.location_label', null);

        $this->getJson('/api/v1/orders/showcase/'.$order->id, $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.location_radius_m', 1000);
    }

    public function test_exact_locations_open_to_both_parties_once_work_starts(): void
    {
        [$agent, $profile] = $this->agency();
        $order = $this->order(OrderStatus::InProgress);
        $offer = Offer::factory()->create([
            'order_id' => $order->id, 'agent_id' => $agent->id, 'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
        ]);
        // A losing bidder on the same order stays outside.
        [$other, $otherProfile] = $this->agency();
        Offer::factory()->create([
            'order_id' => $order->id, 'agent_id' => $other->id, 'agent_profile_id' => $otherProfile->id,
            'status' => OfferStatus::Rejected,
        ]);

        // Executor → the request's exact point and address.
        $exact = $this->getJson("/api/v1/agent/offers/{$offer->id}", $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.order.location_radius_m', null)
            ->assertJsonPath('data.order.location_label', 'Chilonzor 9-kvartal, 12-uy')
            ->json('data.order');
        $this->assertEqualsWithDelta(self::LAT, (float) $exact['lat'], 0.0000001);

        // Client → the chosen agency's exact point; the losing bidder stays a circle.
        $this->app['auth']->forgetGuards();
        $offers = collect($this->getJson("/api/v1/orders/{$order->id}", $this->auth($order->client))
            ->assertOk()
            ->json('data.offers'))->keyBy('agent.id');

        $this->assertNull($offers[$agent->id]['agent']['location_radius_m']);
        $this->assertSame('Amir Temur ko\'chasi 1', $offers[$agent->id]['agent']['location_label']);
        $this->assertSame(1000, $offers[$other->id]['agent']['location_radius_m']);
        $this->assertNull($offers[$other->id]['agent']['location_label']);
    }

    public function test_tezkor_pick_reveals_the_exact_location(): void
    {
        [$agent, $profile] = $this->agency();
        $order = $this->order(OrderStatus::Completed, OrderRoute::Tezkor);
        $order->forceFill(['claimed_agent_id' => $agent->id, 'claimed_at' => now()])->save();
        $offer = Offer::factory()->interest()->create([
            'order_id' => $order->id, 'agent_id' => $agent->id, 'agent_profile_id' => $profile->id,
            'status' => OfferStatus::Accepted,
        ]);

        $this->assertTrue($order->fresh()->revealsExactLocationTo($agent));
        $this->assertTrue($order->fresh()->revealsExactLocationTo($order->client));
        $this->assertFalse($order->fresh()->revealsExactLocationTo(User::factory()->create()));

        $this->getJson("/api/v1/agent/offers/{$offer->id}", $this->auth($agent))
            ->assertOk()
            ->assertJsonPath('data.order.location_radius_m', null);
    }
}
