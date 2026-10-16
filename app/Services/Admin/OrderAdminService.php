<?php

namespace App\Services\Admin;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Order\OfferService;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use App\Services\Payout\PayoutService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderAdminService
{
    /**
     * Eager loads for the admin order views.
     *
     * @var list<string>
     */
    private const RELATIONS = ['category', 'region', 'district', 'hashtags', 'client', 'targetAgent.profile', 'offers.agent', 'offers.agentProfile', 'offers.contractAcceptances.user', 'payments.payer', 'contract.pdfFile', 'documents.pdfFile', 'amendments.contract', 'amendments.offer.agentProfile'];

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

    public function __construct(
        private readonly OrderService $orders,
        private readonly OfferService $offers,
        private readonly PaymentService $payments,
        private readonly PayoutService $payouts,
    ) {}

    /**
     * @param  array{
     *     status?: string|null,
     *     attention?: string|null,
     *     route?: string|null,
     *     search?: string|null,
     *     per_page?: int
     * }  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Order::query()
            ->with(['category', 'region', 'district', 'hashtags', 'client', 'targetAgent.profile', 'claimedAgent.profile'])
            ->withCount('offers');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['route'])) {
            $query->where('route', $filters['route']);
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

    /**
     * Admin-forced status change. Never a bare column write: each target runs
     * through the same domain transition the client / agent flows use, so the
     * money side (payouts, refunds, invoices), the acts and the ratings stay
     * consistent with the status (audit 2026-10-16 §1.3).
     *
     *  - completed → {@see OrderService::complete()} (final payout, acts, rating, notify);
     *  - cancelled → unpaid: open invoices voided + unpaid payouts cancelled;
     *    paid: the admin refund path ({@see PaymentService::refundByAdmin()}),
     *    which refuses when an agent payout was already released;
     *  - in_progress ← awaiting_payment / client_selected with an accepted
     *    offer: {@see OfferService::activateDeal()} (deal chat, activated_at);
     *    ← work_submitted: plain send-back for another round.
     */
    public function updateStatus(Order $order, OrderStatus $target, User $admin): Order
    {
        $this->assertTransition($order, $target);

        DB::transaction(function () use ($order, $target, $admin): void {
            // Re-check under a row lock: a concurrent client/agent transition
            // (accept, submit-work, cancel) must not interleave with ours.
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->assertTransition($locked, $target);

            match ($target) {
                OrderStatus::Completed => $this->orders->complete($locked, auto: false),
                OrderStatus::Cancelled => $this->cancel($locked, $admin),
                OrderStatus::InProgress => $this->activate($locked),
                default => throw ValidationException::withMessages([
                    'status' => ["Admins cannot move an order to {$target->value}."],
                ]),
            };
        });

        $order->refresh()->load(self::RELATIONS);
        Order::hydrateAttachmentFiles($order);

        return $order;
    }

    private function assertTransition(Order $order, OrderStatus $target): void
    {
        $allowedSources = self::TRANSITIONS[$target->value] ?? [];

        if (! in_array($order->status, $allowedSources, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot change status from {$order->status->value} to {$target->value}."],
            ]);
        }
    }

    private function cancel(Order $order, User $admin): void
    {
        if ($order->payment_state === OrderPaymentState::Paid) {
            $settled = $order->payments()
                ->where('purpose', PaymentPurpose::Order)
                ->where('status', PaymentStatus::Success)
                ->orderBy('id')
                ->get();

            if ($settled->isEmpty()) {
                throw ValidationException::withMessages([
                    'status' => ['This order is marked paid but has no settled payment to refund — review it in Payments first.'],
                ]);
            }

            // Refunding is what cancels a paid order: it reverts every settled
            // payment, voids unpaid payouts and refuses when money already
            // left the platform (a released payout) — ops must recover that
            // by hand before the order can be cancelled.
            $settled->each(fn (Payment $payment) => $this->payments->refundByAdmin($payment, $admin));

            return;
        }

        $this->payments->voidOpenIntents($order);
        $this->payouts->cancelUnpaidForOrder($order);

        $order->update(['status' => OrderStatus::Cancelled]);
    }

    private function activate(Order $order): void
    {
        // Dispute resolution: send the delivered work back for another round.
        if ($order->status === OrderStatus::WorkSubmitted) {
            $order->update([
                'status' => OrderStatus::InProgress,
                'work_submitted_at' => null,
                'completion_reminder_sent_at' => null,
            ]);

            return;
        }

        $offer = $order->offers()->where('status', OfferStatus::Accepted)->first();

        if ($offer !== null) {
            // Same path the legacy payment callback used: opens the deal chat,
            // freezes activated_at, notifies both sides, generates the contract.
            $offer->setRelation('order', $order);
            $this->offers->activateDeal($offer);

            return;
        }

        if ($order->status === OrderStatus::AwaitingPayment) {
            throw ValidationException::withMessages([
                'status' => ['This order has no accepted offer to activate a deal for.'],
            ]);
        }

        $order->update(['status' => OrderStatus::InProgress]);
    }
}
