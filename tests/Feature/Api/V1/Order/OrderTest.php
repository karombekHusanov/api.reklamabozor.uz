<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\File;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Region;
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

    /**
     * Default client location for create-order payloads.
     *
     * @return array{lat: float, lng: float, location_label: string}
     */
    private function locationPayload(): array
    {
        return [
            'lat' => 41.311081,
            'lng' => 69.279716,
            'location_label' => 'Toshkent',
        ];
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
            'title' => 'Metro banner campaign',
            'description' => 'Need a banner campaign across Tashkent metro.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.title', 'Metro banner campaign')
            ->assertJsonPath('data.attachment_file_ids', [$file->id])
            ->assertJsonCount(1, 'data.attachment_files')
            ->assertJsonPath('data.location_label', 'Toshkent')
            ->assertJsonPath('data.lat', '41.3110810')
            ->assertJsonPath('data.lng', '69.2797160');

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'category_id' => $category->id,
            'tz_file_id' => null,
            'status' => 'new',
            'location_label' => 'Toshkent',
            'show_files_in_showcase' => true,
        ]);
    }

    public function test_client_can_set_show_files_in_showcase_false(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Private attachments',
            'description' => 'Hide files on showcase.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
            'show_files_in_showcase' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'show_files_in_showcase' => false,
        ]);
    }

    public function test_omitting_show_files_in_showcase_defaults_to_true(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Default showcase files',
            'description' => 'Flag omitted.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'show_files_in_showcase' => true,
        ]);
    }

    public function test_client_order_detail_still_returns_files_when_showcase_flag_false(): void
    {
        [$client, $token] = $this->authedUser();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $order = Order::factory()->for($client, 'client')->create([
            'attachment_file_ids' => [$file->id],
            'show_files_in_showcase' => false,
        ]);

        $this->getJson("/api/v1/orders/{$order->id}", ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'data.attachment_files')
            ->assertJsonPath('data.attachment_files.0.id', $file->id)
            ->assertJsonPath('data.attachment_file_ids', [$file->id]);
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
            'title' => 'Test project',
            'description' => 'Urgent outdoor campaign.',
            ...$this->locationPayload(),
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
            'title' => 'Test project',
            'description' => 'x',
            ...$this->locationPayload(),
            'deadline' => 'next_year',
            'attachment_file_ids' => [$stranger->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deadline', 'attachment_file_ids.0']);
    }

    public function test_order_validates_required_fields(): void
    {
        [, $token] = $this->authedUser();

        // The MVP request form only insists on the description: category, region,
        // files and the map pin are all optional.
        $this->postJson('/api/v1/orders', [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description'])
            ->assertJsonMissingValidationErrors([
                'category_id', 'lat', 'lng', 'title', 'attachment_file_ids', 'region_id',
            ]);
    }

    public function test_map_pin_requires_both_coordinates(): void
    {
        [, $token] = $this->authedUser();

        $this->postJson('/api/v1/orders', [
            'description' => 'Kafe uchun banner kerak.',
            'lat' => 41.31,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lng']);
    }

    public function test_client_can_place_a_description_only_request(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();

        $response = $this->postJson('/api/v1/orders', [
            'description' => '  Do\'kon ochilishi uchun bayram reklamasi kerak.  ',
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        $order = Order::query()->where('client_id', $client->id)->firstOrFail();

        $this->assertNull($order->category_id);
        $this->assertNull($order->category_type);
        $this->assertNull($order->lat);
        $this->assertNull($order->lng);
        // Title falls back to the request text when no category was picked.
        $this->assertSame("Do'kon ochilishi uchun bayram reklamasi kerak.", $order->title);
        $response->assertJsonPath('data.id', $order->id);
    }

    public function test_client_can_place_a_text_only_request_without_title_or_files(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create(['name_uz' => 'Boshqa']);

        $response = $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'description' => 'Need a 3x6m billboard for one month.',
            ...$this->locationPayload(),
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        // Title falls back to the category label; attachments default to empty.
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'client_id' => $client->id,
            'title' => 'Boshqa',
        ]);
    }

    public function test_client_can_place_an_order_without_region(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Nationwide campaign',
            'description' => 'All Uzbekistan.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.region', null)
            ->assertJsonPath('data.district', null);

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'region_id' => null,
            'district_id' => null,
        ]);
    }

    public function test_client_can_place_an_order_with_region_and_district(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $city = Region::query()->where('code', 'toshkent-shahri')->firstOrFail();
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Chilonzor campaign',
            'description' => 'Local work.',
            ...$this->locationPayload(),
            'region_id' => $city->id,
            'district_id' => $district->id,
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.region.id', $city->id)
            ->assertJsonPath('data.region.code', 'toshkent-shahri')
            ->assertJsonPath('data.district.id', $district->id)
            ->assertJsonPath('data.district.code', 'chilonzor');

        $this->assertDatabaseHas('orders', [
            'client_id' => $client->id,
            'region_id' => $city->id,
            'district_id' => $district->id,
        ]);
    }

    public function test_order_rejects_district_not_child_of_region(): void
    {
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $andijon = Region::query()->where('code', 'andijon')->firstOrFail();
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Mismatch',
            'description' => 'Bad district.',
            ...$this->locationPayload(),
            'region_id' => $andijon->id,
            'district_id' => $district->id,
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district_id']);
    }

    public function test_order_derives_location_label_from_region_when_empty(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);
        $city = Region::query()->where('code', 'toshkent-shahri')->firstOrFail();
        $district = Region::query()->where('code', 'chilonzor')->firstOrFail();

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Derived label',
            'description' => 'No map label.',
            'lat' => 41.311081,
            'lng' => 69.279716,
            'region_id' => $city->id,
            'district_id' => $district->id,
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('data.location_label', 'Chilonzor, Toshkent shahri');
    }

    public function test_attachment_files_must_belong_to_the_client(): void
    {
        [, $token] = $this->authedUser();
        $category = Category::factory()->create();
        $strangerFile = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/orders', [
            'category_id' => $category->id,
            'title' => 'Test project',
            'description' => 'x',
            ...$this->locationPayload(),
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
            'title' => 'Test project',
            'description' => 'Need outdoor billboards.',
            ...$this->locationPayload(),
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

        // Matching agent so the category is NOT empty (empty → broadcast-all).
        $insider = User::factory()->create(['telegram_id' => 111222333]);
        AgentProfile::factory()->for($insider)->approved()->create()
            ->categories()->attach($orderCategory);

        $outsider = User::factory()->create(['telegram_id' => 999888777]);
        AgentProfile::factory()->for($outsider)->approved()->create()
            ->categories()->attach($otherCategory);

        $this->postJson('/api/v1/orders', [
            'category_id' => $orderCategory->id,
            'title' => 'Test project',
            'description' => 'Only my category should hear about this.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 111222333);
        Http::assertNotSent(fn ($request) => ($request['chat_id'] ?? null) === 999888777);
    }

    public function test_empty_category_notifies_all_approved_agents(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $emptyCategory = Category::factory()->create();
        $servedCategory = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $agentA = User::factory()->create(['telegram_id' => 101010101]);
        AgentProfile::factory()->for($agentA)->approved()->create()
            ->categories()->attach($servedCategory);

        $agentB = User::factory()->create(['telegram_id' => 202020202]);
        AgentProfile::factory()->for($agentB)->approved()->create()
            ->categories()->attach($servedCategory);

        $this->postJson('/api/v1/orders', [
            'category_id' => $emptyCategory->id,
            'title' => 'Test project',
            'description' => 'No one serves this category yet.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 101010101);
        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 202020202);
    }

    public function test_other_category_notifies_all_approved_agents(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();
        $other = Category::query()->where('is_other', true)->where('type', 'agent')->firstOrFail();
        $servedCategory = Category::factory()->create();
        $file = File::factory()->create(['uploaded_by' => $client->id]);

        $agent = User::factory()->create(['telegram_id' => 303030303]);
        AgentProfile::factory()->for($agent)->approved()->create()
            ->categories()->attach($servedCategory);

        $this->postJson('/api/v1/orders', [
            'category_id' => $other->id,
            'title' => 'Test project',
            'description' => 'Something custom outside the list.',
            ...$this->locationPayload(),
            'attachment_file_ids' => [$file->id],
        ], ['Authorization' => 'Bearer '.$token])->assertCreated();

        Http::assertSent(fn ($request) => ($request['chat_id'] ?? null) === 303030303);
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
            'title' => 'Test project',
            'description' => 'Need a launch campaign.',
            ...$this->locationPayload(),
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

    public function test_client_list_hides_cancelled_orders(): void
    {
        [$client, $token] = $this->authedUser();
        Order::factory()->for($client, 'client')->status(OrderStatus::New)->create();
        $cancelled = Order::factory()->for($client, 'client')->status(OrderStatus::Cancelled)->create();

        $ids = $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.*.id');

        $this->assertNotContains($cancelled->id, $ids);

        $this->getJson("/api/v1/orders/{$cancelled->id}", ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
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

    public function test_client_can_cancel_a_new_order(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::New)->create();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_client_can_cancel_an_order_while_offers_are_open(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_cancelling_an_order_rejects_pending_offers(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::OffersSent)->create();
        $offer = Offer::factory()->for($order)->create(['agent_id' => User::factory()->create()->id]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertSame(OfferStatus::Rejected, $offer->fresh()->status);
    }

    public function test_client_cannot_cancel_once_the_work_is_delivered(): void
    {
        // An active deal is cancellable while unpaid (see CancelActiveOrderTest);
        // once the agent delivered, it is not.
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::WorkSubmitted)->create();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order');

        $this->assertSame(OrderStatus::WorkSubmitted, $order->fresh()->status);
    }

    public function test_client_can_cancel_while_awaiting_payment(): void
    {
        [$client, $token] = $this->authedUser();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::AwaitingPayment)->create();
        Offer::factory()->for($order)->create(['status' => OfferStatus::Accepted]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(OfferStatus::Accepted, $order->offers()->first()->status);
    }

    public function test_client_cannot_cancel_another_clients_order(): void
    {
        [, $token] = $this->authedUser();
        $foreign = Order::factory()->status(OrderStatus::New)->create();

        $this->postJson("/api/v1/orders/{$foreign->id}/cancel", [], ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();
    }

    public function test_cancelling_an_order_requires_authentication(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertUnauthorized();
    }
}
