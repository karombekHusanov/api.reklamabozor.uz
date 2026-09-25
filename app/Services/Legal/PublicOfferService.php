<?php

namespace App\Services\Legal;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * The client public offer (ommaviy oferta): one legally approved text, served
 * both as structured JSON (mini app reader) and as a downloadable PDF.
 */
class PublicOfferService
{
    /** @return array{title: string, sections: list<array{title: string, clauses: list<array{label: string, text: string}>}>, requisites_title: string, requisites: list<array{label: string, value: string}>} */
    public function document(): array
    {
        return require resource_path('legal/public_offer_client.php');
    }

    public function version(): string
    {
        return (string) config('legal.terms_version');
    }

    /** Content hash — the rendered PDF is cached per text, so edits regenerate it. */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->document(), JSON_UNESCAPED_UNICODE).'|'.$this->version());
    }

    /** Rendered PDF bytes, generated once per text version and kept on the local disk. */
    public function pdf(): string
    {
        $path = 'legal/public-offer-'.substr($this->hash(), 0, 16).'.pdf';
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $disk->put($path, Pdf::loadView('legal.public_offer', [
                'doc' => $this->document(),
                'version' => $this->version(),
            ])->setPaper('a4')->output());
        }

        return $disk->get($path);
    }
}
