<?php

namespace Tests\Feature\Api\V1\Order;

use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string}
     */
    private function authedUser(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    public function test_creating_an_order_requires_authentication(): void
    {
        $this->postJson('/api/v1/orders', [])->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_client_can_place_an_order(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $tz = File::factory()->create(['uploaded_by' => $client->id]);

        $response = $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need a banner campaign across Tashkent metro.',
            'tz_file_id' => $tz->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category.id', $category->id)
            // Title is derived from the category.
            ->assertJsonPath('data.title', $category->name_uz);

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'category_id' => $category->id,
            'tz_file_id' => $tz->id,
            'status' => 'new',
        ]);
    }

    public function test_order_validates_required_fields(): void
    {
        [, $token] = $this->authedUser();

        $this->postJson('/api/v1/orders', [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'description', 'tz_file_id']);
    }

    public function test_tz_file_must_belong_to_the_client(): void
    {
        [, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $strangerFile = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'x',
            'tz_file_id' => $strangerFile->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tz_file_id']);
    }

    public function test_placing_an_order_notifies_matching_approved_agents(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $tz = File::factory()->create(['uploaded_by' => $client->id]);

        // An approved agent that serves this category and has a Telegram id.
        $agentUser = User::factory()->create(['telegram_id' => 555000111]);
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();
        $profile->categories()->attach($category);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need outdoor billboards.',
            'tz_file_id' => $tz->id,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === 555000111);
    }

    public function test_new_order_notification_deep_links_to_the_order(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $tz = File::factory()->create(['uploaded_by' => $client->id]);

        $agentUser = User::factory()->create(['telegram_id' => 777000222]);
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();
        $profile->categories()->attach($category);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need a launch campaign.',
            'tz_file_id' => $tz->id,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $order = $client->orders()->latest('id')->first();

        Http::assertSent(function ($request) use ($order) {
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return $url === "https://app.test/agent?order={$order->id}";
        });
    }

    public function test_client_only_sees_their_own_orders(): void
    {
        [$client, $token] = $this->authedUser();
        Order::factory()->for($client, 'client')->count(2)->create();
        Order::factory()->count(3)->create(); // other clients

        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_client_can_view_their_order_with_offers(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->create();
        Offer::factory()->for($order)->count(2)->create();

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(2, 'data.offers');
    }

    public function test_client_cannot_view_another_clients_order(): void
    {
        [, $token] = $this->authedUser();
        $foreign = Order::factory()->create();

        $this->getJson("/api/v1/orders/{$foreign->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }
}
