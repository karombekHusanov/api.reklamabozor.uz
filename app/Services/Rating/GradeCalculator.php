<?php

namespace App\Services\Rating;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Enums\Role;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calculates the Grade (0–100) from platform KPIs, per RATING_SYSTEM.md §4.
 * 90-day rolling window. Seller = stub grade 50 (no sales domain yet).
 */
class GradeCalculator
{
    public function compute(int $userId, Role $role, float $stars, ?int $agentProfileId = null): int
    {
        return match ($role) {
            Role::Client => $this->clientGrade($userId, $stars),
            Role::Agent => $this->agentGrade($userId, $stars, $agentProfileId),
            Role::Designer => $this->designerGrade($userId, $stars, $agentProfileId),
            Role::Seller => 50,
            default => 50,
        };
    }

    /**
     * Grade listing boost — RATING_SYSTEM.md §4 end.
     * boost = round((G - 50) / 10), clamped to [-5, +5].
     */
    public static function listingBoost(int $grade): int
    {
        return (int) max(-5, min(5, round(($grade - 50) / 10)));
    }

    // ── Client Grade ────────────────────────────────────────────────

    private function clientGrade(int $userId, float $stars): int
    {
        $since = $this->windowStart();

        $completed = Order::where('client_id', $userId)
            ->where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', $since)
            ->count();

        $gmv = $this->clientGmv($userId, $since);

        $reviewsGiven = Review::where('reviewer_id', $userId)
            ->where('direction', ReviewDirection::ClientToProvider)
            ->where('status', ReviewStatus::Approved)
            ->where('created_at', '>=', $since)
            ->count();

        $c1 = $this->clip($completed / 10) * 25;
        $c2 = $this->clip($gmv / 50_000_000) * 25;
        $c3 = $this->clip($reviewsGiven / 10) * 15;
        $c4 = (($stars - 1) / 4) * 20;
        $c5 = $this->clientPenalty($userId, $since);

        return $this->clampGrade($c1 + $c2 + $c3 + $c4 + $c5);
    }

    /**
     * C5 penalty — max 15, deductions: cancel −3, dispute −5, payment timeout −4.
     */
    private function clientPenalty(int $userId, Carbon $since): float
    {
        $penalty = 0;

        // Client-initiated cancels (early stage)
        $earlyCancels = Order::where('client_id', $userId)
            ->where('status', OrderStatus::Cancelled)
            ->where('updated_at', '>=', $since)
            ->whereNull('awaiting_payment_at')
            ->count();
        $penalty += $earlyCancels * 3;

        // Payment timeout cancels: cancelled + had awaiting_payment_at + no successful payment
        $timeoutCancels = Order::where('client_id', $userId)
            ->where('status', OrderStatus::Cancelled)
            ->whereNotNull('awaiting_payment_at')
            ->where('updated_at', '>=', $since)
            ->whereDoesntHave('payments', fn ($q) => $q->where('status', PaymentStatus::Success))
            ->count();
        $penalty += $timeoutCancels * 4;

        // Disputes opened by client
        $disputes = Order::where('client_id', $userId)
            ->whereNotNull('disputed_at')
            ->where('disputed_at', '>=', $since)
            ->count();
        $penalty += $disputes * 5;

        return max(0, 15 - $penalty);
    }

    /**
     * Client GMV = paid payments on their orders, in som.
     */
    private function clientGmv(int $userId, Carbon $since): float
    {
        $orderIds = Order::where('client_id', $userId)
            ->where('completed_at', '>=', $since)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return 0;
        }

        $paidAmount = Payment::where('payable_type', Order::class)
            ->whereIn('payable_id', $orderIds)
            ->where('status', PaymentStatus::Success)
            ->sum('amount');

        if ($paidAmount > 0) {
            return $paidAmount / 100;
        }

