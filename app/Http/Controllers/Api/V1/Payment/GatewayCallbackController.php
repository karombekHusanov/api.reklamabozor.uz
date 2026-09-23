<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Contracts\PaymentGateway;
use App\Enums\GatewayPaymentStatus;
use App\Http\Controllers\ApiController;
use App\Models\GatewayPayment;
use App\Services\Pass\GatewayPaymentService;
use App\Services\Payment\Gateway\GatewayEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Public endpoints hit by the payment provider (and, in dev, the fake checkout). */
class GatewayCallbackController extends ApiController
{
    public function __construct(private readonly GatewayPaymentService $payments) {}

    /** POST /payments/gateway/callback — idempotent; repeats change nothing. */
    public function callback(Request $request): JsonResponse
    {
        try {
            $event = app(PaymentGateway::class)->verifyCallback($request);
        } catch (\Throwable $e) {
            Log::warning('gateway.callback.rejected', ['error' => $e->getMessage()]);

            return $this->error('Invalid callback.', 400);
        }

        $payment = $this->payments->handleEvent($event);

        return $this->success(['status' => $payment?->status->value]);
    }

    /**
     * POST /payments/atmos/callback — ATMOS pre-debit billing check. Answering
     * `status: 1` only lets ATMOS charge the card; the Propusk is activated
     * later, when getStatus() confirms the invoice (webhook-free, see
     * AtmosPaymentGateway). Repeats are harmless.
     */
    public function atmosBilling(Request $request): JsonResponse
    {
        try {
            $event = app(PaymentGateway::class)->verifyCallback($request);
        } catch (\Throwable $e) {
            Log::warning('atmos.billing.rejected', ['error' => $e->getMessage()]);

            return response()->json(['status' => 0, 'message' => 'Rejected']);
        }

        $payment = GatewayPayment::query()->where('gateway_ref', $event->gatewayRef)->first();

        if ($payment === null || $payment->status !== GatewayPaymentStatus::Pending || $event->amountTiyin !== $payment->amount_tiyin) {
            Log::warning('atmos.billing.mismatch', ['ref' => $event->gatewayRef, 'amount' => $event->amountTiyin]);

            return response()->json(['status' => 0, 'message' => 'Payment not payable']);
        }

        return response()->json(['status' => 1, 'message' => 'Successful']);
    }

    /**
     * Dev/testing only: "pay" a fake checkout. 404 in production so it
     * can never complete a payment on a real deployment.
     */
    public function fakeComplete(string $ref): JsonResponse
    {
        if (app()->isProduction() || config('passes.gateway') !== 'fake') {
            abort(404);
        }

        $payment = GatewayPayment::query()->where('gateway_ref', $ref)->firstOrFail();

        $payment = $this->payments->handleEvent(
            new GatewayEvent($ref, GatewayPaymentStatus::Success, $payment->amount_tiyin),
        );

        return $this->success(['status' => $payment?->status->value, 'payment_ref' => $payment?->reference]);
    }
}
