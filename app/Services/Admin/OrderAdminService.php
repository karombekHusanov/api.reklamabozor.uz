<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class OrderAdminService
{
    /**
     * Eager loads for the admin order views.
     *
     * @var list<string>
     */
    private const RELATIONS = ['category', 'client', 'targetAgent.agentProfile', 'offers.agent', 'offers.agentProfile', 'payments.payer'];

    /**
     * Allowed admin status transitions: target => acceptable source states.
     *
     * @var array<string, list<OrderStatus>>
     */
    private const TRANSITIONS = [
        // work_submitted → in_progress lets the admin resolve a dispute by
        // sending the work back for another round. awaiting_payment → in_progress
        // lets the admin force-activate a deal (e.g. paid offline) without waiting
        // on the gateway callback.
        OrderStatus::InProgress->value => [OrderStatus::ClientSelected, OrderStatus::AwaitingPayment, OrderStatus::WorkSubmitted],
        OrderStatus::Completed->value => [OrderStatus::InProgress, OrderStatus::WorkSubmitted],
        OrderStatus::Cancelled->value => [
            OrderStatus::New,
            OrderStatus::OffersSent,
            OrderStatus::ClientSelected,
            OrderStatus::AwaitingPayment,
            OrderStatus::InProgress,
            OrderStatus::WorkSubmitted,
        ],
    ];

    /**
     * @param  array{
     *     status?: string|null,
     *     attention?: string|null,
     *     search?: string|null,
     *     per_page?: int
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['category', 'client', 'targetAgent.agentProfile'])
            ->withCount('offers');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Ops-attention shortcuts: same definitions the dashboard counts use.
        if (($filters['attention'] ?? null) === 'stuck') {
            $query->stuck();
        } elseif (($filters['attention'] ?? null) === 'no_offers') {
            $query->withoutOffers();
        }

        if (! empty($filters['search'])) {
            $likeTerm = '%'.mb_strtolower($filters['search']).'%';

            $query->where(function ($builder) use ($likeTerm): void {
                $builder
                    ->whereRaw('LOWER(title) LIKE ?', [$likeTerm])
                    ->orWhereHas('client', function ($clientQuery) use ($likeTerm): void {
                        $clientQuery
                            ->whereRaw('LOWER(first_name) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(last_name) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(username) LIKE ?', [$likeTerm])
                            ->orWhereRaw('LOWER(phone) LIKE ?', [$likeTerm]);
                    });
            });
        }

        return $query
            ->latest()
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(Order $order): Order
    {
        $order->load(self::RELATIONS);
        Order::hydrateAttachmentFiles($order);

        return $order;
    }

    public function updateStatus(Order $order, OrderStatus $target): Order
    {
        $allowedSources = self::TRANSITIONS[$target->value] ?? [];

        if (! in_array($order->status, $allowedSources, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot change status from {$order->status->value} to {$target->value}."],
            ]);
        }

        $order->update(['status' => $target]);

        $order->load(self::RELATIONS);
        Order::hydrateAttachmentFiles($order);

        return $order;
    }
}
