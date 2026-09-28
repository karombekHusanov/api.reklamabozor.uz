<?php

namespace App\Services\Admin;

use App\Models\Chat;
use App\Models\DirectChat;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only oversight of client ↔ agent conversations:
 * `direct` = negotiation / marketplace DMs (`direct_chats`),
 * `order` = the deal chat opened once an offer is accepted (`chats`).
 */
class ChatAdminService
{
    public const TYPES = ['direct', 'order'];

    /**
     * @param  array{type: string, search?: string|null, user_id?: int|null, order_id?: int|null, per_page?: int}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = $this->query($filters['type'])
            ->with(['client', 'agent', 'agentProfile', 'order', 'lastMessage.attachments'])
            ->withCount('messages')
            ->when($filters['user_id'] ?? null, fn (Builder $q, int $userId) => $q
                ->where(fn (Builder $w) => $w->where('client_id', $userId)->orWhere('agent_id', $userId)))
            ->when($filters['order_id'] ?? null, fn (Builder $q, int $orderId) => $q->where('order_id', $orderId))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', fn (Builder $q) => $this->search($q, trim((string) $filters['search'])))
            ->latest('updated_at');

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function find(string $type, int $id): DirectChat|Chat
    {
        /** @var DirectChat|Chat $chat */
        $chat = $this->query($type)
            ->with(['client', 'agent', 'agentProfile', 'order', 'messages' => fn ($q) => $q->with('attachments')->orderBy('id')])
            ->withCount('messages')
            ->findOrFail($id);

        return $chat;
    }

    /** @return array<string, mixed> */
    public function summary(DirectChat|Chat $chat): array
    {
        $isDirect = $chat instanceof DirectChat;
        $last = $chat->relationLoaded('lastMessage') ? $chat->lastMessage : null;

        return [
            'id' => $chat->id,
            'type' => $isDirect ? 'direct' : 'order',
            'order' => $chat->order ? [
                'id' => $chat->order->id,
                'title' => $chat->order->title,
                'status' => $chat->order->status?->value,
                'route' => $chat->order->route?->value,
            ] : null,
            'client' => $this->person($chat->client),
            'agent' => $this->person($chat->agent) + [
                'company_name' => $chat->agentProfile?->company_name,
                'agent_profile_id' => $chat->agent_profile_id,
            ],
            'messages_count' => (int) ($chat->messages_count ?? 0),
            'last_message' => $last ? [
                'sender_id' => $last->sender_id,
                'body' => $last->body,
                'attachments_count' => $last->attachments->count(),
                'created_at' => $last->created_at,
            ] : null,
            'blocked_at' => $isDirect ? $chat->blocked_at : null,
            'blocked_by' => $isDirect ? $chat->blocked_by : null,
            'created_at' => $chat->created_at,
            'updated_at' => $chat->updated_at,
        ];
    }

    /** @return Builder<DirectChat>|Builder<Chat> */
    private function query(string $type): Builder
    {
        return $type === 'order' ? Chat::query() : DirectChat::query();
    }

    /** @param  Builder<covariant Model>  $query */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower($term).'%';
        $person = fn (Builder $u) => $u
            ->whereRaw("LOWER(first_name || ' ' || COALESCE(last_name, '')) LIKE ?", [$like])
            ->orWhere('phone', 'like', '%'.$term.'%')
            ->orWhere('username', 'like', '%'.ltrim($term, '@').'%');

        $query->where(function (Builder $q) use ($term, $like, $person): void {
            $q->whereHas('client', $person)
                ->orWhereHas('agent', $person)
                ->orWhereHas('agentProfile', fn (Builder $p) => $p->whereRaw('LOWER(company_name) LIKE ?', [$like]));

            if (ctype_digit(ltrim($term, '#'))) {
                $q->orWhere('order_id', (int) ltrim($term, '#'));
            }
        });
    }

    /** @return array{id: int, name: string, phone: string|null, username: string|null} */
    private function person(?User $user): array
    {
        return [
            'id' => (int) $user?->id,
            'name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')),
            'phone' => $user?->phone,
            'username' => $user?->username,
        ];
    }
}
