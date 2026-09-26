<?php

namespace App\Http\Controllers\Api\V1\Legal;

use App\Http\Controllers\ApiController;
use App\Services\Legal\PublicOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicOfferController extends ApiController
{
    public function __construct(private readonly PublicOfferService $offers) {}

    /** Public: the client offer text for the in-app reader. */
    public function show(): JsonResponse
    {
        return $this->documentResponse(PublicOfferService::CLIENT, '/api/v1/legal/public-offer.pdf');
    }

    /** Public: the same offer as a downloadable PDF. */
    public function pdf(): Response
    {
        return $this->pdfResponse(PublicOfferService::CLIENT);
    }

    /** Public: the agency partnership offer an agent accepts when applying. */
    public function agentShow(): JsonResponse
    {
        return $this->documentResponse(PublicOfferService::AGENT, '/api/v1/legal/agent-offer.pdf');
    }

    /** Public: the agency partnership offer as a downloadable PDF. */
    public function agentPdf(): Response
    {
        return $this->pdfResponse(PublicOfferService::AGENT);
    }

    private function documentResponse(string $kind, string $pdfPath): JsonResponse
    {
        return $this->success([
            ...$this->offers->document($kind),
            'version' => $this->offers->version($kind),
            'pdf_url' => url($pdfPath),
        ]);
    }

    private function pdfResponse(string $kind): Response
    {
        return response($this->offers->pdf($kind), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->offers->pdfName($kind).'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
