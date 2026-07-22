<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\SubmitLegalEntityRequest;
use App\Http\Resources\LegalEntityVerificationResource;
use App\Services\LegalEntity\LegalEntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optional legal-entity verification for self-declared legal clients/designers.
 * Not required to use the app; it unlocks the "verified" badge once an admin
 * approves the submitted INN + registration document.
 */
class LegalEntityController extends ApiController
{
    public function __construct(
        private readonly LegalEntityService $service,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $verification = $request->user()->legalEntityVerification()
            ->with('registrationCertificateFile')
            ->first();

        return $this->success(
            $verification ? new LegalEntityVerificationResource($verification) : null,
        );
    }

    public function store(SubmitLegalEntityRequest $request): JsonResponse
    {
        $verification = $this->service->submit($request->user(), $request->validated());

        return $this->success(
            new LegalEntityVerificationResource($verification->load('registrationCertificateFile')),
            'Verification submitted',
            201,
        );
    }
}
