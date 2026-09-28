<?php

namespace App\Services\Realtime;

use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\DirectChatMessageResource;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes client ↔ agent chat activity to both participants' personal
 * Centrifugo channels (`user:{id}`), so threads and the inbox update without
 * polling. The DB stays the source of truth: a failed push is logged and the
 * next thread open / reconnect catches the client up.
 */
class ChatBroadcaster
{
    public function __construct(private readonly CentrifugoClient $centrifugo) {}

    public function directMessage(DirectChat $chat, DirectChatMessage $message): void
    {
        $message->loadMissing('attachments');

        $this->push([$chat->client_id, $chat->agent_id], [
            'type' => 'chat.message',
            'chat_type' => 'direct',
            'chat_id' => $chat->id,
            'order_id' => $chat->order_id,
            'message' => (new DirectChatMessageResource($message))->resolve(),
        ]);
    }

    public function orderMessage(Chat $chat, ChatMessage $message): void
    {
        $message->loadMissing('attachments');

        $this->push([$chat->client_id, $chat->agent_id], [
            'type' => 'chat.message',
            'chat_type' => 'order',
            'chat_id' => $chat->id,
            'order_id' => $chat->order_id,
            'message' => (new ChatMessageResource($message))->resolve(),
        ]);
    }

    /** Read receipt → the sender's ticks turn blue. */
    public function read(string $chatType, int $chatId, int $readerId, int $notifyUserId): void
    {
        $this->push([$notifyUserId], [
            'type' => 'chat.read',
            'chat_type' => $chatType,
            'chat_id' => $chatId,
            'reader_id' => $readerId,
            'read_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    private function push(array $userIds, array $data): void
    {
        if (! LiveStatsService::enabled()) {
            return;
        }

        $prefix = (string) config('realtime.channels.user_prefix');
        $channels = array_map(fn (int $id): string => $prefix.$id, array_values(array_unique($userIds)));

        try {
            $this->centrifugo->broadcast($channels, $data);
        } catch (Throwable $e) {
            Log::warning('realtime.chat_push_failed', ['type' => $data['type'], 'error' => $e->getMessage()]);
        }
    }
}
