<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\MulticardClient;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Multicard payment status webhooks. Unauthenticated (Multicard has no
 * bearer token for us) — guarded by a source-IP allowlist and the callback
 * signature. Docs (`callback-webhooks`):
 *   sign = sha1(uuid + invoice_id + amount + secret)
 * Legacy md5(store_id + invoice_id + amount + secret) is accepted when
 * MULTICARD_CALLBACK_SIGN is `md5` or `both` (both is the default).
 */
class MulticardCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        MulticardClient $client,
        PaymentService $payments,
    ): JsonResponse {
        if (! $this->ipAllowed($request)) {
            logger()->warning('multicard.callback.ip_rejected', ['ip' => $request->ip()]);

            return response()->json(['success' => false], 403);
        }

        $payload = $request->all();
        $uuid = (string) ($payload['uuid'] ?? '');

        logger()->info('multicard.callback.received', [
            'ip' => $request->ip(),
            'uuid' => $uuid !== '' ? $uuid : null,
            'invoice_id' => $payload['invoice_id'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        $payment = Payment::query()->where('gateway_uuid', $uuid)->first();

        // Unknown transaction — ack so Multicard stops retrying; nothing to act on.
        if ($payment === null) {
            logger()->warning('multicard.callback.unknown_uuid', ['uuid' => $uuid !== '' ? $uuid : null]);

            return response()->json(['success' => true]);
        }

        $invoiceId = (string) ($payload['invoice_id'] ?? '');
        $amount = (int) ($payload['amount'] ?? -1);

        // Payload fields must match our Payment before we trust the sign input.
        if ($invoiceId !== (string) $payment->payment_uuid || $amount !== (int) $payment->amount) {
            logger()->warning('multicard.callback.payload_mismatch', [
                'uuid' => $uuid,
                'payload_invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                'our_invoice_id' => $payment->payment_uuid,
                'payload_amount' => $payload['amount'] ?? null,
                'our_amount' => $payment->amount,
            ]);

            return response()->json(['success' => false, 'error' => 'payload_mismatch'], 403);
        }

        $verified = $client->verifyCallbackSign($payload, $uuid, $invoiceId, $amount);

        if (! $verified['ok']) {
            $mode = (string) config('services.multicard.callback_sign', 'both');
            logger()->warning('multicard.callback.bad_sign', [
                'uuid' => $uuid,
                'provided' => $payload['sign'] ?? null,
                'mode' => $mode,
                'expected_sha1' => $client->callbackSign('sha1', $uuid, $invoiceId, $amount),
                'expected_md5' => $client->callbackSign('md5', $uuid, $invoiceId, $amount),
            ]);

            return response()->json(['success' => false, 'error' => 'bad_sign'], 403);
        }

        logger()->info('multicard.callback.verified', [
            'uuid' => $uuid,
            'alg' => $verified['alg'],
            'mode' => (string) config('services.multicard.callback_sign', 'both'),
            'status' => $payload['status'] ?? null,
        ]);

        $payments->handleCallback($payload);

        // 2xx so Multicard stops retrying (and does not auto-refund).
        return response()->json(['success' => true]);
    }

    private function ipAllowed(Request $request): bool
    {
        $allow = array_filter(array_map(
            'trim',
            explode(',', (string) config('services.multicard.callback_ips')),
        ));

        // Empty allowlist = no IP restriction (rely on signature only).
        if ($allow === []) {
            return true;
        }

        return in_array($request->ip(), $allow, true);
    }
}
