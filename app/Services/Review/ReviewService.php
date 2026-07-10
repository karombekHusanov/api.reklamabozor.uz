<?php

namespace App\Services\Review;

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(
        private readonly AdminNotifier $admin,
    ) {}

    /**
     * Client rates the winning agency on their completed order.
     *
     * @param  array{rating: int, comment?: string|null}  $data
     */
    public function submit(User $client, Order $order, array $data): Review
    {
        abort_unless($order->client_id === $client->id, 404);

        if ($order->status !== OrderStatus::Completed) {
            throw ValidationException::withMessages([
                'order' => ['Only completed orders can be reviewed.'],
            ]);
        }

        $agentId = $order->acceptedOffer()->value('agent_id');

        if ($agentId === null) {
            throw ValidationException::withMessages([
                'order' => ['This order has no winning agency to review.'],
            ]);
        }

        if ($order->review()->exists()) {
            throw ValidationException::withMessages([
                'order' => ['You have already reviewed this order.'],
            ]);
        }

        /** @var Review $review */
        $review = $order->review()->create([
            'client_id' => $client->id,
            'agent_id' => $agentId,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
            'status' => ReviewStatus::Pending,
        ]);

        $this->admin->reviewSubmitted($review);

        return $review;
    }
}
