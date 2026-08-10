<?php

namespace App\Services\Chat;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\DirectChat;
use App\Models\DirectChatMessage;
use App\Models\Offer;
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
            ->when($agentProfileId !== null, fn ($q) => $q->where('agent_profile_id', $agentProfileId))
            ->with(['client', 'agent', 'agentProfile', 'order.category', 'lastMessage.attachments'])
            ->latest('updated_at')
            ->get();

        return $chats;
    }

    /**
     * Open (or return) the marketplace DM between a client and an approved agency.
     * Always pins order_id = null so it never collides with order-scoped threads.
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

        // Capability check, not active-role check: every non-admin user holds
        // the client base role, so anyone can reach out to an agency as a client.
        if ($user->hasRole(Role::Client)) {
            return DirectChat::query()->firstOrCreate(
                [
                    'client_id' => $user->id,
                    'agent_id' => $agent->id,
                    'order_id' => null,
                ],
                ['agent_profile_id' => $agentProfile->id],
            );
        }

        throw ValidationException::withMessages([
            'chat' => ['Only clients can start a conversation from an agency profile.'],
        ]);
    }

    /**
     * Agent opens (or returns) the order-scoped thread from an offer under
     * negotiation, or from an accepted offer while payment is still pending.
     */
    public function openForOffer(User $agent, Offer $offer): DirectChat
    {
        abort_unless($offer->agent_id === $agent->id, 404);

        $this->assertOfferAllowsChat($offer);

        $order = $offer->order;
        $clientId = $order->client_id;

        if ($clientId === $agent->id) {
            throw ValidationException::withMessages([
                'chat' => ['You cannot start a chat with yourself.'],
            ]);
        }

        return DirectChat::query()->firstOrCreate(
            [
                'client_id' => $clientId,
                'agent_id' => $agent->id,
                'order_id' => $order->id,
            ],
            ['agent_profile_id' => $offer->agent_profile_id],
        );
    }

    /**
     * Client (order owner) opens (or returns) the order-scoped thread for an offer/interest.
     */
    public function openForClient(User $client, Offer $offer): DirectChat
    {
        $offer->loadMissing(['order', 'agentProfile']);

        $order = $offer->order;
        abort_unless($order !== null && (int) $order->client_id === (int) $client->id, 404);

        $this->assertOfferAllowsChat($offer);

        if ((int) $client->id === (int) $offer->agent_id) {
            throw ValidationException::withMessages([
                'chat' => ['You cannot start a chat with yourself.'],
            ]);
        }

        return DirectChat::query()->firstOrCreate(
            [
                'client_id' => $client->id,
                'agent_id' => $offer->agent_id,
                'order_id' => $order->id,
            ],
            ['agent_profile_id' => $offer->agent_profile_id],
        );
    }

    public function forChat(User $user, DirectChat $chat): DirectChat
    {
        abort_if(! $chat->isParticipant($user), 404);

        return $chat->load(['client', 'agent', 'agentProfile', 'order.category']);
    }

    /**
     * Soft end/block — both sides become read-only until the blocker unblocks.
     */
    public function block(User $user, DirectChat $chat): DirectChat
    {
        $chat = $this->forChat($user, $chat);

        if ($chat->isBlocked()) {
            throw ValidationException::withMessages([
                'chat' => ['This conversation is already ended.'],
            ]);
        }

        $chat->update([
            'blocked_at' => now(),
            'blocked_by' => $user->id,
        ]);

        return $chat->fresh(['client', 'agent', 'agentProfile', 'order.category']);
    }

    /**
     * Only the participant who ended the chat may reopen it.
     */
    public function unblock(User $user, DirectChat $chat): DirectChat
    {
        $chat = $this->forChat($user, $chat);

        if (! $chat->isBlocked()) {
            throw ValidationException::withMessages([
                'chat' => ['This conversation is not ended.'],
            ]);
        }

        if ((int) $chat->blocked_by !== (int) $user->id) {
            throw ValidationException::withMessages([
                'chat' => ['Only the person who ended the chat can reopen it.'],
            ]);
        }

        $chat->update([
            'blocked_at' => null,
            'blocked_by' => null,
        ]);

        return $chat->fresh(['client', 'agent', 'agentProfile', 'order.category']);
    }

    /**
     * Clear a block without requiring the blocker (e.g. client accepted this agent).
     */
    public function clearBlock(DirectChat $chat): void
    {
        if (! $chat->isBlocked()) {
            return;
        }

        $chat->update([
            'blocked_at' => null,
            'blocked_by' => null,
        ]);
    }

    /**
     * Find a pair thread. When $orderId is null, returns the marketplace DM
     * (whereNull order_id) — never an arbitrary order-scoped thread.
     */
    public function findPair(int $clientId, int $agentId, ?int $orderId = null): ?DirectChat
    {
        return DirectChat::query()
            ->where('client_id', $clientId)
            ->where('agent_id', $agentId)
            ->when(
                $orderId === null,
                fn ($q) => $q->whereNull('order_id'),
                fn ($q) => $q->where('order_id', $orderId),
            )
            ->first();
    }

    /**
     * Order-scoped DirectChat for an offer, if one already exists.
     */
    public function findForOffer(Offer $offer): ?DirectChat
    {
        $offer->loadMissing('order');

        if ($offer->order === null) {
            return null;
        }

        return $this->findPair(
            (int) $offer->order->client_id,
            (int) $offer->agent_id,
            (int) $offer->order_id,
        );
    }

    /**
     * Append a system/event message (price change, accept, …). Skips the blocked guard
     * so negotiation events can still land when the thread was previously ended —
     * callers should clear the block on accept before posting when needed.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function postEvent(
        DirectChat $chat,
        User $author,
        string $type,
        string $body,
        ?array $meta = null,
    ): DirectChatMessage {
        /** @var DirectChatMessage $message */
        $message = $chat->messages()->create([
            'sender_id' => $author->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
        ]);

        $chat->touch();

        return $message;
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

        if ($chat->isBlocked()) {
            throw ValidationException::withMessages([
                'chat' => ['This conversation has ended. New messages are not allowed.'],
            ]);
        }

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
            'type' => DirectChatMessage::TYPE_TEXT,
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
        return DirectChat::query()
            ->where(fn ($q) => $q->where('client_id', $user->id)->orWhere('agent_id', $user->id))
            ->where('agent_profile_id', $agentProfileId)
            ->whereNull('order_id')
            ->latest('updated_at')
            ->first();
    }

    /**
     * Active pending offer for a thread's context chip.
     * Order-scoped threads resolve to that order; marketplace DMs use the
     * latest open pending offer between the pair.
     */
    public function activeOfferForPair(DirectChat $chat): ?Offer
    {
        if ($chat->order_id !== null) {
            return Offer::query()
                ->where('agent_id', $chat->agent_id)
                ->where('order_id', $chat->order_id)
                ->where('status', OfferStatus::Pending)
                ->with('order')
                ->latest('id')
                ->first();
        }

        return Offer::query()
            ->where('agent_id', $chat->agent_id)
            ->where('status', OfferStatus::Pending)
            ->whereHas('order', function ($q) use ($chat): void {
                $q->where('client_id', $chat->client_id)
                    ->whereIn('status', array_map(
                        fn ($s) => $s->value,
                        OrderStatus::openForOffers(),
                    ));
            })
            ->with('order')
            ->latest('id')
            ->first();
    }

    private function assertOfferAllowsChat(Offer $offer): void
    {
        $offer->loadMissing(['order', 'agentProfile']);

        $order = $offer->order;
        if ($order === null) {
            throw ValidationException::withMessages([
                'offer' => ['This order is no longer open for negotiation.'],
            ]);
        }

        $negotiating = $offer->status === OfferStatus::Pending
            && $order->status->isOpenForOffers();

        $awaitingPayment = $offer->status === OfferStatus::Accepted
            && $order->status === OrderStatus::AwaitingPayment;

        if (! $negotiating && ! $awaitingPayment) {
            throw ValidationException::withMessages([
                'offer' => $offer->status === OfferStatus::Pending
                    ? ['This order is no longer open for negotiation.']
                    : ['You can only start a chat from a pending or awaiting-payment offer.'],
            ]);
        }
    }
}
