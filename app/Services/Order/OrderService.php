<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderProblemReason;
use App\Enums\OrderProblemState;
use App\Enums\OrderStatus;
use App\Jobs\RecalculateRating;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderProblemEvent;
use App\Models\Region;
use App\Models\User;
use App\Services\Assistant\CategoryClassifier;
use App\Services\Hashtag\HashtagService;
use App\Services\Payment\PaymentService;
use App\Services\Payout\PayoutService;
use App\Services\Telegram\AdminNotifier;
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
        private readonly AdminNotifier $admin,
        private readonly OrderActService $acts,
        private readonly OrderProblemService $problems,
        private readonly CategoryClassifier $classifier,
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
        $category = isset($data['category_id'])
            ? Category::find($data['category_id'])
            : null;

        // No category picked and not a directed order: let the assistant infer it
        // from the description so the order is not broadcast to every provider.
        // Null (disabled / timeout / unsure) keeps the broadcast behaviour.
        if ($category === null && empty($data['agent_profile_id'])) {
            $classifiedId = $this->classifier->classify((string) $data['description']);
            $category = $classifiedId !== null ? Category::find($classifiedId) : null;
        }

        $targetAgentId = $this->resolveTargetAgent($data['agent_profile_id'] ?? null, $category);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            // No category picked → name the order after its own description.
            $title = $category?->name_uz ?? $this->titleFromDescription($data['description']);
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
            'category_id' => $category?->id,
            // Freeze the category type so capacity stats survive category edits.
            'category_type' => $category?->type,
            'target_agent_id' => $targetAgentId,
            'title' => $title,
            'description' => $data['description'],
            'budget_max' => $data['budget'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'attachment_file_ids' => $data['attachment_file_ids'] ?? [],
            'show_files_in_showcase' => $data['show_files_in_showcase'] ?? true,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
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
    private function resolveTargetAgent(?int $agentProfileId, ?Category $category): ?int
    {
        if ($agentProfileId === null) {
            return null;
        }

        /** @var AgentProfile $profile */
        $profile = AgentProfile::where('status', AgentProfileStatus::Approved)->findOrFail($agentProfileId);

        // Without a category there is nothing to check the agency against.
        if ($category !== null && ! $profile->categories()->where('categories.id', $category->id)->exists()) {
            throw ValidationException::withMessages([
                'agent_profile_id' => ['This agency does not serve the selected category.'],
            ]);
        }

        return $profile->user_id;
    }

    /** First words of the request, used as the title when no category was picked. */
    private function titleFromDescription(string $description): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $description) ?? '');

        if ($text === '') {
            return 'Buyurtma';
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57).'…' : $text;
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
     * ops team is signalled to step in. A correction deadline starts (or
     * restarts, on a repeat dispute): if the order has not reached
     * `completed` by then, the daily sweep flags it as a problem order
     * ({@see OrderProblemService::flagFromDispute()}).
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
            'correction_deadline_at' => now()->addDays(
                (int) config('orders.quality_correction_window_days', 3),
            ),
            // A fresh dispute cycle gets its own approaching-deadline nudge.
            'correction_reminder_sent_at' => null,
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
     * The client reports that the winning agent took the advance but never
     * reported starting the work — flags the order into the problem-orders
     * admin queue immediately (no waiting for a sweep, unlike a quality
     * dispute: there is nothing to auto-resolve here).
     */
    public function reportNoStart(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        if ($order->problem_state !== OrderProblemState::None) {
            throw ValidationException::withMessages([
                'order' => ['This order already has an open or resolved problem report.'],
            ]);
        }

        if ($order->status !== OrderStatus::InProgress || $order->payment_state !== OrderPaymentState::Paid) {
            throw ValidationException::withMessages([
                'order' => ['This report is only available for an active, paid deal.'],
            ]);
        }

        if ($order->work_submitted_at !== null) {
            throw ValidationException::withMessages([
                'order' => ['The agent has already submitted the work for this order.'],
            ]);
        }

        if (! $order->canReportNoStart()) {
            throw ValidationException::withMessages([
                'order' => ['The minimum waiting period has not passed yet.'],
            ]);
        }

        DB::transaction(function () use ($client, $order): void {
            $order->update([
                'problem_state' => OrderProblemState::Flagged,
                'problem_reason' => OrderProblemReason::AgentNoStart,
                'problem_flagged_at' => now(),
            ]);

            $this->problems->logEvent($order, $client, 'client', OrderProblemEvent::FLAGGED, [
                'reason' => OrderProblemReason::AgentNoStart->value,
            ]);
        });

        try {
            $this->admin->orderProblemFlagged($order->fresh(), OrderProblemReason::AgentNoStart);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $this->notifier->notifyReportedNoStart($order->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order->fresh());
    }

    /**
     * The client cancels their own order:
     *  - while still open for offers (`new` / `offers_sent`);
     *  - while awaiting an unpaid checkout (legacy `awaiting_payment`);
     *  - on an **active but unpaid** deal — no money has moved;
     *  - on an **active paid** deal only inside the cooling-off window
     *    ({@see Order::clientCancelDeadline()}), which refunds the payment.
     *
     * Once the work is delivered, the window has closed, or an agent payout was
     * already released, only support can cancel.
     */
    public function cancelByClient(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

        if ($order->status === OrderStatus::InProgress) {
            return $this->cancelActiveDeal($client, $order);
        }

        if ($order->status === OrderStatus::AwaitingPayment) {
            $acceptedAgent = $order->offers()
                ->where('status', OfferStatus::Accepted)
                ->with('agent')
                ->first()
                ?->agent;

            $this->payments->cancelAwaitingPayment($order);

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
     * Cancel an already-running deal. Unpaid: open invoices are retired and the
     * order closes. Paid: the payment is refunded first (gateway revert, or a
     * manual return flagged to ops for cash / bank transfers), which is what
     * closes the order.
     */
    private function cancelActiveDeal(User $client, Order $order): Order
    {
        if (! $order->isCancellableByClient()) {
            throw ValidationException::withMessages([
                'order' => [$order->payment_state === OrderPaymentState::Paid
                    ? 'The cancellation window has closed — contact support.'
                    : 'This order can no longer be cancelled.'],
            ]);
        }

        $agent = $order->offers()
            ->where('status', OfferStatus::Accepted)
            ->with('agent')
            ->first()
            ?->agent;

        if ($order->payment_state === OrderPaymentState::Paid) {
            // Refunding settles the money and cancels the order (see
            // PaymentService::onOrderRefunded).
            $this->payments->refundForClientCancel($order, $client);
        } else {
            $this->payments->voidOpenIntents($order);

            $order->update(['status' => OrderStatus::Cancelled]);
        }

        RecalculateRating::dispatch($client->id);

        try {
            $this->notifier->notifyOrderCancelled($order->fresh(), array_filter([$agent]));
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

        // Queue the agent's final payout — the remainder after the advance.
        // Payouts follow settled money: an order that still owes (e.g. an
        // amendment raised the price) holds its final tranche.
        $final = $this->payouts->planFinal($order);

        $outstanding = $order->fresh()->outstandingTiyin();

        if ($final === null && $outstanding > 0) {
            try {
                $this->admin->finalPayoutHeld($order, $outstanding);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // The order still has an open problem report — the payout gate keeps
        // the money in, but flag this for ops so it isn't lost in the normal
        // completion flow.
        if ($order->problem_state === OrderProblemState::Flagged) {
            $this->problems->logEvent($order, null, 'system', OrderProblemEvent::COMPLETED_WHILE_FLAGGED);

            try {
                $this->admin->orderCompletedWhileFlagged($order);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Close the books: the act of completed work and the commission act.
        // A failure here must not roll the completion back — they are
        // regenerated on demand.
        try {
            $this->acts->generateForOrder($order->fresh());
        } catch (\Throwable $e) {
            report($e);
        }

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
