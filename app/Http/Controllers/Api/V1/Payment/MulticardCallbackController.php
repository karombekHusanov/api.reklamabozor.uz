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
 * bearer token for us) — guarded by a source-IP allowlist and the MD5
 * signature: md5(store_id + invoice_id + amount + secret).
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

        $payment = Payment::query()
            ->where('gateway_uuid', (string) ($payload['uuid'] ?? ''))
            ->first();

        // Unknown transaction — ack so Multicard stops retrying; nothing to act on.
        if ($payment === null) {
            return response()->json(['success' => true]);
        }

        if (! $client->verifyCallbackSign($payload, (string) $payment->payment_uuid, (int) $payment->amount)) {
            logger()->warning('multicard.callback.bad_sign', ['uuid' => $payload['uuid'] ?? null]);

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