        // Gateway off: sum accepted offer prices
        return (float) Offer::whereIn('order_id', $orderIds)
            ->where('status', OfferStatus::Accepted)
            ->sum('price');
    }

    // ── Agent Grade ────────────────────────────────────────────────

    private function agentGrade(int $userId, float $stars, ?int $agentProfileId): int
    {
        $since = $this->windowStart();

        $completedQuery = $this->providerCompletedQuery($userId, $agentProfileId, $since);
        $completed = $completedQuery->count();

        $gmv = $this->providerGmv($userId, $agentProfileId, $since);

        $reviewsReceived = $this->reviewsReceived($userId, $agentProfileId, $since);

        $dealsEntered = $this->dealsEntered($userId, $agentProfileId, $since);

        $a1 = $this->clip($completed / 20) * 20;
        $a2 = $this->clip($gmv / 100_000_000) * 20;
        $a3 = $this->clip($reviewsReceived / 20) * 10;
        $a4 = (($stars - 1) / 4) * 25;
        $a5 = ($completed / max($dealsEntered, 1)) * 15;
        $a6 = $this->agentPenalty($userId, $agentProfileId, $since);

        return $this->clampGrade($a1 + $a2 + $a3 + $a4 + $a5 + $a6);
    }

    /**
     * A6 penalty — max 10, deductions: dispute −4, admin refund −5.
     */
    private function agentPenalty(int $userId, ?int $agentProfileId, Carbon $since): float
    {
        $penalty = 0;

        $orderIds = $this->providerOrderIds($userId, $agentProfileId, $since);

        if ($orderIds->isNotEmpty()) {
            $disputes = Order::whereIn('id', $orderIds)
                ->whereNotNull('disputed_at')
                ->where('disputed_at', '>=', $since)
                ->count();
            $penalty += $disputes * 4;

            $refunds = Payment::where('payable_type', Order::class)
                ->whereIn('payable_id', $orderIds)
                ->where('status', PaymentStatus::Revert)
                ->whereNotNull('refunded_at')
                ->where('refunded_at', '>=', $since)
                ->count();
            $penalty += $refunds * 5;
        }

        return max(0, 10 - $penalty);
    }

    // ── Designer Grade ─────────────────────────────────────────────

    private function designerGrade(int $userId, float $stars, ?int $agentProfileId): int
    {
        $since = $this->windowStart();

        $completedQuery = $this->providerCompletedQuery($userId, $agentProfileId, $since);
        $completed = $completedQuery->count();

        $gmv = $this->providerGmv($userId, $agentProfileId, $since);

        $reviewsReceived = $this->reviewsReceived($userId, $agentProfileId, $since);

        $dealsEntered = $this->dealsEntered($userId, $agentProfileId, $since);

        // D6: on_time — no concrete deadline date → full 10 points
        $d6 = 10.0;

        $d1 = $this->clip($completed / 20) * 20;
        $d2 = $this->clip($gmv / 50_000_000) * 15;
        $d3 = $this->clip($reviewsReceived / 20) * 10;
        $d4 = (($stars - 1) / 4) * 25;
        $d5 = ($completed / max($dealsEntered, 1)) * 15;
        $d7 = $this->designerPenalty($userId, $agentProfileId, $since);

        return $this->clampGrade($d1 + $d2 + $d3 + $d4 + $d5 + $d6 + $d7);
    }

    /**
     * D7 penalty — max 5, deductions: dispute −3.
     */
    private function designerPenalty(int $userId, ?int $agentProfileId, Carbon $since): float
    {
        $penalty = 0;
        $orderIds = $this->providerOrderIds($userId, $agentProfileId, $since);

        if ($orderIds->isNotEmpty()) {
            $disputes = Order::whereIn('id', $orderIds)
                ->whereNotNull('disputed_at')
                ->where('disputed_at', '>=', $since)
                ->count();
            $penalty += $disputes * 3;
        }

        return max(0, 5 - $penalty);
    }

    // ── Shared helpers ─────────────────────────────────────────────

    /**
     * @return Builder<Order>
     */
    private function providerCompletedQuery(int $userId, ?int $agentProfileId, Carbon $since)
    {
        return Order::where('status', OrderStatus::Completed)
            ->where('completed_at', '>=', $since)
            ->whereHas('offers', function ($q) use ($userId, $agentProfileId) {
                $q->where('status', OfferStatus::Accepted)
                    ->where('agent_id', $userId);
                if ($agentProfileId !== null) {
                    $q->where('agent_profile_id', $agentProfileId);
                }
            });
    }

    /**
     * Provider GMV: payments success (som) or accepted offer price (gateway off).
     */
    private function providerGmv(int $userId, ?int $agentProfileId, Carbon $since): float
    {
        $orderIds = $this->providerCompletedOrderIds($userId, $agentProfileId, $since);

        if ($orderIds->isEmpty()) {
            return 0;
        }

        $paidAmount = Payment::where('payable_type', Order::class)
            ->whereIn('payable_id', $orderIds)
            ->where('status', PaymentStatus::Success)
            ->sum('amount');

        if ($paidAmount > 0) {
            return $paidAmount / 100;
        }

        return (float) Offer::whereIn('order_id', $orderIds)
            ->where('status', OfferStatus::Accepted)
            ->where('agent_id', $userId)
            ->sum('price');
    }

    /**
     * Number of approved reviews received by this provider.
     */
    private function reviewsReceived(int $userId, ?int $agentProfileId, Carbon $since): int
    {
        $query = Review::where('reviewee_id', $userId)
            ->where('direction', ReviewDirection::ClientToProvider)
            ->where('status', ReviewStatus::Approved)
            ->where('created_at', '>=', $since);

        if ($agentProfileId !== null) {
            $query->where('agent_profile_id', $agentProfileId);
        }

        return $query->count();
    }

    /**
     * N_deals: orders that entered in_progress (paid / activated) in the window.
     */
    private function dealsEntered(int $userId, ?int $agentProfileId, Carbon $since): int
    {
        return Order::whereIn('status', [
            OrderStatus::InProgress,
            OrderStatus::WorkSubmitted,
            OrderStatus::Completed,
            OrderStatus::Cancelled,
        ])
            ->where('updated_at', '>=', $since)
            ->whereHas('offers', function ($q) use ($userId, $agentProfileId) {
                $q->where('status', OfferStatus::Accepted)
                    ->where('agent_id', $userId);
                if ($agentProfileId !== null) {
                    $q->where('agent_profile_id', $agentProfileId);
                }
            })
            ->count();
    }

    /**
     * @return Collection<int, int>
     */
    private function providerOrderIds(int $userId, ?int $agentProfileId, Carbon $since)
    {
        return Offer::where('agent_id', $userId)
            ->where('status', OfferStatus::Accepted)
            ->when($agentProfileId, fn ($q) => $q->where('agent_profile_id', $agentProfileId))
            ->whereHas('order', fn ($q) => $q->where('updated_at', '>=', $since))
            ->pluck('order_id');
    }

    /**
     * @return Collection<int, int>
     */
    private function providerCompletedOrderIds(int $userId, ?int $agentProfileId, Carbon $since)
    {
        return Offer::where('agent_id', $userId)
            ->where('status', OfferStatus::Accepted)
            ->when($agentProfileId, fn ($q) => $q->where('agent_profile_id', $agentProfileId))
            ->whereHas('order', fn ($q) => $q
                ->where('status', OrderStatus::Completed)
                ->where('completed_at', '>=', $since))
            ->pluck('order_id');
    }

    private function windowStart(): Carbon
    {
        return now()->subDays(90);
    }

    private function clip(float $value): float
    {
        return min(max($value, 0), 1);
    }

    private function clampGrade(float $raw): int
    {
        return (int) max(0, min(100, round($raw)));
    }
}
