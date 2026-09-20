<?php

namespace App\Services\Order;

use App\Enums\AmendmentStatus;
use App\Enums\OrderPaymentState;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\AmendmentEvent;
use App\Models\Contract;
use App\Models\ContractAcceptance;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\User;
use App\Services\Fiscal\FiscalService;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Http\Request;
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
        private readonly OrderContractService $contracts,
        private readonly AdminNotifier $admin,
        private readonly OrderNotifier $notifier,
        private readonly FiscalService $fiscal,
    ) {}

    /**
     * The addendum a party is about to propose, built from draft rows — the
     * text they read in the accept drawer before anything is stored.
     *
     * @param  array{items: array<int, array<string, mixed>>, deadline_days: int, reason?: string|null}  $data
     * @return array<string, mixed>
     */
    public function previewDocument(User $user, Order $order, array $data): array
    {
        $offer = $this->activeOffer($order);
        $role = $this->initiatorRole($user, $order, $offer);

        $before = $this->snapshotFromOffer($offer);
        $after = $this->snapshotFromInput($data['items'], $data['deadline_days']);

        return $this->pdf->draftDocument(
            order: $order,
            contract: $this->contractFor($order),
            before: $before,
            after: $after,
            initiatorRole: $role,
            reason: $data['reason'] ?? null,
            sequence: $this->nextSequence($order),
            requiresOperator: $this->requiresFormalDoc($before, $after),
        );
    }

    /**
     * The stored addendum's text (what the other party is asked to accept).
     *
     * @return array<string, mixed>
     */
    public function documentFor(OrderAmendment $amendment): array
    {
        return $this->pdf->documentFor($amendment);
    }

    /**
     * Propose an amendment. The initiator's own side counts as approved.
     *
     * @param  array{items: array<int, array<string, mixed>>, deadline_days: int, reason?: string|null}  $data
     */
    public function propose(User $user, Order $order, array $data, ?Request $request = null): OrderAmendment
    {
        $offer = $this->activeOffer($order);
        $role = $this->initiatorRole($user, $order, $offer);

        // One open amendment at a time keeps the approval state unambiguous.
        if ($order->amendments()->where('status', AmendmentStatus::Pending)->exists()) {
            throw ValidationException::withMessages([
                'amendment' => ['There is already a pending amendment on this order.'],
            ]);
        }

        // The client may only ask for changes early in the delivery time; the
        // agent, who is doing the work, may propose one at any point.
        if ($role === OrderAmendment::ROLE_CLIENT && ! $order->canProposeAmendment($user)) {
            throw ValidationException::withMessages([
                'amendment' => ['The window for requesting changes has closed — agree it with the provider in chat.'],
            ]);
        }

        $before = $this->snapshotFromOffer($offer);
        $after = $this->snapshotFromInput($data['items'], $data['deadline_days']);

        $extra = bcsub($after['total'], $before['total'], 2);
        $formal = $this->requiresFormalDoc($before, $after);
        $contract = $this->contractFor($order);
        $sequence = $this->nextSequence($order);

        $document = $this->pdf->draftDocument(
            order: $order,
            contract: $contract,
            before: $before,
            after: $after,
            initiatorRole: $role,
            reason: $data['reason'] ?? null,
            sequence: $sequence,
            requiresOperator: $formal,
        );

        $amendment = new OrderAmendment([
            'order_id' => $order->id,
            'offer_id' => $offer->id,
            'number' => $document['number'],
            'sequence' => $sequence,
            'contract_id' => $contract?->id,
            'initiator_id' => $user->id,
            'initiator_role' => $role,
            'client_window_ends_at' => $order->amendmentWindowEndsAt(),
            'expires_at' => now()->addHours(max(1, (int) config('orders.amendment_response_hours', 72))),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'reason' => $data['reason'] ?? null,
            'extra_amount' => $extra,
            'requires_operator' => $formal,
            'requires_formal_doc' => $formal,
            'document_hash' => $document['hash'],
            'status' => AmendmentStatus::Pending,
        ]);

        // The proposing side has, by definition, agreed to its own terms.
        $amendment->{$role === OrderAmendment::ROLE_CLIENT ? 'client_approved_at' : 'agent_approved_at'} = now();
        $amendment->save();

        // Proposing is an acceptance of the addendum text — log it click-wrap style.
        $this->recordAcceptance($amendment, $user, $role, $document, $request);

        $this->logEvent($amendment, AmendmentEvent::PROPOSED, $user, $role, [
            'number' => $amendment->number,
            'delta' => $extra,
            'requires_operator' => $formal,
            'window_ends_at' => $amendment->client_window_ends_at?->toIso8601String(),
        ], $request);

        $this->notify(fn () => $this->notifier->notifyAmendmentProposed($amendment->fresh()));

        if ($formal) {
            $this->notify(fn () => $this->admin->amendmentNeedsOperator($amendment->fresh()));
        }

        return $amendment;
    }

    /**
     * Record an approval from the client, agent, or (when flagged) operator.
     * Finalizes the amendment once every required party has approved.
     */
    public function approve(
        User $user,
        OrderAmendment $amendment,
        ?string $expectedHash = null,
        ?Request $request = null,
    ): OrderAmendment {
        $this->assertOpen($amendment);
        $role = $this->approverRole($user, $amendment);

        $document = $this->documentFor($amendment);

        // The drawer sends back the hash it displayed; refuse a stale text.
        if ($expectedHash !== null && ! hash_equals($document['hash'], $expectedHash)) {
            throw ValidationException::withMessages([
                'accept_contract' => ['The agreement changed — reopen it and accept the current version.'],
            ]);
        }

        $column = match ($role) {
            OrderAmendment::ROLE_CLIENT => 'client_approved_at',
            OrderAmendment::ROLE_AGENT => 'agent_approved_at',
            default => 'operator_approved_at',
        };

        if ($amendment->{$column} === null) {
            $amendment->{$column} = now();
            $amendment->save();

            $this->recordAcceptance($amendment, $user, $role, $document, $request);

            $this->logEvent(
                $amendment,
                $role === OrderAmendment::ROLE_OPERATOR ? AmendmentEvent::OPERATOR_APPROVED : AmendmentEvent::APPROVED,
                $user,
                $role,
                ['hash' => $document['hash']],
                $request,
            );
        }

        return $this->finalizeIfApproved($amendment);
    }

    public function reject(User $user, OrderAmendment $amendment, ?string $reason = null): OrderAmendment
    {
        $this->assertOpen($amendment);
        $role = $this->approverRole($user, $amendment);

        $amendment->update([
            'status' => AmendmentStatus::Rejected,
            'rejected_by' => $user->id,
            'rejection_reason' => $reason,
        ]);

        $this->logEvent($amendment, AmendmentEvent::REJECTED, $user, $role, ['reason' => $reason]);

        $this->notify(fn () => $this->notifier->notifyAmendmentDecided($amendment->fresh(), applied: false));

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

        $this->logEvent($amendment, AmendmentEvent::CANCELLED, $user, $amendment->initiator_role);

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
     * Once every required party has approved the addendum takes effect at once —
     * the work must not stall waiting for money. What it costs (or gives back)
     * lands on the order's ledger and is settled afterwards.
     */
    private function finalizeIfApproved(OrderAmendment $amendment): OrderAmendment
    {
        if (! $amendment->isFullyApproved()) {
            return $amendment;
        }

        $this->apply($amendment);
        $this->settleMoney($amendment->fresh());

        $this->notify(fn () => $this->notifier->notifyAmendmentDecided($amendment->fresh(), applied: true));

        return $amendment->refresh();
    }

    /**
     * Money side of an applied addendum, read off the order ledger:
     * a positive balance becomes a debt the client pays with the usual methods,
     * a negative one becomes a refund obligation for the operator.
     */
    private function settleMoney(OrderAmendment $amendment): void
    {
        $order = $amendment->order?->fresh();

        if ($order === null) {
            return;
        }

        // Offline deals (gateway disabled) collect nothing — nothing to settle.
        if ($order->payment_state === OrderPaymentState::NotRequired) {
            return;
        }

        $outstanding = $order->outstandingTiyin();

        if ($outstanding > 0) {
            $order->update([
                'payment_due_at' => $order->payment_due_at
                    ?? now()->addDays(max(1, (int) config('payments.payment_due_days', 3))),
            ]);
            $order->refresh()->recalculatePaymentState();

            $this->logEvent($amendment, AmendmentEvent::CHARGE_DUE, null, 'system', [
                'outstanding_som' => round($outstanding / 100, 2),
            ]);

            return;
        }

        if ($outstanding < 0) {
            $refund = round(abs($outstanding) / 100, 2);

            $amendment->update([
                'refund_amount' => $refund,
                'refund_state' => OrderAmendment::REFUND_DUE,
            ]);
            $order->refresh()->recalculatePaymentState();

            $this->logEvent($amendment, AmendmentEvent::REFUND_DUE, null, 'system', [
                'refund_som' => $refund,
            ]);

            try {
                $this->admin->amendmentRefundDue($amendment->fresh());
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }

        $order->refresh()->recalculatePaymentState();
    }

    /**
     * Operator hands back the money a price-lowering addendum created (no
     * gateway path exists for a partial refund) and records how it was returned.
     *
     * @param  array{amount?: float|int|string|null, method: string, reference?: string|null, note?: string|null}  $data
     */
    public function settleRefund(OrderAmendment $amendment, User $admin, array $data): OrderAmendment
    {
        if (! $amendment->refundIsDue()) {
            throw ValidationException::withMessages([
                'amendment' => ['This amendment has no refund waiting to be paid.'],
            ]);
        }

        if (isset($data['amount']) && bccomp((string) $data['amount'], (string) $amendment->refund_amount, 2) !== 0) {
            throw ValidationException::withMessages([
                'amount' => ['Partial refunds are not supported — return the full amount.'],
            ]);
        }

        $amendment->update([
            'refund_state' => OrderAmendment::REFUND_REFUNDED,
            'refund_method' => $data['method'],
            'refund_reference' => $data['reference'] ?? null,
            'refund_note' => $data['note'] ?? null,
            'refunded_by' => $admin->id,
            'refunded_at' => now(),
        ]);

        $amendment->order?->fresh()->recalculatePaymentState();

        $this->logEvent($amendment, AmendmentEvent::REFUND_PAID, $admin, OrderAmendment::ROLE_OPERATOR, [
            'amount_som' => (float) $amendment->refund_amount,
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
        ]);

        return $amendment->refresh();
    }

    /**
     * The refund is not going to be paid out (client left it as credit, or it
     * was settled outside the platform) — recorded, never silently dropped.
     */
    public function waiveRefund(OrderAmendment $amendment, User $admin, ?string $note = null): OrderAmendment
    {
        if (! $amendment->refundIsDue()) {
            throw ValidationException::withMessages([
                'amendment' => ['This amendment has no refund waiting to be paid.'],
            ]);
        }

        $amendment->update([
            'refund_state' => OrderAmendment::REFUND_WAIVED,
            'refund_note' => $note,
            'refunded_by' => $admin->id,
            'refunded_at' => now(),
        ]);

        $this->logEvent($amendment, AmendmentEvent::REFUND_WAIVED, $admin, OrderAmendment::ROLE_OPERATOR, [
            'amount_som' => (float) $amendment->refund_amount,
            'note' => $note,
        ]);

        return $amendment->refresh();
    }

    /**
     * Close a proposal nobody answered in time (operator action; the sweep in
     * Faza 5 calls the same path).
     */
    public function expire(OrderAmendment $amendment, ?User $actor = null): OrderAmendment
    {
        if (! $amendment->status->isOpen()) {
            throw ValidationException::withMessages([
                'amendment' => ['This amendment is no longer open.'],
            ]);
        }

        $amendment->update(['status' => AmendmentStatus::Expired]);

        $this->logEvent(
            $amendment,
            AmendmentEvent::EXPIRED,
            $actor,
            $actor !== null ? OrderAmendment::ROLE_OPERATOR : 'system',
            ['expires_at' => $amendment->expires_at?->toIso8601String()],
        );

        $this->notify(fn () => $this->notifier->notifyAmendmentExpired($amendment->fresh()));

        return $amendment->refresh();
    }

    /**
     * Nudge the party that has not answered yet (sweep; once per proposal).
     */
    public function remind(OrderAmendment $amendment): void
    {
        if (! $amendment->status->isOpen() || $amendment->awaitingParty() === null) {
            return;
        }

        $this->notify(fn () => $this->notifier->notifyAmendmentReminder($amendment));

        $amendment->update(['reminder_sent_at' => now()]);
    }

    /** Notifications never break the flow they report about. */
    private function notify(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logEvent(
        OrderAmendment $amendment,
        string $type,
        ?User $actor,
        string $role,
        array $payload = [],
        ?Request $request = null,
    ): void {
        AmendmentEvent::create([
            'amendment_id' => $amendment->id,
            'actor_id' => $actor?->id,
            'actor_role' => $role,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'ip_address' => $request?->ip(),
        ]);
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

            $fiscal = $this->fiscal->fieldsForOrder($amendment->order);

            $offer->items()->delete();
            foreach (array_values($after['items']) as $index => $item) {
                $offer->items()->create([
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'sort_order' => $index,
                    ...$fiscal,
                ]);
            }

            $offer->load('items');
            $offer->recomputeTotal();
            $offer->update(['deadline_days' => $after['deadline_days']]);

            $document = $this->documentFor($amendment);
            $rendered = $this->pdf->render($document, $this->acceptanceLog($amendment));

            $amendment->update([
                'status' => AmendmentStatus::Applied,
                'applied_at' => now(),
                'pdf_file_id' => $rendered['file']->id,
                'hash' => $rendered['hash'],
                'document_hash' => $document['hash'],
            ]);

            $this->logEvent($amendment, AmendmentEvent::DOCUMENT_GENERATED, null, 'system', [
                'hash' => $rendered['hash'],
            ]);
            $this->logEvent($amendment, AmendmentEvent::APPLIED, null, 'system', [
                'total' => $after['total'] ?? null,
                'deadline_days' => $after['deadline_days'] ?? null,
            ]);
        });
    }

    /**
     * The order's service contract — generated on demand (idempotent) so an
     * addendum always has a parent document to reference.
     */
    private function contractFor(Order $order): ?Contract
    {
        $contract = $order->contract()->first();

        if ($contract !== null) {
            return $contract;
        }

        try {
            return $this->contracts->generateForOrder($order);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Next addendum number within the order (DS1, DS2 …). */
    private function nextSequence(Order $order): int
    {
        return (int) $order->amendments()->max('sequence') + 1;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function recordAcceptance(
        OrderAmendment $amendment,
        User $user,
        string $role,
        array $document,
        ?Request $request,
    ): void {
        ContractAcceptance::create([
            'order_id' => $amendment->order_id,
            'offer_id' => $amendment->offer_id,
            'amendment_id' => $amendment->id,
            'user_id' => $user->id,
            'party' => $role === OrderAmendment::ROLE_CLIENT
                ? ContractAcceptance::PARTY_CLIENT
                : ($role === OrderAmendment::ROLE_AGENT
                    ? ContractAcceptance::PARTY_AGENT
                    : 'operator'),
            'version' => $document['version'] ?? AmendmentContractService::VERSION,
            'terms_version' => config('legal.terms_version'),
            'total' => $document['after']['total'] ?? 0,
            'snapshot' => $document,
            'hash' => $document['hash'],
            'accepted_at' => now(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /**
     * Printable "accepted by" lines for the addendum PDF.
     *
     * @return list<array{label: string, name: string, accepted_at: string}>
     */
    private function acceptanceLog(OrderAmendment $amendment): array
    {
        $labels = [
            ContractAcceptance::PARTY_AGENT => 'Ijrochi',
            ContractAcceptance::PARTY_CLIENT => 'Buyurtmachi',
            'operator' => 'Operator',
        ];

        return $amendment->acceptances()->with('user')->get()
            ->groupBy('party')
            ->map(fn ($rows) => $rows->first())
            ->map(function (ContractAcceptance $acceptance) use ($labels): array {
                $name = trim(($acceptance->user?->first_name ?? '').' '.($acceptance->user?->last_name ?? ''));

                return [
                    'label' => $labels[$acceptance->party] ?? $acceptance->party,
                    'name' => $name !== '' ? $name : '—',
                    'accepted_at' => $acceptance->accepted_at?->format('d.m.Y H:i') ?? '',
                ];
            })
            ->values()
            ->all();
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
