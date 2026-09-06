<?php

namespace App\Services\Order;

use App\Enums\AmendmentStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Additional agreement" (Qo'shimcha kelishuv) flow: either party proposes a
 * change to an active deal's pricelist and/or deadline; it takes effect only
 * once every required party approves and any extra charge is settled.
 */
class AmendmentService
{
    public function __construct(
        private readonly AmendmentContractService $pdf,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Propose an amendment. The initiator's own side counts as approved.
     *
     * @param  array{items: array<int, array<string, mixed>>, deadline_days: int, reason?: string|null}  $data
     */
    public function propose(User $user, Order $order, array $data): OrderAmendment
    {
        $offer = $this->activeOffer($order);
        $role = $this->initiatorRole($user, $order, $offer);

        // One open amendment at a time keeps the approval state unambiguous.
        if ($order->amendments()->where('status', AmendmentStatus::Pending)->exists()) {
            throw ValidationException::withMessages([
                'amendment' => ['There is already a pending amendment on this order.'],
            ]);
        }

        $before = $this->snapshotFromOffer($offer);
        $after = $this->snapshotFromInput($data['items'], $data['deadline_days']);

        $extra = bcsub($after['total'], $before['total'], 2);
        $formal = $this->requiresFormalDoc($before, $after);

        $amendment = new OrderAmendment([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'initiator_id' => $user->id,
            'initiator_role' => $role,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'reason' => $data['reason'] ?? null,
            'extra_amount' => $extra,
            'requires_operator' => $formal,
            'requires_formal_doc' => $formal,
            'status' => AmendmentStatus::Pending,
        ]);

        // The proposing side has, by definition, agreed to its own terms.
        $amendment->{$role === OrderAmendment::ROLE_CLIENT ? 'client_approved_at' : 'agent_approved_at'} = now();
        $amendment->save();

        return $amendment;
    }

    /**
     * Record an approval from the client, agent, or (when flagged) operator.
     * Finalizes the amendment once every required party has approved.
     */
    public function approve(User $user, OrderAmendment $amendment): OrderAmendment
    {
        $this->assertOpen($amendment);
        $role = $this->approverRole($user, $amendment);

        $column = match ($role) {
            OrderAmendment::ROLE_CLIENT => 'client_approved_at',
            OrderAmendment::ROLE_AGENT => 'agent_approved_at',
            default => 'operator_approved_at',
        };

        if ($amendment->{$column} === null) {
            $amendment->{$column} = now();
            $amendment->save();
        }

        return $this->finalizeIfApproved($amendment);
    }

    public function reject(User $user, OrderAmendment $amendment, ?string $reason = null): OrderAmendment
    {
        $this->assertOpen($amendment);
        $this->approverRole($user, $amendment); // authorization only

        $amendment->update([
            'status' => AmendmentStatus::Rejected,
            'rejected_by' => $user->id,
            'rejection_reason' => $reason,
        ]);

        return $amendment;
    }

    public function cancel(User $user, OrderAmendment $amendment): OrderAmendment
    {
        if ($amendment->initiator_id !== $user->id) {
            abort(403);
        }

        if ($amendment->status->isTerminal()) {
            throw ValidationException::withMessages([
                'amendment' => ['This amendment can no longer be cancelled.'],
            ]);
        }

        $amendment->update(['status' => AmendmentStatus::Cancelled]);

        return $amendment;
    }

    /**
     * Apply an amendment whose extra payment has just succeeded.
     */
    public function applyPaid(OrderAmendment $amendment): void
    {
        if ($amendment->status !== AmendmentStatus::Approved || $amendment->applied_at !== null) {
            return;
        }

        $this->apply($amendment);
    }

    // --- internals -------------------------------------------------------

    /**
     * Once every required party has approved: charge the extra (when the gateway
     * is on and money is owed) and hold, otherwise apply straight away.
     */
    private function finalizeIfApproved(OrderAmendment $amendment): OrderAmendment
    {
        if (! $amendment->isFullyApproved()) {
            return $amendment;
        }

        $gatewayOn = (bool) config('services.multicard.enabled');

        if ($amendment->hasExtraPayment() && $gatewayOn) {
            $amendment->update(['status' => AmendmentStatus::Approved]);
            $payment = $this->payments->startAmendmentPayment($amendment->fresh());
            $amendment->update(['payment_id' => $payment->id]);

            return $amendment->refresh();
        }

        $this->apply($amendment);

        return $amendment->refresh();
    }

    /**
     * Write the amendment into the deal: replace the offer's pricelist + deadline,
     * recompute the total, and store the immutable amendment PDF.
     */
    private function apply(OrderAmendment $amendment): void
    {
        DB::transaction(function () use ($amendment): void {
            /** @var Offer $offer */
            $offer = $amendment->offer()->firstOrFail();
            $after = $amendment->after_snapshot;

            $offer->items()->delete();
            foreach (array_values($after['items']) as $index => $item) {
                $offer->items()->create([
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'sort_order' => $index,
                ]);
            }

            $offer->load('items');
            $offer->recomputeTotal();
            $offer->update(['deadline_days' => $after['deadline_days']]);

            $rendered = $this->pdf->render($amendment);

            $amendment->update([
                'status' => AmendmentStatus::Applied,
                'applied_at' => now(),
                'pdf_file_id' => $rendered['file']->id,
                'hash' => $rendered['hash'],
            ]);
        });
    }

    private function activeOffer(Order $order): Offer
    {
        if ($order->status !== OrderStatus::InProgress) {
            throw ValidationException::withMessages([
                'order' => ['Amendments are only allowed on an active (in-progress) order.'],
            ]);
        }

        $offer = $order->acceptedOffer()->with('items')->first();

        if ($offer === null) {
            throw ValidationException::withMessages([
                'order' => ['This order has no accepted offer.'],
            ]);
        }

        return $offer;
    }

    private function initiatorRole(User $user, Order $order, Offer $offer): string
    {
        if ($user->id === $order->client_id) {
            return OrderAmendment::ROLE_CLIENT;
        }

        if ($user->id === $offer->agent_id) {
            return OrderAmendment::ROLE_AGENT;
        }

        abort(403);
    }

    /**
     * Which party this user approves as — or 403 if they are not a party.
     */
    private function approverRole(User $user, OrderAmendment $amendment): string
    {
        $order = $amendment->order;
        $offer = $amendment->offer;

        if ($user->id === $order?->client_id) {
            return OrderAmendment::ROLE_CLIENT;
        }

        if ($user->id === $offer?->agent_id) {
            return OrderAmendment::ROLE_AGENT;
        }

        if ($user->role === Role::Admin && $amendment->requires_operator) {
            return 'operator';
        }

        abort(403);
    }

    private function assertOpen(OrderAmendment $amendment): void
    {
        if (! $amendment->status->isOpen()) {
            throw ValidationException::withMessages([
                'amendment' => ['This amendment is no longer open for a decision.'],
            ]);
        }
    }

    /**
     * @return array{items: list<array<string, string>>, deadline_days: int|null, total: string}
     */
    private function snapshotFromOffer(Offer $offer): array
    {
        $items = $offer->relationLoaded('items') ? $offer->items : $offer->items()->get();

        $rows = $items->map(fn ($item) => [
            'name' => $item->name,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'line_total' => $item->lineTotal(),
        ])->values()->all();

        return [
            'items' => $rows,
            'deadline_days' => $offer->deadline_days !== null ? (int) $offer->deadline_days : null,
            'total' => $this->sumLines($rows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: list<array<string, string>>, deadline_days: int, total: string}
     */
    private function snapshotFromInput(array $items, int $deadlineDays): array
    {
        $rows = [];

        foreach (array_values($items) as $item) {
            $qty = (string) $item['quantity'];
            $unitPrice = (string) $item['unit_price'];

            $rows[] = [
                'name' => trim((string) $item['name']),
                'unit' => isset($item['unit']) && trim((string) $item['unit']) !== ''
                    ? trim((string) $item['unit'])
                    : 'dona',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => bcmul($qty, $unitPrice, 2),
            ];
        }

        return [
            'items' => $rows,
            'deadline_days' => $deadlineDays,
            'total' => $this->sumLines($rows),
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function sumLines(array $rows): string
    {
        return array_reduce(
            $rows,
            fn (string $carry, array $row): string => bcadd($carry, $row['line_total'], 2),
            '0',
        );
    }

    /**
     * A change needs a formal (offline) document when it moves the deadline or
     * introduces scope beyond the original pricelist (a brand-new line item).
     *
     * @param  array{items: list<array<string, string>>, deadline_days: int|null, total: string}  $before
     * @param  array{items: list<array<string, string>>, deadline_days: int|null, total: string}  $after
     */
    private function requiresFormalDoc(array $before, array $after): bool
    {
        if (($before['deadline_days'] ?? null) !== ($after['deadline_days'] ?? null)) {
            return true;
        }

        $beforeNames = array_map(
            fn (array $row) => mb_strtolower(trim($row['name'])),
            $before['items'],
        );

        foreach ($after['items'] as $row) {
            if (! in_array(mb_strtolower(trim($row['name'])), $beforeNames, true)) {
                return true;
            }
        }

        return false;
    }
}
