<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcceptOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_accept_an_offer(): void
    {
        Http::fake();
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
        // Auto-manager: selection activates the order immediately, no admin gate.
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_accepting_notifies_winner_and_losers(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();

        $winner = User::factory()->create(['telegram_id' => 111000111]);
        $loser = User::factory()->create(['telegram_id' => 222000222]);
        $chosen = Offer::factory()->for($order)->for($winner, 'agent')->create(['price' => 3_000_000]);
        Offer::factory()->for($order)->for($loser, 'agent')->create();

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        // Winner: congratulation with order id, agreed price, and a deep link.
        Http::assertSent(function ($request) use ($order) {
            $text = $request['text'] ?? '';
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return ($request['chat_id'] ?? null) === 111000111
                && str_contains($text, "#{$order->id}")
                && str_contains($text, '3 000 000')
                && $url === "https://app.test/orders/{$order->id}";
        });

        // Loser: told the order went to someone else.
        Http::assertSent(function ($request) use ($order) {
            $text = $request['text'] ?? '';

            return ($request['chat_id'] ?? null) === 222000222
                && str_contains($text, "#{$order->id}")
                && str_contains($text, 'boshqa taklifni tanladi');
        });
    }

    public function test_accepting_reports_the_deal_to_the_ops_group(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $chosen = Offer::factory()->for($order)->create(['price' => 5_000_000]);

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        Http::assertSent(function ($request) use ($order) {
            $text = $request['text'] ?? '';

            return ($request['chat_id'] ?? null) === '-100777'
                && str_contains($text, 'Kelishuv')
                && str_contains($text, "#{$order->id}")
                && str_contains($text, '5 000 000');
        });
    }

    public function test_ops_group_events_can_be_muted(): void
    {
        config([
            'services.telegram.admin_chat_id' => '-100777',
            'services.telegram.admin_events' => 'dispute,completed',
        ]);
        Http::fake();
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $chosen = Offer::factory()->for($order)->create();

        $this->postJson("/api/v1/offers/{$chosen->id}/accept", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        Http::assertNotSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777');
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
