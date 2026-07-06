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

class OrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An in-progress order with a winning agent.
     *
     * @return array{0: Order, 1: User, 2: User} [order, client, agent]
     */
    private function activeDeal(): array
    {
        $client = User::factory()->create(['telegram_id' => 111222333]);
        $agent = User::factory()->create(['telegram_id' => 444555666]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Offer::factory()->for($order)->for($agent, 'agent')->create(['status' => OfferStatus::Accepted]);

        return [$order, $client, $agent];
    }

    public function test_winning_agent_can_submit_work(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        [$order, , $agent] = $this->activeDeal();
        $token = $agent->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/agent/orders/{$order->id}/submit-work", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'work_submitted');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::WorkSubmitted, $fresh->status);
        $this->assertNotNull($fresh->work_submitted_at);

        // The client is asked to confirm, with a deep link to the order page.
        Http::assertSent(function ($request) use ($order) {
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return ($request['chat_id'] ?? null) === 111222333
                && str_contains($request['text'] ?? '', "#{$order->id}")
                && $url === "https://app.test/orders/{$order->id}";
        });
    }

    public function test_only_the_winning_agent_can_submit_work(): void
    {
        [$order] = $this->activeDeal();
        $outsider = User::factory()->create();
        $token = $outsider->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/agent/orders/{$order->id}/submit-work", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    public function test_work_can_only_be_submitted_while_in_progress(): void
    {
        [$order, , $agent] = $this->activeDeal();
        $order->update(['status' => OrderStatus::Completed]);
        $token = $agent->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/agent/orders/{$order->id}/submit-work", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }

    public function test_client_can_confirm_completion(): void
    {
        Http::fake();
        [$order, $client] = $this->activeDeal();
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/complete", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.auto_completed', false);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);

        // The winning agent hears the good news.
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 444555666
            && str_contains($request['text'] ?? '', 'yakunlandi'));
    }

    public function test_client_can_dispute_the_delivered_work(): void
    {
        config(['services.telegram.admin_chat_id' => '-100777']);
        Http::fake();
        [$order, $client] = $this->activeDeal();
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertNull($order->fresh()->work_submitted_at);

        // The agent is told, and the ops group is signalled to step in.
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 444555666
            && str_contains($request['text'] ?? '', 'qabul qilmadi'));
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === '-100777'
            && str_contains($request['text'] ?? '', 'Muammo'));
    }

    public function test_only_the_owning_client_can_confirm_or_dispute(): void
    {
        [$order] = $this->activeDeal();
        $order->update(['status' => OrderStatus::WorkSubmitted, 'work_submitted_at' => now()]);
        $stranger = User::factory()->create();
        $token = $stranger->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/complete", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();

        $this->postJson("/api/v1/orders/{$order->id}/dispute", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    public function test_confirmation_requires_work_submitted_status(): void
    {
        [$order, $client] = $this->activeDeal(); // still in_progress
        $token = $client->createToken('test')->plainTextToken;

        $this->postJson("/api/v1/orders/{$order->id}/complete", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnprocessable();
    }

    public function test_scheduler_reminds_on_day_two_only_once(): void
    {
        Http::fake();
        [$order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now()->subDays(2)->subHour(),
        ]);

        $this->artisan('orders:process-completions')->assertSuccessful();

        $this->assertNotNull($order->fresh()->completion_reminder_sent_at);
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 111222333
            && str_contains($request['text'] ?? '', 'Eslatma'));

        // A second run must not send the reminder again.
        Http::fake();
        $this->artisan('orders:process-completions')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_scheduler_auto_completes_after_three_days(): void
    {
        Http::fake();
        [$order] = $this->activeDeal();
        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now()->subDays(3)->subHour(),
        ]);

        $this->artisan('orders:process-completions')->assertSuccessful();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertTrue($fresh->auto_completed);
        $this->assertNotNull($fresh->completed_at);

        // Both sides are told about the automatic acceptance.
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 444555666
            && str_contains($request['text'] ?? '', 'avtomatik'));
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 111222333
            && str_contains($request['text'] ?? '', 'avtomatik'));
    }
}
