<?php

namespace App\Services\Activity;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\ProviderType;
use App\Enums\ReviewDirection;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\ChatMessage;
use App\Models\DirectChatMessage;
use App\Models\LiveOrderRead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\GlobalChat\GlobalChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Aggregated activity counters for GET /me/activity.
 *
 * Uses grouped COUNT queries only — never hydrates order/offer/chat collections,
 * and never calls OfferService::availableForAgent (that records order_views).
 */
class UserActivityService
{
    public function __construct(
        private readonly GlobalChatService $globalChat,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?Role $role = null, ?int $agentProfileId = null): array
    {
        $role ??= $user->role;

        if (! $user->hasRole($role)) {
            throw ValidationException::withMessages([
                'role' => ['You do not hold this role.'],
            ]);
        }

        $profiles = $user->providerProfiles()
            ->withCount([
                'portfolioItems as portfolio_items_count' => fn ($q) => $q->visible(),
                'categories',
            ])
            ->get();

        $scopedProfile = $this->resolveScopedProfile($profiles, $agentProfileId);
        $primaryProfile = $this->primaryFrom($profiles);
        $isProviderRole = in_array($role, [Role::Agent, Role::Designer], true);

        $chats = $this->chatUnread($user);
        $chats['global_unread'] = (int) $this->globalChat->unread($user)['count'];

        // Admin callers: zeroed/null-safe — no client/provider blocks (do not 403).
        if ($user->role === Role::Admin) {
            $client = null;
            $provider = null;
        } else {
            $client = $this->clientBlock($user);
            if ($profiles->isEmpty()) {
                // KYC-pending agent/designer still gets a stub so the client can
                // prompt "finish your profile" — ordinary clients stay null.
                $provider = $isProviderRole ? $this->emptyProviderBlock() : null;
            } else {
                $provider = $this->providerBlock($user, $profiles, $scopedProfile);
            }
        }

        $liveOrders = $this->liveOrdersBlock($user);

        $actionRequired = $this->actionRequired(
            role: $role,
            client: $client,
            provider: $provider,
            unreadThreads: $chats['unread_threads'],
        );

        return [
            'role' => $role->value,
            'roles' => $user->allRoles()->map(fn (Role $r) => $r->value)->values()->all(),
            // Only advertise a profile id on provider perspective (or explicit scope).
            'agent_profile_id' => $scopedProfile?->id
                ?? ($isProviderRole ? $primaryProfile?->id : null),
            'chats' => $chats,
            'client' => $client,
            'provider' => $provider,
            'live_orders' => $liveOrders,
            'notifications' => ['unread' => 0],
            'action_required' => $actionRequired,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Mark the Live Orders list as seen — clears the home "new" badge.
     *
     * @return array{count: int, last_seen_at: string}
     */
    public function markLiveOrdersSeen(User $user): array
    {
        $seenAt = now();

        $read = LiveOrderRead::query()->firstOrNew(['user_id' => $user->id]);
        $read->last_seen_at = $seenAt;
        $read->save();

        return [
            'count' => 0,
            'last_seen_at' => $seenAt->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     */
    private function resolveScopedProfile(Collection $profiles, ?int $agentProfileId): ?AgentProfile
    {
        if ($agentProfileId === null) {
            return null;
        }

        $profile = $profiles->firstWhere('id', $agentProfileId);

        if ($profile === null) {
            throw new NotFoundHttpException('Agent profile not found.');
        }

        return $profile;
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     */
    private function primaryFrom(Collection $profiles): ?AgentProfile
    {
        return $profiles->firstWhere('provider_type', ProviderType::Agent)
            ?? $profiles->first();
    }

    /**
     * @return array{
     *     unread_messages: int,
     *     unread_threads: int,
     *     order_unread_messages: int,
     *     direct_unread_messages: int,
     *     global_unread: int
     * }
     */
    private function chatUnread(User $user): array
    {
        $order = ChatMessage::query()
            ->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->where(fn ($q) => $q
                ->where('chats.client_id', $user->id)
                ->orWhere('chats.agent_id', $user->id))
            ->where('chat_messages.sender_id', '!=', $user->id)
            ->whereNull('chat_messages.read_at')
            ->selectRaw('COUNT(*) as messages, COUNT(DISTINCT chat_messages.chat_id) as threads')
            ->first();

        $direct = DirectChatMessage::query()
            ->join('direct_chats', 'direct_chats.id', '=', 'direct_chat_messages.direct_chat_id')
            ->where(fn ($q) => $q
                ->where('direct_chats.client_id', $user->id)
                ->orWhere('direct_chats.agent_id', $user->id))
            ->where('direct_chat_messages.sender_id', '!=', $user->id)
            ->whereNull('direct_chat_messages.read_at')
            ->selectRaw('COUNT(*) as messages, COUNT(DISTINCT direct_chat_messages.direct_chat_id) as threads')
            ->first();

        $orderMessages = (int) ($order->messages ?? 0);
        $orderThreads = (int) ($order->threads ?? 0);
        $directMessages = (int) ($direct->messages ?? 0);
        $directThreads = (int) ($direct->threads ?? 0);

        return [
            'unread_messages' => $orderMessages + $directMessages,
            'unread_threads' => $orderThreads + $directThreads,
            'order_unread_messages' => $orderMessages,
            'direct_unread_messages' => $directMessages,
            'global_unread' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientBlock(User $user): array
    {
        $byStatus = $this->emptyStatusMap();

        $rows = Order::query()
            ->where('client_id', $user->id)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($rows as $status => $count) {
            $key = $status instanceof OrderStatus ? $status->value : (string) $status;
            if (array_key_exists($key, $byStatus)) {
                $byStatus[$key] = (int) $count;
            }
        }

        $openStatuses = array_map(
            fn (OrderStatus $s) => $s->value,
            OrderStatus::openForOffers(),
        );

        $offersReceivedPending = (int) Offer::query()
            ->where('status', OfferStatus::Pending)
            ->whereHas('order', fn ($q) => $q
                ->where('client_id', $user->id)
                ->whereIn('status', $openStatuses))
            ->count();

        $reviewsPending = (int) Order::query()
            ->where('client_id', $user->id)
            ->where('status', OrderStatus::Completed)
            ->whereDoesntHave('reviews', fn ($q) => $q
                ->where('direction', ReviewDirection::ClientToProvider))
            ->count();

        return [
            'orders_total' => array_sum($byStatus),
            'orders_open' => $byStatus[OrderStatus::New->value] + $byStatus[OrderStatus::OffersSent->value],
            'orders_awaiting_payment' => $byStatus[OrderStatus::AwaitingPayment->value],
            'orders_in_progress' => $byStatus[OrderStatus::InProgress->value]
                + $byStatus[OrderStatus::ClientSelected->value],
            'orders_awaiting_confirmation' => $byStatus[OrderStatus::WorkSubmitted->value],
            'orders_completed' => $byStatus[OrderStatus::Completed->value],
            'orders_cancelled' => $byStatus[OrderStatus::Cancelled->value],
            'offers_received_pending' => $offersReceivedPending,
            'reviews_pending' => $reviewsPending,
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param  Collection<int, AgentProfile>  $profiles
     * @return array<string, mixed>
     */
    private function providerBlock(User $user, Collection $profiles, ?AgentProfile $scoped): array
    {
        $profile = $scoped ?? $this->primaryFrom($profiles);

        $offerQuery = Offer::query()->where('agent_id', $user->id);
        if ($scoped !== null) {
            $offerQuery->where('agent_profile_id', $scoped->id);
        }

        $offerCounts = [
            OfferStatus::Pending->value => 0,
            OfferStatus::Accepted->value => 0,
            OfferStatus::Rejected->value => 0,
        ];

        $offerRows = (clone $offerQuery)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($offerRows as $status => $count) {
            $key = $status instanceof OfferStatus ? $status->value : (string) $status;
            if (array_key_exists($key, $offerCounts)) {
                $offerCounts[$key] = (int) $count;
            }
        }

        $dealQuery = Offer::query()
            ->where('offers.agent_id', $user->id)
            ->where('offers.status', OfferStatus::Accepted)
            ->join('orders', 'orders.id', '=', 'offers.order_id');

        if ($scoped !== null) {
            $dealQuery->where('offers.agent_profile_id', $scoped->id);
        }

        $dealRows = $dealQuery
            ->select('orders.status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('orders.status')
            ->pluck('aggregate', 'status');

        $dealByStatus = $this->emptyStatusMap();
        foreach ($dealRows as $status => $count) {
            $key = $status instanceof OrderStatus ? $status->value : (string) $status;
            if (array_key_exists($key, $dealByStatus)) {
                $dealByStatus[$key] = (int) $count;
            }
        }

        $reviewsPendingQuery = Offer::query()
            ->where('offers.agent_id', $user->id)
            ->where('offers.status', OfferStatus::Accepted)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Completed))
            ->whereDoesntHave('order.reviews', fn ($q) => $q
                ->where('direction', ReviewDirection::ProviderToClient));

        if ($scoped !== null) {
            $reviewsPendingQuery->where('offers.agent_profile_id', $scoped->id);
        }

        $reviewsPending = (int) $reviewsPendingQuery->count();

        if ($scoped !== null) {
            $portfolioItems = (int) $scoped->portfolio_items_count;
            $categories = (int) $scoped->categories_count;
        } else {
            $portfolioItems = (int) $profiles->sum('portfolio_items_count');
            $categories = (int) $profiles->sum('categories_count');
        }

        return [
            'has_profile' => true,
            'profile_status' => $profile?->status?->value,
            'offers_total' => array_sum($offerCounts),
            'offers_pending' => $offerCounts[OfferStatus::Pending->value],
            'offers_accepted' => $offerCounts[OfferStatus::Accepted->value],
            'offers_rejected' => $offerCounts[OfferStatus::Rejected->value],
            'deals_awaiting_payment' => $dealByStatus[OrderStatus::AwaitingPayment->value],
            'deals_in_progress' => $dealByStatus[OrderStatus::InProgress->value]
                + $dealByStatus[OrderStatus::ClientSelected->value],
            'deals_work_submitted' => $dealByStatus[OrderStatus::WorkSubmitted->value],
            'deals_completed' => $dealByStatus[OrderStatus::Completed->value],
            'reviews_pending' => $reviewsPending,
            'portfolio_items' => $portfolioItems,
            'categories' => $categories,
        ];
    }

    /**
     * Stub for agent/designer role holders who have not submitted KYC yet.
     *
     * @return array<string, mixed>
     */
    private function emptyProviderBlock(): array
    {
        return [
            'has_profile' => false,
            'profile_status' => null,
            'offers_total' => 0,
            'offers_pending' => 0,
            'offers_accepted' => 0,
            'offers_rejected' => 0,
            'deals_awaiting_payment' => 0,
            'deals_in_progress' => 0,
            'deals_work_submitted' => 0,
            'deals_completed' => 0,
            'reviews_pending' => 0,
            'portfolio_items' => 0,
            'categories' => 0,
        ];
    }

    /**
     * Live Orders "new" badge — open-for-offers orders newer than last_seen_at.
     *
     * Never opened the list: ignore backlog from before the account existed
     * (same idea as GlobalChatService::unread). Own orders never count.
     *
     * @return array{count: int, last_seen_at: string|null}
     */
    private function liveOrdersBlock(User $user): array
    {
        $open = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::openForOffers());
        $read = LiveOrderRead::query()->where('user_id', $user->id)->first();
        $after = $read?->last_seen_at ?? $user->created_at;

        $count = (int) Order::query()
            ->whereIn('status', $open)
            ->where('client_id', '!=', $user->id)
            ->where('created_at', '>', $after)
            ->count();

        return [
            'count' => $count,
            'last_seen_at' => $read?->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $client
     * @param  array<string, mixed>|null  $provider
     */
    private function actionRequired(
        Role $role,
        ?array $client,
        ?array $provider,
        int $unreadThreads,
    ): int {
        $total = $unreadThreads;

        if ($role === Role::Client && $client !== null) {
            // awaiting_payment is time-critical (72h auto-cancel).
            $total += (int) $client['orders_awaiting_payment']
                + (int) $client['orders_awaiting_confirmation']
                + (int) $client['offers_received_pending']
                + (int) $client['reviews_pending'];
        }

        if (in_array($role, [Role::Agent, Role::Designer], true) && $provider !== null) {
            $total += (int) $provider['reviews_pending'];
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStatusMap(): array
    {
        $map = [];
        foreach (OrderStatus::cases() as $status) {
            $map[$status->value] = 0;
        }

        return $map;
    }
}
