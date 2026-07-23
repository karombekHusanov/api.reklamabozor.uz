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
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $response = $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need a banner campaign across Tashkent metro.',
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.title', $category->name_uz)
            ->assertJsonPath('data.attachment_file_ids', [$file->id])
            ->assertJsonCount(1, 'data.attachment_files');

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'category_id' => $category->id,
            'tz_file_id' => null,
            'status' => 'new',
        ]);
    }

    public function test_client_can_place_an_order_with_deadline_and_multiple_attachments(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file1 = File::factory()->create(['uploaded_by' => $client->id]);
        $file2 = File::factory()->create(['uploaded_by' => $client->id]);
        $file3 = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Urgent outdoor campaign.',
            'deadline' => 'today_tomorrow',
            'attachment_file_ids' => [$file1->id, $file2->id, $file3->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.deadline', 'today_tomorrow')
            ->assertJsonPath('data.attachment_file_ids', [$file1->id, $file2->id, $file3->id])
            ->assertJsonCount(3, 'data.attachment_files')
            ->assertJsonPath('data.attachment_files.0.id', $file1->id)
            ->assertJsonPath('data.attachment_files.0.url', $file1->url())
            ->assertJsonPath('data.attachment_files.2.id', $file3->id);

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
            'attachment_file_ids' => [$stranger->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline', 'attachment_file_ids.0']);
    }

    public function test_order_validates_required_fields(): void
    {
        [, $token] = $this->authedUser();

        $this->postJson('/api/v1/orders', [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'description', 'attachment_file_ids']);
    }

    public function test_attachment_files_must_belong_to_the_client(): void
    {
        [, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $strangerFile = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'x',
            'attachment_file_ids' => [$strangerFile->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachment_file_ids.0']);
    }

    public function test_placing_an_order_notifies_matching_approved_agents(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $agentUser = User::factory()->create(['telegram_id' => 555000111]);
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();
        $profile->categories()->attach($category);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need outdoor billboards.',
            'deadline' => 'this_week',
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $order = $client->orders()->latest('id')->first();

        Http::assertSent(function ($request) use ($file, $category, $order) {
            $doc = $request['document'] ?? '';
            $caption = $request['caption'] ?? '';

            return str_contains($request->url(), 'sendDocument')
                && $request['chat_id'] === 555000111
                && str_starts_with($doc, 'http')
                && str_contains($doc, $file->path)
                && str_contains($caption, "#{$order->id}")
                && str_contains($caption, $category->name_uz)
                && str_contains($caption, 'Shu hafta')
                && str_contains($caption, 'Need outdoor billboards.')
                && str_contains($caption, '1 ta fayl ilova qilindi.');
        });
    }

    public function test_agents_outside_the_order_category_are_not_notified(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $orderCategory = Category::factory()->create();
        $otherCategory = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $outsider = User::factory()->create(['telegram_id' => 999888777]);
        AgentProfile::factory()->for($outsider)->approved()->create()
            ->categories()->attach($otherCategory);

        $this->postJson('/api/v1/orders', [
            'category_id' => $orderCategory->id,
            'description' => 'Only my category should hear about this.',
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertNotSent(fn ($request) => ($request['chat_id'] ?? null) === 999888777);
    }

    public function test_new_order_notification_deep_links_to_the_order(): void
    {
        config(['services.telegram.mini_app_url' => 'https://app.test']);
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $agentUser = User::factory()->create(['telegram_id' => 777000222]);
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();
        $profile->categories()->attach($category);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need a launch campaign.',
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $order = $client->orders()->latest('id')->first();

        // Providers don't own the order, so the deep link lands them on their
        // own workspace focused on this order (where they bid), not the
        // client-only /orders/{id} page (which 404s for them).
        Http::assertSent(function ($request) use ($order) {
            $url = $request['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '';

            return $url === "https://app.test/offers?order={$order->id}";
        });
    }

    public function test_client_only_sees_their_own_orders(): void
    {
        [$client, $token] = $this->authedUser();
        Order::factory()->for($client, 'client')->count(2)->create();
        Order::factory()->count(3)->create();

        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_client_can_view_their_order_with_offers(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->create();
        $agentUser = User::factory()->create();
        $profile = AgentProfile::factory()->for($agentUser)->approved()->create();
        Offer::factory()->for($order)->create(['agent_id' => $agentUser->id]);

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(1, 'data.offers')
            ->assertJsonPath('data.offers.0.agent.profile_id', $profile->id)
            ->assertJsonPath('data.attachment_files', []);
    }

    public function test_client_can_view_order_attachment_files_with_urls(): void
    {
        [$client, $token] = $this->authedUser();
        $file1 = File::factory()->create(['uploaded_by' => $client->id]);
        $file2 = File::factory()->create(['uploaded_by' => $client->id]);
        $order = Order::factory()->for($client, 'client')->create([
            'attachment_file_ids' => [$file1->id, $file2->id],
        ]);

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.attachment_file_ids', [$file1->id, $file2->id])
            ->assertJsonCount(2, 'data.attachment_files')
            ->assertJsonPath('data.attachment_files.0.id', $file1->id)
            ->assertJsonPath('data.attachment_files.0.url', $file1->url())
            ->assertJsonPath('data.attachment_files.1.id', $file2->id)
            ->assertJsonPath('data.attachment_files.1.url', $file2->url());
    }

    public function test_legacy_tz_only_orders_still_expose_files_via_attachments(): void
    {
        [$client, $token] = $this->authedUser();
        $legacyFile = File::factory()->create(['uploaded_by' => $client->id]);
        $order = Order::factory()->for($client, 'client')->create([
            'tz_file_id' => $legacyFile->id,
            'attachment_file_ids' => null,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.attachment_file_ids', [$legacyFile->id])
            ->assertJsonCount(1, 'data.attachment_files')
            ->assertJsonPath('data.attachment_files.0.id', $legacyFile->id)
            ->assertJsonPath('data.attachment_files.0.url', $legacyFile->url());
    }

    public function test_client_cannot_view_another_clients_order(): void
    {
        [, $token] = $this->authedUser();
        $foreign = Order::factory()->create();

        $this->getJson("/api/v1/orders/{$foreign->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }
}
