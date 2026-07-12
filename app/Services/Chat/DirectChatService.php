<?php

namespace App\Services\Chat;

use App\Enums\AgentProfileStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\User;
use App\Services\Order\OrderNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DirectChatService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * @return Collection<int, DirectChat>
     */
    public function listForUser(User $user, ?int $agentProfileId = null): Collection
    {
        $chats = DirectChat::query()
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('agent_id', $user->id))
            ->with(['client', 'agent.agentProfile', 'lastMessage.attachments'])
            ->latest('updated_at')
            ->get();

        if ($agentProfileId === null) {
            return $chats;
        }

        return $chats
            ->filter(fn (DirectChat $chat) => $chat->otherParticipant($user)->agentProfile?->id === $agentProfileId)
            ->values();
    }

    /**
     * Open (or return) the direct conversation between a client and an approved agency.
     */
    public function open(User $user, AgentProfile $agentProfile): DirectChat
    {
        abort_unless($agentProfile->status === AgentProfileStatus::Approved, 404);

        $agent = $agentProfile->user;

        if ($user->id === $agent->id) {
            throw ValidationException::withMessages([
                'chat' => ['You cannot start a chat with yourself.'],
            ]);
        }

        if ($user->role === Role::Client) {
            return DirectChat::query()->firstOrCreate(
                ['client_id' => $user->id, 'agent_id' => $agent->id],
            );
        }

        throw ValidationException::withMessages([
            'chat' => ['Only clients can start a conversation from an agency profile.'],
        ]);
    }

    public function forChat(User $user, DirectChat $chat): DirectChat
    {
        abort_if(! $chat->isParticipant($user), 404);

        return $chat->load(['client', 'agent.agentProfile']);
    }

    /**
     * @return Collection<int, DirectChatMessage>
     */
    public function messages(User $user, DirectChat $chat, ?int $afterId = null): Collection
    {
        $chat = $this->forChat($user, $chat);

        $messages = $chat->messages()
            ->with('attachments')
            ->when($afterId !== null, fn ($q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->get();

        $chat->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $messages;
    }

    /**
     * @param  list<int>  $fileIds
     */
    public function send(User $user, DirectChat $chat, ?string $body, array $fileIds = []): DirectChatMessage
    {
        $chat = $this->forChat($user, $chat);

        $body = $body !== null ? trim($body) : '';

        if ($body === '' && $fileIds === []) {
            throw ValidationException::withMessages([
                'body' => ['Write a message or attach a file.'],
            ]);
        }

        $files = MessageAttachments::resolve($user, $fileIds);
        $recipient = $chat->otherParticipant($user);
        $shouldPing = $chat->unreadCountFor($recipient) === 0;

        /** @var DirectChatMessage $message */
        $message = $chat->messages()->create([
            'sender_id' => $user->id,
            'body' => $body,
        ]);

        MessageAttachments::attach($message->attachments(), $files);
        $message->setRelation('attachments', $files->values());

        $chat->touch();

        if ($shouldPing) {
            try {
                $this->notifier->notifyNewDirectChatMessage($chat, $recipient);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $message;
    }

    public function findForAgentProfile(User $user, int $agentProfileId): ?DirectChat
    {
        return $this->listForUser($user, $agentProfileId)->first();
    }
}
