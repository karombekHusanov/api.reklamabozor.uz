<?php

namespace App\Services\Review;

use App\Enums\OrderStatus;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use App\Services\Rating\RatingCriteria;
use App\Services\Rating\RatingService;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(
        private readonly AdminNotifier $admin,
        private readonly RatingService $ratings,
    ) {}

    /**
     * Client rates the winning provider on their completed order.
     *
     * @param  array{criteria: list<array{code: string, score: int}>, comment?: string|null}  $data
     */
    public function submitClientReview(User $client, Order $order, array $data): Review
    {
        abort_unless($order->client_id === $client->id, 404);
        $this->assertCompleted($order);

        $acceptedOffer = $order->acceptedOffer()->first(['agent_id', 'agent_profile_id']);
        if ($acceptedOffer === null) {
            throw ValidationException::withMessages([
                'order' => ['This order has no winning agency to review.'],
            ]);
        }

        $this->assertNoExistingReview($order, ReviewDirection::ClientToProvider);

        $providerRole = $this->resolveProviderRole($acceptedOffer->agent_profile_id);
        $scores = $this->extractScores($data['criteria']);
        $this->validateCriteria($providerRole, $scores);
        $dealScore = RatingCriteria::dealScore($providerRole, $scores);

        /** @var Review $review */
        $review = Review::create([
            'order_id' => $order->id,
            'direction' => ReviewDirection::ClientToProvider,
            'client_id' => $client->id,
            'agent_id' => $acceptedOffer->agent_id,
            'agent_profile_id' => $acceptedOffer->agent_profile_id,
            'reviewer_id' => $client->id,
            'reviewee_id' => $acceptedOffer->agent_id,
            'criteria' => $data['criteria'],
            'rating' => $dealScore,
            'comment' => $data['comment'] ?? null,
            'status' => ReviewStatus::Pending,
        ]);

        $this->admin->reviewSubmitted($review);

        return $review;
    }

    /**
     * Provider rates the client on their completed order.
     *
     * @param  array{criteria: list<array{code: string, score: int}>, comment?: string|null}  $data
     */
    public function submitProviderReview(User $provider, Order $order, array $data): Review
    {
        $acceptedOffer = $order->acceptedOffer()->where('agent_id', $provider->id)->first();
        if ($acceptedOffer === null) {
            abort(404);
        }

        $this->assertCompleted($order);
        $this->assertNoExistingReview($order, ReviewDirection::ProviderToClient);

        $scores = $this->extractScores($data['criteria']);
        $this->validateCriteria(Role::Client, $scores);
        $dealScore = RatingCriteria::dealScore(Role::Client, $scores);

        /** @var Review $review */
        $review = Review::create([
            'order_id' => $order->id,
            'direction' => ReviewDirection::ProviderToClient,
            'client_id' => $order->client_id,
            'agent_id' => $provider->id,
            'agent_profile_id' => $acceptedOffer->agent_profile_id,
            'reviewer_id' => $provider->id,
            'reviewee_id' => $order->client_id,
            'criteria' => $data['criteria'],
            'rating' => $dealScore,
            'comment' => $data['comment'] ?? null,
            'status' => ReviewStatus::Pending,
        ]);

        $this->admin->reviewSubmitted($review);

        return $review;
    }

    /**
     * Legacy wrapper — kept for backward compatibility.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(User $client, Order $order, array $data): Review
    {
        return $this->submitClientReview($client, $order, $data);
    }

    /**
     * Admin approves or rejects a review. Any terminal moderation
     * (approve or reject) recomputes the reviewee so cache stays fresh
     * when an already-approved review is later rejected.
     */
    public function moderate(Review $review, ReviewStatus $status): Review
    {
        $review->update(['status' => $status]);

        if (in_array($status, [ReviewStatus::Approved, ReviewStatus::Rejected], true)) {
            $this->ratings->recomputeForUser($review->reviewee_id);
        }

        return $review;
    }

    private function assertCompleted(Order $order): void
    {
        if ($order->status !== OrderStatus::Completed) {
            throw ValidationException::withMessages([
                'order' => ['Only completed orders can be reviewed.'],
            ]);
        }
    }

    private function assertNoExistingReview(Order $order, ReviewDirection $direction): void
    {
        if (Review::where('order_id', $order->id)->where('direction', $direction)->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A review has already been submitted for this direction.'],
            ]);
        }
    }

    /**
     * Resolve the provider role from the agent_profile's provider_type.
     */
    private function resolveProviderRole(?int $agentProfileId): Role
    {
        if ($agentProfileId === null) {
            return Role::Agent;
        }

        $providerType = AgentProfile::where('id', $agentProfileId)->value('provider_type');

        return match ($providerType?->value ?? 'agent') {
            'designer' => Role::Designer,
            default => Role::Agent,
        };
    }

    /**
     * @param  list<array{code: string, score: int}>  $criteria
     * @return array<string, int>
     */
    private function extractScores(array $criteria): array
    {
        $scores = [];
        foreach ($criteria as $item) {
            $scores[$item['code']] = $item['score'];
        }

        return $scores;
    }

    /**
     * @param  array<string, int>  $scores
     */
    private function validateCriteria(Role $role, array $scores): void
    {
        $requiredCodes = RatingCriteria::codesForRole($role);

        $providedCodes = array_keys($scores);
        sort($requiredCodes);
        sort($providedCodes);

        if ($requiredCodes !== $providedCodes) {
            throw ValidationException::withMessages([
                'criteria' => ['Criteria codes must exactly match the required set for this role: '.implode(', ', $requiredCodes)],
            ]);
        }

        foreach ($scores as $code => $score) {
            if ($score < 1 || $score > 5) {
                throw ValidationException::withMessages([
                    'criteria' => ["Score for '{$code}' must be between 1 and 5."],
                ]);
            }
        }
    }
}
