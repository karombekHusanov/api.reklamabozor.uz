<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Enums\PaymentMethod;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Payment\StartOfflinePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Client chooses how to pay — cash at the platform's desk or a bank
     * transfer. Returns the pending payment with its invoice (hisob-faktura);
     * a manager confirms the money once it arrives (or Kapitalbank
     * auto-reconciliation matches it for a bank transfer).
     */
    public function payOffline(StartOfflinePaymentRequest $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payment = $this->payments->startOfflineOrderPayment(
            $order,
            PaymentMethod::from((string) $request->validated('method')),
            (int) ($request->validated('percent') ?? 100),
        );

        return $this->success(new PaymentResource($payment), 'Invoice ready');
    }

    /**
     * Every payment attempt on an order (cash, bank transfer) so the client
     * sees what is pending and what settled.
     */
    public function index(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payments = $order->payments()
            ->with('invoiceFile')
            ->latest()
            ->get();

        return $this->success(PaymentResource::collection($payments));
    }

    /**
     * Latest payment status for an order (used by the mini app to poll while
     * an offline payment awaits confirmation).
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->client_id === $request->user()->id, 404);

        $payment = $order->latestPayment;
        $payment?->loadMissing('invoiceFile');

        return $this->success(
            $payment ? new PaymentResource($payment) : null,
            'Payment status',
        );
    }
}
