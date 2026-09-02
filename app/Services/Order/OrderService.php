<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Jobs\RecalculateRating;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\Region;
use App\Models\User;
use App\Services\Hashtag\HashtagService;
use App\Services\Payment\PaymentService;
use App\Services\Payout\PayoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly PayoutService $payouts,
        private readonly PaymentService $payments,
        private readonly HashtagService $hashtags,
    ) {}

    /**
     * Place a B2C order. Title is the client project name (falls back to the
     * category label). A normal order is broadcast to every approved provider
     * serving that category; a directed order (agent_profile_id) reaches only
     * the chosen agency.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $client, array $data): Order
    {
        /** @var Category $category */
        $category = Category::findOrFail($data['category_id']);

        $targetAgentId = $this->resolveTargetAgent($data['agent_profile_id'] ?? null, $category);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $category->name_uz;
        }

        $regionId = isset($data['region_id']) ? (int) $data['region_id'] : null;
        $districtId = isset($data['district_id']) ? (int) $data['district_id'] : null;

        $locationLabel = isset($data['location_label'])
            ? (trim((string) $data['location_label']) ?: null)
            : null;

        if ($locationLabel === null && $regionId !== null) {
            $locationLabel = $this->locationLabelFromRegion($regionId, $districtId);
        }

        /** @var Order $order */
        $order = $client->orders()->create([
            'category_id' => $category->id,
            // Freeze the category type so capacity stats survive category edits.
            'category_type' => $category->type,
            'target_agent_id' => $targetAgentId,
            'title' => $title,
            'description' => $data['description'],
            'deadline' => $data['deadline'] ?? null,
            'attachment_file_ids' => $data['attachment_file_ids'] ?? [],
            'show_files_in_showcase' => $data['show_files_in_showcase'] ?? true,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'location_label' => $locationLabel,
            'region_id' => $regionId,
            'district_id' => $districtId,
            'status' => OrderStatus::New,
        ]);

        $this->hashtags->syncForOrder($order, $data['hashtags'] ?? []);

        try {
            $this->notifier->notifyNewOrder($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order);
    }

    /**
     * Resolve an optional directed-order target (an agency's public profile id)
     * to the agent's user id. The agency must be approved and actually serve the
     * chosen category — otherwise it could never see or bid on the order.
     */
    private function resolveTargetAgent(?int $agentProfileId, Category $category): ?int
    {
        if ($agentProfileId === null) {
            return null;
        }

        /** @var AgentProfile $profile */
        $profile = AgentProfile::where('status', AgentProfileStatus::Approved)->findOrFail($agentProfileId);

        if (! $profile->categories()->where('categories.id', $category->id)->exists()) {
            throw ValidationException::withMessages([
                'agent_profile_id' => ['This agency does not serve the selected category.'],
            ]);
        }

        return $profile->user_id;
    }

    /**
     * @return Collection<int, Order>
     */
    public function listForClient(User $client): Collection
    {
        // Cancelled orders stay in the DB / admin panel only — clients never
        // see them in their request list again.
        $orders = $client->orders()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->with(['category', 'region', 'district', 'targetAgent.profile'])
            ->withCount(['offers', 'views'])
            ->latest()
            ->get();

        Order::hydrateAttachmentFiles($orders);

        return $orders;
    }

    public function findForClient(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);
        abort_if($order->status === OrderStatus::Cancelled, 404);

        return $this->withClientRelations($order)->loadCount(['offers', 'views']);
    }

    /**
     * The winning agent marks the work as delivered — the order waits for the
     * client's confirmation (or the 3-day auto-complete).
     */
    public function submitWork(User $agent, Order $order): Order
    {
        $isWinner = $order->acceptedOffer()->where('agent_id', $agent->id)->exists();

        abort_unless($isWinner, 404);

        if ($order->status !== OrderStatus::InProgress) {
            throw ValidationException::withMessages([
                'order' => ['Only an order in progress can be submitted for review.'],
            ]);
        }

        $order->update([
            'status' => OrderStatus::WorkSubmitted,
            'work_submitted_at' => now(),
            'completion_reminder_sent_at' => null,
        ]);

        try {
            $this->notifier->notifyWorkSubmitted($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order);
    }

    /**
     * The client accepts the delivered work — the deal is done.
     */
    public function confirmCompletion(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        $this->assertAwaitingConfirmation($order);

        $this->complete($order, auto: false);

        return $this->withClientRelations($order);
    }

    /**
     * The client rejects the delivered work — back to in_progress, and the
     * ops team is signalled to step in.
     */
    public function disputeCompletion(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        $this->assertAwaitingConfirmation($order);

        $order->update([
            'status' => OrderStatus::InProgress,
            'work_submitted_at' => null,
            'completion_reminder_sent_at' => null,
            'disputed_at' => now(),
        ]);

        $this->dispatchRatingRecompute($order);

        try {
            $this->notifier->notifyDisputeOpened($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order);
    }

    /**
     * The client cancels their own order — while still open for offers
     * (`new` / `offers_sent`) or while awaiting unpaid checkout
     * (`awaiting_payment`). Once paid / in progress, cancel is refused.
     */
    public function cancelByClient(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        if ($order->status === OrderStatus::AwaitingPayment) {
            $acceptedAgent = $order->offers()
                ->where('status', OfferStatus::Accepted)
                ->with('agent')
                ->first()
                ?->agent;

            $this->payments->cancelAwaitingPayment($order, 'client');

            try {
                $this->notifier->notifyAwaitingPaymentCancelled($order->fresh(), $acceptedAgent);
            } catch (\Throwable $e) {
                report($e);
            }

            return $this->withClientRelations($order->fresh());
        }

        if (! $order->status->isOpenForOffers()) {
            throw ValidationException::withMessages([
                'order' => ['This order can no longer be cancelled.'],
            ]);
        }

        $biddingAgents = $order->offers()
            ->where('status', OfferStatus::Pending)
            ->with('agent')
            ->get()
            ->pluck('agent')
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        DB::transaction(function () use ($order): void {
            $order->offers()
                ->where('status', OfferStatus::Pending)
                ->update(['status' => OfferStatus::Rejected]);

            $order->update(['status' => OrderStatus::Cancelled]);
        });

        RecalculateRating::dispatch($client->id);

        try {
            $this->notifier->notifyOrderCancelled($order->fresh(), $biddingAgents);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order->fresh());
    }

    /**
     * Shared completion transition, used by the client confirmation and the
     * scheduler's auto-complete.
     */
    public function complete(Order $order, bool $auto): void
    {
        $order->update([
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
            'auto_completed' => $auto,
        ]);

        // Queue the agent's final payout — the remaining escrow after the
        // advance (gateway flow only). A manager releases it later.
        $this->payouts->planFinal($order);

        $this->dispatchRatingRecompute($order);

        try {
            $this->notifier->notifyOrderCompleted($order, $auto);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Queue a rating recompute for both sides of a deal.
     */
    private function dispatchRatingRecompute(Order $order): void
    {
        RecalculateRating::dispatch($order->client_id);

        $agentId = $order->acceptedOffer()->value('agent_id');
        if ($agentId !== null) {
            RecalculateRating::dispatch($agentId);
        }
    }

    private function assertAwaitingConfirmation(Order $order): void
    {
        if ($order->status !== OrderStatus::WorkSubmitted) {
            throw ValidationException::withMessages([
                'order' => ['This order is not awaiting completion confirmation.'],
            ]);
        }
    }

    private function withClientRelations(Order $order): Order
    {
        $order->load(Order::CLIENT_RELATIONS);
        Order::hydrateAttachmentFiles($order);

        return $order;
    }

    /**
     * Display fallback when the client omits location_label but picked a region.
     */
    private function locationLabelFromRegion(int $regionId, ?int $districtId): ?string
    {
        $region = Region::query()->find($regionId);
        if ($region === null) {
            return null;
        }

        $parts = [$region->name_uz];

        if ($districtId !== null) {
            $district = Region::query()->find($districtId);
            if ($district !== null) {
                array_unshift($parts, $district->name_uz);
            }
        }

        return implode(', ', $parts);
    }
}
