<?php

namespace App\Services\Order;

use App\Enums\AgentProfileStatus;
use App\Enums\OrderStatus;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Order;
use App\Models\User;
use App\Services\Payout\PayoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly PayoutService $payouts,
    ) {}

    /**
     * Place a B2C order. The title is derived from the category. A normal order
     * is broadcast to every approved provider serving that category; a directed
     * order (agent_profile_id) reaches only the chosen agency.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $client, array $data): Order
    {
        /** @var Category $category */
        $category = Category::findOrFail($data['category_id']);

        $targetAgentId = $this->resolveTargetAgent($data['agent_profile_id'] ?? null, $category);

        /** @var Order $order */
        $order = $client->orders()->create([
            'category_id' => $category->id,
            'target_agent_id' => $targetAgentId,
            'title' => $category->name_uz,
            'description' => $data['description'],
            'deadline' => $data['deadline'] ?? null,
            'attachment_file_ids' => $data['attachment_file_ids'],
            'status' => OrderStatus::New,
        ]);

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
        $orders = $client->orders()
            ->with(['category', 'targetAgent.agentProfile'])
            ->withCount(['offers', 'views'])
            ->latest()
            ->get();

        Order::hydrateAttachmentFiles($orders);

        return $orders;
    }

    public function findForClient(User $client, Order $order): Order
    {
        abort_unless($order->client_id === $client->id, 404);

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
        ]);

        try {
            $this->notifier->notifyDisputeOpened($order);
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->withClientRelations($order);
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

        try {
            $this->notifier->notifyOrderCompleted($order, $auto);
        } catch (\Throwable $e) {
            report($e);
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
}
