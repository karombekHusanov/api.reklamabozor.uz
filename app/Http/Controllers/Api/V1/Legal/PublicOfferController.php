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
        return $this->success([
            ...$this->offers->document(),
            'version' => $this->offers->version(),
            'pdf_url' => url('/api/v1/legal/public-offer.pdf'),
        ]);
    }

    /** Public: the same offer as a downloadable PDF. */
    public function pdf(): Response
    {
        return response($this->offers->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="PRB_ommaviy_oferta.pdf"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
