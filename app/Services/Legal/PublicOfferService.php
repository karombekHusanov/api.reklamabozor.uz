<?php

namespace App\Services\Legal;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Public offers (ommaviy oferta): each is one legally approved text, served
 * both as structured JSON (mini app reader) and as a downloadable PDF.
 *
 *  - `client` — accepted by every user at onboarding (TermsGate).
 *  - `agent`  — the agency partnership offer, accepted when applying as an
 *               agent; an agent cannot be approved without it.
 *
 * Languages: the binding text is the Uzbek Cyrillic original
 * (`resources/legal/{file}.php`, locale `uz-Cyrl`). Uzbek Latin, Russian and
 * English versions live in `resources/legal/translations/{uz,ru,en}/` and are
 * informational only — every translation carries a notice that the Uzbek
 * original prevails. One version (`config/legal.php`) covers all languages,
 * and the acceptance hash is always taken from the original (see hash()), so
 * adding or editing a translation never invalidates existing acceptances.
 */
class PublicOfferService
{
    public const CLIENT = 'client';

    public const AGENT = 'agent';

    /** The binding Uzbek Cyrillic original. */
    public const ORIGINAL_LOCALE = 'uz-Cyrl';

    /** Every locale a document is served in; the first is the original. */
    public const LOCALES = [self::ORIGINAL_LOCALE, 'uz', 'ru', 'en'];

    /** Shown on top of every translation (screen + PDF), in its own language. */
    private const TRANSLATION_NOTICES = [
        'uz' => 'Ushbu matn maʼlumot uchun berilgan (rasmiy matnning lotin yozuvidagi nusxasi). Nizoli holatda oʻzbek tilidagi (kirill yozuvidagi) rasmiy matn ustun hisoblanadi.',
        'ru' => 'Перевод приводится для информации. В случае разногласий преимущественную силу имеет официальный текст на узбекском языке.',
        'en' => 'This translation is provided for information only. In case of any discrepancy, the official Uzbek-language text prevails.',
    ];

    /** kind => [text file under resources/legal, version config key, PDF base name] */
    private const DOCUMENTS = [
        self::CLIENT => ['public_offer_client', 'legal.terms_version', 'PRB_ommaviy_oferta'],
        self::AGENT => ['agent_offer', 'legal.agent_offer_version', 'PRB_agentlik_ofertasi'],
    ];

    /**
     * The offer text in the given locale (null / unknown = the original).
     *
     * @return array{title: string, sections: list<array{title: string, clauses: list<array{label: string, text: string}>}>, requisites_title: string, requisites: list<array{label: string, value: string}>}
     */
    public function document(string $kind = self::CLIENT, ?string $locale = null): array
    {
        $file = $this->definition($kind)[0];
        $locale = $this->normalizeLocale($locale) ?? self::ORIGINAL_LOCALE;

        if ($locale === self::ORIGINAL_LOCALE) {
            return require resource_path('legal/'.$file.'.php');
        }

        return require resource_path('legal/translations/'.$locale.'/'.$file.'.php');
    }

    public function version(string $kind = self::CLIENT): string
    {
        return (string) config($this->definition($kind)[1]);
    }

    public function pdfName(string $kind = self::CLIENT, ?string $locale = null): string
    {
        $locale = $this->normalizeLocale($locale) ?? self::ORIGINAL_LOCALE;
        $base = $this->definition($kind)[2];

        return $locale === self::ORIGINAL_LOCALE ? $base.'.pdf' : $base.'_'.$locale.'.pdf';
    }

    public function isOriginal(?string $locale): bool
    {
        return ($this->normalizeLocale($locale) ?? self::ORIGINAL_LOCALE) === self::ORIGINAL_LOCALE;
    }

    /** "Translation is informational, the Uzbek original prevails" — null for the original. */
    public function translationNotice(?string $locale): ?string
    {
        return self::TRANSLATION_NOTICES[$this->normalizeLocale($locale) ?? self::ORIGINAL_LOCALE] ?? null;
    }

    /**
     * Acceptance hash — ALWAYS of the binding Uzbek Cyrillic original + the
     * version, whatever language the user read. Stored on acceptance
     * (`agent_profiles.offer_hash`); translations deliberately do not feed it.
     */
    public function hash(string $kind = self::CLIENT): string
    {
        return hash('sha256', json_encode($this->document($kind), JSON_UNESCAPED_UNICODE).'|'.$this->version($kind));
    }

    /** Rendered PDF bytes, cached per language and per text version on the local disk. */
    public function pdf(string $kind = self::CLIENT, ?string $locale = null): string
    {
        $locale = $this->normalizeLocale($locale) ?? self::ORIGINAL_LOCALE;
        $doc = $this->document($kind, $locale);
        $notice = $this->translationNotice($locale);

        $contentHash = hash('sha256', json_encode([$doc, $notice], JSON_UNESCAPED_UNICODE).'|'.$this->version($kind));
        $prefix = $kind === self::CLIENT ? 'public-offer' : $kind.'-offer';
        $path = 'legal/'.$prefix.'-'.strtolower($locale).'-'.substr($contentHash, 0, 16).'.pdf';
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $disk->put($path, Pdf::loadView('legal.public_offer', [
                'doc' => $doc,
                'version' => $this->version($kind),
                'lang' => $locale,
                'notice' => $notice,
            ])->setPaper('a4')->output());
        }

        return $disk->get($path);
    }

    /**
     * Locale from `?locale=` first, then `Accept-Language`; null when neither
     * names a supported language (callers then serve the original).
     */
    public function localeFromRequest(Request $request): ?string
    {
        $query = $request->query('locale');
        if (is_string($query) && ($locale = $this->normalizeLocale($query)) !== null) {
            return $locale;
        }

        foreach ($request->getLanguages() as $language) {
            if (($locale = $this->normalizeLocale($language)) !== null) {
                return $locale;
            }
        }

        return null;
    }

    /** `uz-Cyrl` / `uz_cyrl` → original; `uz` / `uz-Latn` / `ru-RU` / `en_US` → the language; else null. */
    public function normalizeLocale(?string $locale): ?string
    {
        if ($locale === null || $locale === '') {
            return null;
        }

        $parts = preg_split('/[-_]/', strtolower(trim($locale))) ?: [];
        $language = $parts[0] ?? '';

        if ($language === 'uz' && in_array('cyrl', $parts, true)) {
            return self::ORIGINAL_LOCALE;
        }

        return in_array($language, ['uz', 'ru', 'en'], true) ? $language : null;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function definition(string $kind): array
    {
        return self::DOCUMENTS[$kind] ?? throw new InvalidArgumentException("Unknown offer kind [{$kind}].");
    }
}
