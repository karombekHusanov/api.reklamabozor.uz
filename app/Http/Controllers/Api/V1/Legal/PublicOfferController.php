<?php

namespace App\Http\Controllers\Api\V1\Legal;

use App\Http\Controllers\ApiController;
use App\Services\Legal\PublicOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Offers are served in `?locale=uz-Cyrl|uz|ru|en` (or `Accept-Language`);
 * without either the binding Uzbek Cyrillic original is returned.
 */
class PublicOfferController extends ApiController
{
    public function __construct(private readonly PublicOfferService $offers) {}

    /** Public: the client offer text for the in-app reader. */
    public function show(Request $request): JsonResponse
    {
        return $this->documentResponse($request, PublicOfferService::CLIENT, '/api/v1/legal/public-offer.pdf');
    }

    /** Public: the same offer as a downloadable PDF. */
    public function pdf(Request $request): Response
    {
        return $this->pdfResponse($request, PublicOfferService::CLIENT);
    }

    /** Public: the agency partnership offer an agent accepts when applying. */
    public function agentShow(Request $request): JsonResponse
    {
        return $this->documentResponse($request, PublicOfferService::AGENT, '/api/v1/legal/agent-offer.pdf');
    }

    /** Public: the agency partnership offer as a downloadable PDF. */
    public function agentPdf(Request $request): Response
    {
        return $this->pdfResponse($request, PublicOfferService::AGENT);
    }

    private function documentResponse(Request $request, string $kind, string $pdfPath): JsonResponse
    {
        $locale = $this->offers->localeFromRequest($request) ?? PublicOfferService::ORIGINAL_LOCALE;

        return $this->success([
            ...$this->offers->document($kind, $locale),
            'version' => $this->offers->version($kind),
            'locale' => $locale,
            'is_original' => $this->offers->isOriginal($locale),
            'original_locale' => PublicOfferService::ORIGINAL_LOCALE,
            'available_locales' => PublicOfferService::LOCALES,
            'translation_notice' => $this->offers->translationNotice($locale),
            'pdf_url' => url($pdfPath).'?locale='.$locale,
            'original_pdf_url' => url($pdfPath).'?locale='.PublicOfferService::ORIGINAL_LOCALE,
        ])->setVary('Accept-Language');
    }

    private function pdfResponse(Request $request, string $kind): Response
    {
        $locale = $this->offers->localeFromRequest($request) ?? PublicOfferService::ORIGINAL_LOCALE;

        return response($this->offers->pdf($kind, $locale), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->offers->pdfName($kind, $locale).'"',
            'Cache-Control' => 'public, max-age=3600',
            'Vary' => 'Accept-Language',
        ]);
    }
}
