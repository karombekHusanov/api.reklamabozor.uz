<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use App\Services\Realtime\ChatBroadcaster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    /**
     * All conversations the user takes part in (either side), newest activity first.
     * Optionally narrowed to threads with a specific agency profile.
     *
     * @return Collection<int, Chat>
     */
    public function listForUser(User $user, ?int $agentProfileId = null): Collection
    {
        $chats = Chat::query()
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('agent_id', $user->id))
            ->when($agentProfileId !== null, fn ($q) => $q->where('agent_profile_id', $agentProfileId))
            ->with(['order.category', 'client', 'agent', 'agentProfile', 'lastMessage.attachments'])
            ->latest('updated_at')
            ->get();

        return $chats;
    }

    /**
     * The order's chat, guarded to its two participants.
     */
    public function forOrder(User $user, Order $order): Chat
    {
        /** @var Chat|null $chat */
        $chat = $order->chat()->with(['client', 'agent', 'agentProfile', 'order'])->first();

        abort_if($chat === null || ! $chat->isParticipant($user), 404);

        return $chat;
    }

    /**
     * Messages for the chat (optionally only after a known id, for polling).
     * Fetching marks the other side's messages as read.
     *
     * @return Collection<int, ChatMessage>
     */
    public function messages(User $user, Order $order, ?int $afterId = null): Collection
    {
        $chat = $this->forOrder($user, $order);

        $messages = $chat->messages()
            ->with('attachments')
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->get();

        // Opening (or polling) the thread means the user has seen everything sent to them.
        $this->markRead($user, $chat);

        return $messages;
    }

    /** Mark everything sent to $user as read and tell the other side. */
    public function markRead(User $user, Chat $chat): void
    {
        abort_if(! $chat->isParticipant($user), 404);

        $updated = $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($updated > 0) {
            $other = $user->id === $chat->client_id ? $chat->agent_id : $chat->client_id;
            $this->broadcaster->read('order', $chat->id, $user->id, (int) $other);
        }
    }

    /**
     * @param  list<int>  $fileIds
     */
    public function send(User $user, Order $order, ?string $body, array $fileIds = []): ChatMessage
    {
        $chat = $this->forOrder($user, $order);

        // The order conversation stays open for both participants regardless of
        // order status — client and agent can keep talking after completion.

        // File-only messages are allowed; the DB keeps body non-null (empty string).
        $body = $body !== null ? trim($body) : '';

        if ($body === '' && $fileIds === []) {
            throw ValidationException::withMessages([
                'body' => ['Write a message or attach a file.'],
            ]);
        }

        $files = MessageAttachments::resolve($user, $fileIds);
        MessageAttachments::assertAgentSendsNoImages($user, (int) $chat->agent_id, $files);

        $recipient = $chat->otherParticipant($user);

        // Ping the recipient only when they have nothing unread yet — one nudge
        // per "batch", not one per message.
        $shouldPing = $chat->unreadCountFor($recipient) === 0;

        /** @var ChatMessage $message */
        $message = $chat->messages()->create([
            'sender_id' => $user->id,
            'body' => $body,
        ]);

        MessageAttachments::attach($message->attachments(), $files);
        $message->setRelation('attachments', $files->values());

        $chat->touch();
        $this->broadcaster->orderMessage($chat, $message);

        if ($shouldPing) {
            try {
                $this->notifier->notifyNewChatMessage($order, $recipient);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $message;
    }
}
