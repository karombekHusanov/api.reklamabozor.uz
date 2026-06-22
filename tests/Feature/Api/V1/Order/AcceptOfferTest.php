<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcceptOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_accept_an_offer(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $chosen = Offer::factory()->for($order)->create();
        $other = Offer::factory()->for($order)->create();

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertSame(OfferStatus::Accepted, $chosen->fresh()->status);
        $this->assertSame(OfferStatus::Rejected, $other->fresh()->status);
        $this->assertSame(OrderStatus::ClientSelected, $order->fresh()->status);
    }

    public function test_only_the_owning_client_can_accept(): void
    {
        $stranger = User::factory()->create();
        $token = $stranger->createToken('test')->plainTextToken;
        $order = Order::factory()->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create();

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    public function test_cannot_accept_when_order_already_in_progress(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        $offer = Offer::factory()->for($order)->create();

        $this->postJson("/api/v1/offers/{$offer->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }
}
