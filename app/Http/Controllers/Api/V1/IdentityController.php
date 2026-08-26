<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\FinalizeIdentityRequest;
use App\Http\Resources\IdentityVerificationResource;
use App\Services\MyId\IdentityVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional MyID identity verification for any authenticated user. Grants the
 * "identity verified" badge; never a gate. When MyID is disabled (no contract
 * yet) the session/finalize endpoints return 404 and `show` reports state only.
 */
class IdentityController extends ApiController
{
    public function __construct(
        private readonly IdentityVerificationService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $verification = $request->user()->identityVerification;

        return $this->success(
            $verification ? new IdentityVerificationResource($verification) : null,
        );
    }

    /**
     * Start a MyID WebSDK session; returns session_id + iframe config the mini
     * app needs to mount the camera.
     */
    public function session(Request $request): JsonResponse
    {
        if (! $this->service->enabled()) {
            return $this->error('Identity verification is not available', 404);
        }

        $verification = $this->service->startSession($request->user(), $request->ip());

        return $this->success([
            'session_id' => $verification->session_id,
            'web_url' => config('services.myid.web_url'),
            'verification' => new IdentityVerificationResource($verification),
        ], 'Session created', 201);
    }

    /**
     * Redirect-flow fallback: return the MyID authorization URL for the mini app
     * to open via WebApp.openLink when the camera iframe is blocked in the
     * WebView. The badge is granted later by the public callback; the mini app
     * polls GET /me/identity for the result.
     */
    public function authorize(Request $request): JsonResponse
    {
        if (! $this->service->enabled()) {
            return $this->error('Identity verification is not available', 404);
        }

        return $this->success([
            'authorization_url' => $this->service->authorize($request->user()),
        ]);
    }

    /**
     * Dev/test only: grant a simulated verified identity without calling MyID.
     * Available when MYID_SIMULATE is on. Lets the whole card → badge flow be
     * exercised in a dev/staging (or prod-test) environment without creds.
     */
    public function simulate(Request $request): JsonResponse
    {
        if (! $this->service->simulate()) {
            return $this->error('Identity verification is not available', 404);
        }

        $verification = $this->service->simulateVerify($request->user());

        return $this->success(new IdentityVerificationResource($verification));
    }

    /**
     * Finalize after the user finishes MyID: exchange the auth_code server-side
     * and grant/fail the badge.
     */
    public function finalize(FinalizeIdentityRequest $request): JsonResponse
    {
        if (! $this->service->enabled()) {
            return $this->error('Identity verification is not available', 404);
        }

        $verification = $this->service->finalize($request->user(), $request->validated('auth_code'));

        return $this->success(new IdentityVerificationResource($verification));
    }
}
