<?php

namespace App\Http\Controllers\Api\V1\Identity;

use App\Http\Controllers\Controller;
use App\Services\MyId\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Public MyID redirect-flow callback. MyID sends the external browser here with
 * ?code&state after the user finishes verification. Unauthenticated (the visit
 * comes from MyID's redirect, not our session) — trust is established by the
 * single-use, short-lived `state` we minted. We finalize server-side, grant the
 * badge, then bounce the browser back to the mini app; the app itself polls
 * GET /me/identity for the outcome.
 */
class MyIdCallbackController extends Controller
{
    public function __invoke(Request $request, IdentityVerificationService $service): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        $status = 'failed';

        if ($error === '' && $state !== '' && $code !== '') {
            try {
                $verification = $service->finalizeByState($state, $code);
                $status = $verification?->status->value ?? 'failed';
            } catch (Throwable $e) {
                logger()->warning('myid.callback.failed', ['message' => $e->getMessage()]);
            }
        } else {
            logger()->info('myid.callback.rejected', [
                'has_state' => $state !== '',
                'has_code' => $code !== '',
                'error' => $error !== '' ? $error : null,
            ]);
        }

        return redirect()->away($this->returnUrl($status));
    }

    /**
     * Where to send the browser after the callback. Points at the mini app with
     * an `identity` flag; falls back to a bare status page when no mini app URL
     * is configured.
     */
    private function returnUrl(string $status): string
    {
        $base = (string) config('services.telegram.mini_app_url');

        if ($base === '') {
            return url('/?identity='.$status);
        }

        return rtrim($base, '/').'/?identity='.$status;
    }
}
