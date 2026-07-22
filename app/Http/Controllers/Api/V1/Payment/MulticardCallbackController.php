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
        if (! $this->ipAllowed($request)) {
            return response()->json(['success' => false], 403);
        }

        $payload = $request->all();

        if (! $client->verifyCallbackSign($payload)) {
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
