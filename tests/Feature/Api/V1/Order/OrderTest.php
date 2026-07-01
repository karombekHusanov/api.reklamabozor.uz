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

    public function test_client_can_place_an_order_with_deadline_and_attachments(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $tz = File::factory()->create(['uploaded_by' => $client->id]);
        $extra1 = File::factory()->create(['uploaded_by' => $client->id]);
        $extra2 = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Urgent outdoor campaign.',
            'deadline' => 'today_tomorrow',
            'tz_file_id' => $tz->id,
            'attachment_file_ids' => [$extra1->id, $extra2->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.deadline', 'today_tomorrow')
            ->assertJsonPath('data.attachment_file_ids', [$extra1->id, $extra2->id]);

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'deadline' => 'today_tomorrow',
        ]);
    }

    public function test_order_rejects_invalid_deadline_and_unowned_attachments(): void
    {
        [, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $stranger = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'x',
            'deadline' => 'next_year',
            'tz_file_id' => File::factory()->create()->id,
            'attachment_file_ids' => [$stranger->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline', 'tz_file_id', 'attachment_file_ids.0']);
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
            'deadline' => 'this_week',
            'tz_file_id' => $tz->id,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        // The client's TZ brief is delivered to agents as a Telegram document,
        // referenced by an absolute URL, with a caption carrying the full order
        // info (category, deadline, comment) and a "view order" deep-link button.
        Http::assertSent(function ($request) use ($tz, $category) {
            $doc = $request['document'] ?? '';
            $caption = $request['caption'] ?? '';

            return str_contains($request->url(), 'sendDocument')
                && $request['chat_id'] === 555000111
                && str_starts_with($doc, 'http')
                && str_contains($doc, $tz->path)
                && str_contains($caption, $category->name_uz)
                && str_contains($caption, 'Shu hafta')
                && str_contains($caption, 'Need outdoor billboards.');
        });
    }

    public function test_agents_outside_the_order_category_are_not_notified(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $orderCategory = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $tz = File::factory()->create(['uploaded_by' => $client->id]);

        // Approved agent, but serves a DIFFERENT category — must not be notified.
        $outsider = User::factory()->create(['telegram_id' => 999888777]);
        AgentProfile::factory()->for($outsider)->approved()->create()
            ->categories()->attach($otherCategory);

        $this->postJson('/api/v1/orders', [
            'category_id' => $orderCategory->id,
            'description' => 'Only my category should hear about this.',
            'tz_file_id' => $tz->id,
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertNotSent(fn ($request) => ($request['chat_id'] ?? null) === 999888777);
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
