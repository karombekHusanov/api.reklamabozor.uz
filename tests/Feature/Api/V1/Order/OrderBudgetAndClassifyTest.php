<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\AgentProfileStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderBudgetAndClassifyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function authedUser(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('test')->plainTextToken];
    }

    private function enableAssistant(): void
    {
        Cache::flush();
        config([
            'services.assistant.enabled' => true,
            'services.assistant.api_key' => 'test-key',
            'services.assistant.base_url' => 'https://provider.test/v1',
            'services.assistant.model' => 'test/model',
        ]);
    }

    private function fakeAnswer(string $content): void
    {
        Http::fake([
            'provider.test/*' => Http::response([
                'model' => 'test/model',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ]),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function place(string $token, array $extra = [])
    {
        return $this->postJson('/api/v1/orders', [
            'description' => 'Kafe uchun banner chiqarib o\'rnatish kerak.',
            ...$extra,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_budget_is_required(): void
    {
        Http::fake();
        [, $token] = $this->authedUser();

        $this->place($token)->assertUnprocessable()->assertJsonValidationErrors(['budget']);
        $this->place($token, ['budget' => 0])->assertUnprocessable()->assertJsonValidationErrors(['budget']);
    }

    public function test_budget_is_stored_as_budget_max(): void
    {
        Http::fake();
        [$client, $token] = $this->authedUser();

        $this->place($token, ['budget' => 2500000])->assertCreated();

        $order = Order::where('client_id', $client->id)->firstOrFail();
        $this->assertEquals(2500000, $order->budget_max);
        $this->assertNull($order->budget_min);
    }

    public function test_budget_is_required_for_directed_orders(): void
    {
        Http::fake();
        [, $token] = $this->authedUser();
        $profile = AgentProfile::factory()->create(['status' => AgentProfileStatus::Approved]);

        $this->place($token, ['agent_profile_id' => $profile->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['budget']);
    }

    public function test_category_is_inferred_from_description(): void
    {
        $this->enableAssistant();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create(['is_active' => true]);
        $this->fakeAnswer((string) $category->id);

        $this->place($token, ['budget' => 1000000])->assertCreated();

        $order = Order::where('client_id', $client->id)->firstOrFail();
        $this->assertSame($category->id, $order->category_id);
        $this->assertSame($category->type, $order->category_type);
    }

    public function test_unknown_or_garbled_classifier_answer_leaves_order_uncategorized(): void
    {
        $this->enableAssistant();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create(['is_active' => true]);

        foreach (['99999', 'null', 'Men tanlayman: '.$category->id] as $answer) {
            $this->fakeAnswer($answer);
            $this->place($token, ['budget' => 1000000])->assertCreated();
        }

        $this->assertSame(3, Order::where('client_id', $client->id)->whereNull('category_id')->count());
    }

    public function test_inactive_category_is_not_used(): void
    {
        $this->enableAssistant();
        [$client, $token] = $this->authedUser();
        $category = Category::factory()->create(['is_active' => false]);
        $this->fakeAnswer((string) $category->id);

        $this->place($token, ['budget' => 1000000])->assertCreated();

        $this->assertNull(Order::where('client_id', $client->id)->firstOrFail()->category_id);
    }

    public function test_disabled_assistant_still_creates_the_order(): void
    {
        config(['services.assistant.enabled' => false]);
        Http::fake();
        [$client, $token] = $this->authedUser();
        Category::factory()->create(['is_active' => true]);

        $this->place($token, ['budget' => 1000000])->assertCreated();

        Http::assertNothingSent();
        $this->assertNull(Order::where('client_id', $client->id)->firstOrFail()->category_id);
    }

    public function test_provider_failure_or_timeout_still_creates_the_order(): void
    {
        $this->enableAssistant();
        [$client, $token] = $this->authedUser();
        Category::factory()->create(['is_active' => true]);

        Http::fake(['provider.test/*' => Http::response('boom', 500)]);
        $this->place($token, ['budget' => 1000000])->assertCreated();

        Http::fake(['provider.test/*' => fn () => throw new ConnectionException('timeout')]);
        $this->place($token, ['budget' => 1000000])->assertCreated();

        $this->assertSame(2, Order::where('client_id', $client->id)->whereNull('category_id')->count());
    }

    public function test_classifier_is_skipped_when_category_is_given(): void
    {
        $this->enableAssistant();
        Http::fake();
        [, $token] = $this->authedUser();
        $category = Category::factory()->create(['is_active' => true]);

        $this->place($token, ['budget' => 1000000, 'category_id' => $category->id])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_classifier_is_skipped_for_directed_orders(): void
    {
        $this->enableAssistant();
        Http::fake();
        [, $token] = $this->authedUser();
        $profile = AgentProfile::factory()->create(['status' => AgentProfileStatus::Approved]);

        $this->place($token, ['budget' => 1000000, 'agent_profile_id' => $profile->id])->assertCreated();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'provider.test'));
    }

    public function test_platform_contact_is_public(): void
    {
        config([
            'legal.platform.phone' => '+998901112233',
            'legal.platform.work_hours' => '09:00–18:00',
            'legal.platform.email' => 'support@reklamabozor.uz',
        ]);

        $this->getJson('/api/v1/platform-contact')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', '+998901112233')
            ->assertJsonPath('data.work_hours', '09:00–18:00')
            ->assertJsonPath('data.email', 'support@reklamabozor.uz');
    }

    public function test_platform_contact_returns_nulls_when_unset(): void
    {
        config([
            'legal.platform.phone' => null,
            'legal.platform.work_hours' => null,
            'legal.platform.email' => null,
        ]);

        $this->getJson('/api/v1/platform-contact')
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.work_hours', null)
            ->assertJsonPath('data.email', null);
    }
}
