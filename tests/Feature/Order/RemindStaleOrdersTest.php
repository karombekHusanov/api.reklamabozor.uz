<?php

namespace Tests\Feature\Order;

use App\Console\Commands\RemindStaleOrders;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An order still open for offers with zero offers for too long gets a single
 * one-time reminder ({@see RemindStaleOrders}).
 */
class RemindStaleOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminds_a_stale_order_with_zero_offers(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $order = Order::factory()->status(OrderStatus::New)->create(['created_at' => now()->subDays(4)]);

        $this->artisan('orders:remind-stale')->assertSuccessful();

        $this->assertNotNull($order->fresh()->stale_reminder_sent_at);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'javob'));
    }

    public function test_does_not_remind_before_the_grace_period(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $order = Order::factory()->status(OrderStatus::New)->create(['created_at' => now()->subDay()]);

        $this->artisan('orders:remind-stale')->assertSuccessful();

        $this->assertNull($order->fresh()->stale_reminder_sent_at);
    }

    public function test_does_not_remind_an_order_that_already_has_an_offer(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $order = Order::factory()->status(OrderStatus::OffersSent)->create(['created_at' => now()->subDays(4)]);
        Offer::factory()->for($order)->for(User::factory(), 'agent')->create(['status' => OfferStatus::Withdrawn]);

        $this->artisan('orders:remind-stale')->assertSuccessful();

        $this->assertNull($order->fresh()->stale_reminder_sent_at);
    }

    public function test_does_not_remind_a_cancelled_order(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $order = Order::factory()->status(OrderStatus::Cancelled)->create(['created_at' => now()->subDays(4)]);

        $this->artisan('orders:remind-stale')->assertSuccessful();

        $this->assertNull($order->fresh()->stale_reminder_sent_at);
    }

    public function test_reminds_only_once(): void
    {
        config(['orders.stale_order_reminder_days' => 3]);
        Http::fake();
        $order = Order::factory()->status(OrderStatus::New)->create(['created_at' => now()->subDays(4)]);

        $this->artisan('orders:remind-stale')->assertSuccessful();
        $sentAt = $order->fresh()->stale_reminder_sent_at;

        $this->artisan('orders:remind-stale')->assertSuccessful();

        $this->assertTrue($sentAt->equalTo($order->fresh()->stale_reminder_sent_at));
    }
}
