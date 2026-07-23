<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShowcaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_is_public_and_returns_recent_orders_with_counters(): void
    {
        $order = Order::factory()->status(OrderStatus::OffersSent)->create();
        OrderView::create(['order_id' => $order->id, 'user_id' => User::factory()->create()->id]);
        OrderView::create(['order_id' => $order->id, 'user_id' => User::factory()->create()->id]);
        Offer::factory()->for($order)->create();

        // No Authorization header — the section is visible to everyone.
        $this->getJson('/api/v1/orders/showcase')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.views_count', 2)
            ->assertJsonPath('data.0.offers_count', 1)
            ->assertJsonPath('data.0.category.id', $order->category_id);
    }

    public function test_showcase_hides_cancelled_orders(): void
    {
        Order::factory()->status(OrderStatus::Cancelled)->create();
        $live = Order::factory()->status(OrderStatus::New)->create();

        $this->getJson('/api/v1/orders/showcase')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id);
    }

    public function test_showcase_does_not_leak_client_identity_or_attachments(): void
    {
        Order::factory()->status(OrderStatus::New)->create();

        $response = $this->getJson('/api/v1/orders/showcase')->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('client', $row);
        $this->assertArrayNotHasKey('attachment_files', $row);
        $this->assertArrayNotHasKey('attachment_file_ids', $row);
    }

    public function test_showcase_respects_the_limit(): void
    {
        Order::factory()->count(5)->status(OrderStatus::New)->create();

        $this->getJson('/api/v1/orders/showcase?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
