<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Admin\ConfirmOfflinePaymentRequest;
use App\Http\Resources\AdminPaymentResource;
use App\Jobs\RecalculateRating;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Payment::query()->with(['payer', 'confirmedBy', 'invoiceFile'])->latest();

        if (($status = $request->query('status')) && PaymentStatus::tryFrom((string) $status)) {
            $query->where('status', $status);
        }

        if (($purpose = $request->query('purpose')) && PaymentPurpose::tryFrom((string) $purpose)) {
            $query->where('purpose', $purpose);
        }

        if (($method = $request->query('method')) && PaymentMethod::tryFrom((string) $method)) {
            $query->where('method', $method);
        }

        // Ops shortcut: cash / bank transfers waiting for a manager to confirm.
        if ($request->boolean('awaiting_confirmation')) {
            $query->whereIn('method', array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::offline()))
                ->whereIn('status', [PaymentStatus::Draft, PaymentStatus::Progress]);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('payment_uuid', 'like', "%{$search}%")
                    ->orWhere('gateway_uuid', 'like', "%{$search}%")
                    ->orWhere('billing_id', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => AdminPaymentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->success(new AdminPaymentResource($payment->load(['payer', 'confirmedBy', 'invoiceFile'])));
    }

    /**
     * Confirm money that arrived outside the gateway (cash desk or bank
     * statement): settles the payment, marks the order paid and plans the
     * agent's advance payout — exactly what a gateway webhook would do.
     */
    public function confirm(ConfirmOfflinePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->confirmOfflinePayment(
            $payment,
            $request->user(),
            $request->validated('reference'),
            $request->validated('note'),
        );

        return $this->success(
            new AdminPaymentResource($payment->load(['payer', 'confirmedBy', 'invoiceFile'])),
            'Payment confirmed',
        );
    }

    /**
     * Reject an offline payment request that never arrived.
     */
    public function reject(ConfirmOfflinePaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->rejectOfflinePayment(
            $payment,
            $request->user(),
            $request->validated('note'),
        );

        return $this->success(
            new AdminPaymentResource($payment->load(['payer', 'confirmedBy', 'invoiceFile'])),
            'Payment rejected',
        );
    }

    /**
     * Full Multicard refund (DELETE /payment/{uuid}). Cancels the related
     * order and voids unpaid payouts. Blocked if an agent payout is already paid.
     */
    public function refund(Request $request, Payment $payment): JsonResponse
    {
        if (! config('services.multicard.enabled')) {
            return $this->error('Payments are not enabled.', 422);
        }

        try {
            $payment = $this->payments->refundByAdmin($payment, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Refund failed at the payment gateway. Please try again.', 503);
        }

        if ($payment->payable instanceof Order) {
            $order = $payment->payable;
            RecalculateRating::dispatch($order->client_id);
            $agentId = $order->acceptedOffer()?->value('agent_id');
            if ($agentId) {
                RecalculateRating::dispatch($agentId);
            }
        }

        return $this->success(
            new AdminPaymentResource($payment->load('payer')),
            'Payment refunded',
        );
    }
}
