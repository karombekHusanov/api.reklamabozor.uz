<?php

namespace Tests\Feature\Api\V1\Chat;

use App\Enums\OrderStatus;
use App\Models\Chat;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\File;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Client ↔ agent chat over the WebSocket: sends arrive through the
 * Centrifugo RPC proxy, every new message / read receipt is pushed to both
 * participants' `user:{id}` channels, and the agent side may not send images.
 */
class RealtimeChatTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_SECRET = 'proxy-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'realtime.enabled' => true,
            'realtime.api_url' => 'http://centrifugo.test/api',
            'realtime.api_key' => 'api-key',
            'realtime.proxy_secret' => self::PROXY_SECRET,
            'services.telegram.mini_app_url' => 'https://app.test',
        ]);
        Http::fake(['*' => Http::response(['result' => []])]);
    }

    /** @return array{0: User, 1: User, 2: DirectChat} */
    private function directChat(): array
    {
        $client = User::factory()->create(['telegram_id' => 111222333]);
        $agent = User::factory()->agent()->create(['telegram_id' => 444555666]);

        return [$client, $agent, DirectChat::factory()->between($client, $agent)->create()];
    }

    /** @param  array<string, mixed>  $data */
    private function rpc(User $user, string $method, array $data, ?string $secret = self::PROXY_SECRET)
    {
        return $this->postJson('/api/v1/centrifugo/rpc', [
            'client' => 'abc',
            'transport' => 'websocket',
            'user' => (string) $user->id,
            'method' => $method,
            'data' => $data,
        ], $secret !== null ? ['X-Centrifugo-Proxy-Secret' => $secret] : []);
    }

    private function assertBroadcast(string $type, array $channels): void
    {
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/broadcast')
            && $r['data']['type'] === $type
            && $r['channels'] === $channels);
    }

    public function test_rpc_send_persists_and_pushes_to_both_participants(): void
    {
        [$client, $agent, $chat] = $this->directChat();

        $this->rpc($client, 'chat.send', ['type' => 'direct', 'id' => $chat->id, 'body' => 'Salom!'])
            ->assertOk()
            ->assertJsonPath('result.data.message.body', 'Salom!')
            ->assertJsonMissingPath('error');

        $this->assertDatabaseHas('direct_chat_messages', ['direct_chat_id' => $chat->id, 'body' => 'Salom!']);
        $this->assertBroadcast('chat.message', ['user:'.$client->id, 'user:'.$agent->id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/broadcast')
            && $r['data']['chat_type'] === 'direct'
            && $r['data']['chat_id'] === $chat->id);
    }

    public function test_rpc_send_to_order_deal_chat(): void
    {
        $client = User::factory()->create(['telegram_id' => 111222333]);
        $agent = User::factory()->create(['telegram_id' => 444555666]);
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        $chat = Chat::factory()->forDeal($order, $agent)->create();

        $this->rpc($agent, 'chat.send', ['type' => 'order', 'id' => $order->id, 'body' => 'Ish boshlandi'])
            ->assertOk()
            ->assertJsonPath('result.data.message.body', 'Ish boshlandi');

        $this->assertDatabaseHas('chat_messages', ['chat_id' => $chat->id, 'body' => 'Ish boshlandi']);
        $this->assertBroadcast('chat.message', ['user:'.$client->id, 'user:'.$agent->id]);
    }

    public function test_rpc_rejects_missing_or_wrong_secret(): void
    {
        [$client, , $chat] = $this->directChat();

        $this->rpc($client, 'chat.send', ['type' => 'direct', 'id' => $chat->id, 'body' => 'x'], null)->assertForbidden();
        $this->rpc($client, 'chat.send', ['type' => 'direct', 'id' => $chat->id, 'body' => 'x'], 'wrong')->assertForbidden();

        $this->assertSame(0, DirectChatMessage::query()->count());
    }

    public function test_rpc_stranger_gets_not_found_error(): void
    {
        [, , $chat] = $this->directChat();

        $this->rpc(User::factory()->create(), 'chat.send', ['type' => 'direct', 'id' => $chat->id, 'body' => 'x'])
            ->assertOk()
            ->assertJsonPath('error.code', 404);

        $this->assertSame(0, DirectChatMessage::query()->count());
    }

    public function test_rpc_validation_error_is_reported_in_centrifugo_format(): void
    {
        [$client, , $chat] = $this->directChat();

        $this->rpc($client, 'chat.send', ['type' => 'direct', 'id' => $chat->id])
            ->assertOk()
            ->assertJsonPath('error.code', 422);
    }

    public function test_agent_cannot_send_images_but_can_send_documents(): void
    {
        [$client, $agent, $chat] = $this->directChat();
        $image = File::factory()->create(['uploaded_by' => $agent->id, 'mime_type' => 'image/png']);
        $pdf = File::factory()->create(['uploaded_by' => $agent->id, 'mime_type' => 'application/pdf']);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", ['file_ids' => [$image->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_ids');

        $this->rpc($agent, 'chat.send', ['type' => 'direct', 'id' => $chat->id, 'file_ids' => [$image->id]])
            ->assertJsonPath('error.code', 422);

        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", ['file_ids' => [$pdf->id]])
            ->assertCreated();

        $this->assertSame(1, DirectChatMessage::query()->count());
    }

    public function test_client_can_still_send_images_and_resource_flags_it(): void
    {
        [$client, $agent, $chat] = $this->directChat();
        $image = File::factory()->create(['uploaded_by' => $client->id, 'mime_type' => 'image/png']);

        Sanctum::actingAs($client);
        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", ['file_ids' => [$image->id]])->assertCreated();
        $this->getJson("/api/v1/direct-chats/{$chat->id}")->assertJsonPath('data.chat.can_send_images', true);

        Sanctum::actingAs($agent);
        $this->getJson("/api/v1/direct-chats/{$chat->id}")->assertJsonPath('data.chat.can_send_images', false);
    }

    public function test_agent_image_ban_applies_to_deal_chat(): void
    {
        $client = User::factory()->create();
        $agent = User::factory()->create();
        $order = Order::factory()->for($client, 'client')->status(OrderStatus::InProgress)->create();
        Chat::factory()->forDeal($order, $agent)->create();
        $image = File::factory()->create(['uploaded_by' => $agent->id, 'mime_type' => 'image/jpeg']);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/orders/{$order->id}/chat/messages", ['file_ids' => [$image->id]])
            ->assertUnprocessable();
    }

    public function test_rpc_read_marks_messages_and_notifies_sender(): void
    {
        [$client, $agent, $chat] = $this->directChat();
        $message = $chat->messages()->create(['sender_id' => $agent->id, 'type' => 'text', 'body' => 'Narx 1 mln']);

        $this->rpc($client, 'chat.read', ['type' => 'direct', 'id' => $chat->id])
            ->assertOk()
            ->assertJsonPath('result.data.ok', true);

        $this->assertNotNull($message->fresh()->read_at);
        $this->assertBroadcast('chat.read', ['user:'.$agent->id]);
    }

    public function test_nothing_is_pushed_when_realtime_is_disabled(): void
    {
        config(['realtime.enabled' => false]);
        [$client, , $chat] = $this->directChat();

        Sanctum::actingAs($client);
        $this->postJson("/api/v1/direct-chats/{$chat->id}/messages", ['body' => 'Salom'])->assertCreated();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'centrifugo.test'));
    }

    public function test_admin_can_list_and_read_chat_history(): void
    {
        [$client, $agent, $chat] = $this->directChat();
        $chat->messages()->create(['sender_id' => $client->id, 'type' => 'text', 'body' => 'Birinchi']);
        $chat->messages()->create(['sender_id' => $agent->id, 'type' => 'text', 'body' => 'Ikkinchi']);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/chats?type=direct')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $chat->id)
            ->assertJsonPath('data.items.0.messages_count', 2)
            ->assertJsonPath('data.items.0.last_message.body', 'Ikkinchi');

        $this->getJson('/api/v1/admin/chats?type=direct&search='.urlencode($client->first_name))
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson("/api/v1/admin/chats/direct/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('data.chat.client.id', $client->id)
            ->assertJsonPath('data.messages.0.body', 'Birinchi')
            ->assertJsonPath('data.messages.1.body', 'Ikkinchi');

        $this->getJson('/api/v1/admin/chats?type=order')->assertJsonPath('data.meta.total', 0);
    }

    public function test_non_admin_cannot_read_chat_history(): void
    {
        [$client, , $chat] = $this->directChat();
        Sanctum::actingAs($client);

        $this->getJson('/api/v1/admin/chats')->assertForbidden();
        $this->getJson("/api/v1/admin/chats/direct/{$chat->id}")->assertForbidden();
    }
}
