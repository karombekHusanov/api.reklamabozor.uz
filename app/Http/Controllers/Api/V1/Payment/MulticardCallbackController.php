<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Services\Payment\MulticardClient;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Multicard payment status webhooks. Unauthenticated (Multicard has
 * no bearer token for us) — guarded by a source-IP allowlist and the SHA1
 * signature: sha1(uuid + invoice_id + amount + secret).
 */
class MulticardCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        MulticardClient $client,
        PaymentService $payments,
    ): JsonResponse {
        $payload = $request->all();

        // Callback diagnostics (temporary): confirm the exact field names + sign
        // so we can fix any mismatch. Card/phone omitted from the log.
        logger()->info('multicard.callback.received', [
            'ip' => $request->ip(),
            'keys' => array_keys($payload),
            'uuid' => $payload['uuid'] ?? null,
            'invoice_id' => $payload['invoice_id'] ?? null,
            'store_invoice_id' => $payload['store_invoice_id'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'payment_amount' => $payload['payment_amount'] ?? null,
            'total_amount' => $payload['total_amount'] ?? null,
            'status' => $payload['status'] ?? null,
            'sign' => $payload['sign'] ?? null,
        ]);

        if (! $this->ipAllowed($request)) {
            logger()->warning('multicard.callback.ip_rejected', ['ip' => $request->ip()]);

            return response()->json(['success' => false], 403);
        }

        if (! $client->verifyCallbackSign($payload)) {
            logger()->warning('multicard.callback.bad_sign', [
                'expected' => $client->expectedCallbackSign($payload),
                'provided' => $payload['sign'] ?? null,
            ]);

            return response()->json(['success' => false, 'error' => 'bad_sign'], 403);
        }

        $payments->handleCallback($payload);

        // 2xx so Multicard stops retrying.
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
