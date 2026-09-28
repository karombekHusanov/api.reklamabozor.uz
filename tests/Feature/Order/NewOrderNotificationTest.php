<?php

namespace Tests\Feature\Order;

use App\Enums\OrderRoute;
use App\Enums\OrderStatus;
use App\Jobs\SendNewOrderNotification;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'T', 'services.telegram.api_url' => 'https://tg.test']);
    }

    private function agent(): User
    {
        $user = User::factory()->create(['telegram_id' => random_int(1000000, 9999999)]);
        AgentProfile::factory()->for($user)->approved()->create();

        return $user;
    }

    private function order(): Order
    {
        return Order::factory()->status(OrderStatus::New)->create(['category_id' => null, 'route' => OrderRoute::Tezkor]);
    }

    public function test_broadcast_queues_one_staggered_job_per_agent(): void
    {
        Queue::fake();
        config(['services.telegram.broadcast_per_second' => 2]);
        $agents = collect(range(1, 5))->map(fn () => $this->agent());
        $order = $this->order();

        $queued = app(OrderNotifier::class)->notifyNewOrder($order);

        $this->assertSame(5, $queued);
        Queue::assertPushed(SendNewOrderNotification::class, 5);
        $delays = [];
        Queue::assertPushed(SendNewOrderNotification::class, function ($job) use (&$delays) {
            $delays[] = $job->delay?->diffInSeconds(now(), true);

            return true;
        });
        // 5 recipients at 2/s → waves at +0s, +1s, +2s.
        $this->assertCount(3, array_unique(array_map(fn ($d) => (int) round($d), $delays)));
        $this->assertNotEmpty($agents);
    }

    public function test_job_delivers_message(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => true])]);
        $agent = $this->agent();
        $order = $this->order();

        SendNewOrderNotification::dispatchSync($order->id, $agent->id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && (int) $r['chat_id'] === (int) $agent->telegram_id);
    }

    public function test_job_skips_closed_orders(): void
    {
        Http::fake();
        $agent = $this->agent();
        $cancelled = $this->order();
        $cancelled->update(['status' => OrderStatus::Cancelled]);
        $completed = $this->order();
        $completed->update(['status' => OrderStatus::Completed]);

        SendNewOrderNotification::dispatchSync($cancelled->id, $agent->id);
        SendNewOrderNotification::dispatchSync($completed->id, $agent->id);

        Http::assertNothingSent();
    }

    public function test_server_error_is_retried(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false], 500)]);
        $agent = $this->agent();
        $order = $this->order();

        $this->expectException(\RuntimeException::class);
        (new SendNewOrderNotification($order->id, $agent->id))->handle(app(OrderNotifier::class));
    }

    public function test_blocked_chat_is_not_retried(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false], 403)]);
        $agent = $this->agent();
        $order = $this->order();

        (new SendNewOrderNotification($order->id, $agent->id))->handle(app(OrderNotifier::class));

        Http::assertSentCount(1);
    }

    public function test_flood_control_releases_the_job_for_the_requested_time(): void
    {
        Http::fake(['tg.test/*' => Http::response(['ok' => false, 'parameters' => ['retry_after' => 7]], 429)]);
        $agent = $this->agent();
        $order = $this->order();

        $job = new class($order->id, $agent->id) extends SendNewOrderNotification
        {
            public ?int $releasedFor = null;

            public function release($delay = 0)
            {
                $this->releasedFor = (int) $delay;
            }
        };
        $job->handle(app(OrderNotifier::class));

        $this->assertSame(8, $job->releasedFor);
    }
}
