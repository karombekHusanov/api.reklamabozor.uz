<?php

namespace App\Services\Chat;

use App\Enums\OrderStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ChatService
{
    /**
     * Order statuses in which the conversation is still writable. Terminal
     * orders keep the chat readable but frozen.
     */
    private const WRITABLE_STATUSES = [OrderStatus::InProgress, OrderStatus::WorkSubmitted];

    public function __construct(
        private readonly OrderNotifier $notifier,
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
            ->with(['order.category', 'client', 'agent.agentProfile', 'lastMessage.attachments'])
            ->latest('updated_at')
            ->get();

        if ($agentProfileId === null) {
            return $chats;
        }

        return $chats
            ->filter(fn (Chat $chat) => $chat->otherParticipant($user)->agentProfile?->id === $agentProfileId)
            ->values();
    }

    /**
     * The order's chat, guarded to its two participants.
     */
    public function forOrder(User $user, Order $order): Chat
    {
        /** @var Chat|null $chat */
        $chat = $order->chat()->with(['client', 'agent.agentProfile', 'order'])->first();

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
        $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $messages;
    }

    /**
     * @param  list<int>  $fileIds
     */
    public function send(User $user, Order $order, ?string $body, array $fileIds = []): ChatMessage
    {
        $chat = $this->forOrder($user, $order);

        if (! in_array($order->status, self::WRITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'chat' => ['This conversation is closed.'],
            ]);
        }

        // File-only messages are allowed; the DB keeps body non-null (empty string).
        $body = $body !== null ? trim($body) : '';

        if ($body === '' && $fileIds === []) {
            throw ValidationException::withMessages([
                'body' => ['Write a message or attach a file.'],
            ]);
        }

        $files = MessageAttachments::resolve($user, $fileIds);

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
