<?php

namespace App\Services\Legal;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Public offers (ommaviy oferta): each is one legally approved text, served
 * both as structured JSON (mini app reader) and as a downloadable PDF.
 *
 *  - `client` — accepted by every user at onboarding (TermsGate).
 *  - `agent`  — the agency partnership offer, accepted when applying as an
 *               agent; an agent cannot be approved without it.
 */
class PublicOfferService
{
    public const CLIENT = 'client';

    public const AGENT = 'agent';

    /** kind => [text file under resources/legal, version config key, PDF name] */
    private const DOCUMENTS = [
        self::CLIENT => ['public_offer_client', 'legal.terms_version', 'PRB_ommaviy_oferta.pdf'],
        self::AGENT => ['agent_offer', 'legal.agent_offer_version', 'PRB_agentlik_ofertasi.pdf'],
    ];

    /** @return array{title: string, sections: list<array{title: string, clauses: list<array{label: string, text: string}>}>, requisites_title: string, requisites: list<array{label: string, value: string}>} */
    public function document(string $kind = self::CLIENT): array
    {
        return require resource_path('legal/'.$this->definition($kind)[0].'.php');
    }

    public function version(string $kind = self::CLIENT): string
    {
        return (string) config($this->definition($kind)[1]);
    }

    public function pdfName(string $kind = self::CLIENT): string
    {
        return $this->definition($kind)[2];
    }

    /** Content hash — the rendered PDF is cached per text, so edits regenerate it. */
    public function hash(string $kind = self::CLIENT): string
    {
        return hash('sha256', json_encode($this->document($kind), JSON_UNESCAPED_UNICODE).'|'.$this->version($kind));
    }

    /** Rendered PDF bytes, generated once per text version and kept on the local disk. */
    public function pdf(string $kind = self::CLIENT): string
    {
        $prefix = $kind === self::CLIENT ? 'public-offer' : $kind.'-offer';
        $path = 'legal/'.$prefix.'-'.substr($this->hash($kind), 0, 16).'.pdf';
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $disk->put($path, Pdf::loadView('legal.public_offer', [
                'doc' => $this->document($kind),
                'version' => $this->version($kind),
            ])->setPaper('a4')->output());
        }

        return $disk->get($path);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function definition(string $kind): array
    {
        return self::DOCUMENTS[$kind] ?? throw new InvalidArgumentException("Unknown offer kind [{$kind}].");
    }
}
