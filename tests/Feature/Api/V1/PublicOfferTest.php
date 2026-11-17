<?php

namespace Tests\Feature\Api\V1;

use App\Services\Legal\PublicOfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_text_is_public(): void
    {
        $this->withHeader('Accept-Language', '')->getJson('/api/v1/legal/public-offer')
            ->assertOk()
            ->assertJsonPath('data.version', config('legal.terms_version'))
            ->assertJsonCount(11, 'data.sections')
            ->assertJsonPath('data.requisites.0.value', '«Imprint Business» МЧЖ')
            ->assertJsonStructure(['data' => ['title', 'sections' => [['title', 'clauses' => [['label', 'text']]]], 'pdf_url']]);
    }

    public function test_offer_downloads_as_pdf(): void
    {
        Storage::fake('local');

        $response = $this->get('/api/v1/legal/public-offer.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertCount(1, Storage::disk('local')->files('legal'));
    }

    public function test_default_is_the_binding_uzbek_cyrillic_original(): void
    {
        // No ?locale and no Accept-Language (the test client sends en-us by default).
        $this->withHeader('Accept-Language', '')->getJson('/api/v1/legal/public-offer')
            ->assertOk()
            ->assertJsonPath('data.locale', 'uz-Cyrl')
            ->assertJsonPath('data.is_original', true)
            ->assertJsonPath('data.translation_notice', null)
            ->assertJsonPath('data.available_locales', ['uz-Cyrl', 'uz', 'ru', 'en'])
            ->assertJsonPath('data.pdf_url', url('/api/v1/legal/public-offer.pdf').'?locale=uz-Cyrl');
    }

    public function test_offer_is_served_in_uzbek_latin_russian_and_english(): void
    {
        $this->getJson('/api/v1/legal/public-offer?locale=uz')
            ->assertOk()
            ->assertJsonPath('data.locale', 'uz')
            ->assertJsonPath('data.is_original', false)
            ->assertJsonPath('data.version', config('legal.terms_version'))
            ->assertJsonPath('data.title', '«PRB» PLATFORMASIDAN FOYDALANUVCHI MIJOZLAR UCHUN OMMAVIY OFERTA')
            ->assertJsonPath('data.requisites.0.value', '«Imprint Business» MChJ');

        $ru = $this->getJson('/api/v1/legal/public-offer?locale=ru')->assertOk()->assertJsonCount(11, 'data.sections');
        $this->assertStringContainsString('Узбекистан', $ru->json('data.sections.0.clauses.0.text'));
        $this->assertStringContainsString('узбекском', $ru->json('data.translation_notice'));

        $en = $this->getJson('/api/v1/legal/public-offer?locale=en')->assertOk()->assertJsonCount(11, 'data.sections');
        $this->assertStringContainsString('Uzbekistan', $en->json('data.sections.0.clauses.0.text'));
        $this->assertStringContainsString('Uzbek-language text prevails', $en->json('data.translation_notice'));
    }

    public function test_locale_falls_back_to_accept_language_then_to_the_original(): void
    {
        $this->getJson('/api/v1/legal/public-offer', ['Accept-Language' => 'ru-RU,ru;q=0.9'])
            ->assertJsonPath('data.locale', 'ru');
        $this->getJson('/api/v1/legal/public-offer', ['Accept-Language' => 'uz-Cyrl'])
            ->assertJsonPath('data.locale', 'uz-Cyrl');
        $this->getJson('/api/v1/legal/public-offer?locale=en', ['Accept-Language' => 'ru'])
            ->assertJsonPath('data.locale', 'en');
        $this->getJson('/api/v1/legal/public-offer?locale=de', ['Accept-Language' => 'fr'])
            ->assertJsonPath('data.locale', 'uz-Cyrl');
    }

    public function test_pdf_is_cached_separately_per_language(): void
    {
        Storage::fake('local');

        foreach (['uz-Cyrl', 'uz', 'ru', 'en'] as $locale) {
            $response = $this->get('/api/v1/legal/public-offer.pdf?locale='.$locale)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        // Second request of the same language is served from the cache.
        $this->get('/api/v1/legal/public-offer.pdf?locale=ru')->assertOk();

        $this->assertCount(4, Storage::disk('local')->files('legal'));
        $this->assertStringContainsString(
            'PRB_ommaviy_oferta_en.pdf',
            (string) $this->get('/api/v1/legal/public-offer.pdf?locale=en')->headers->get('Content-Disposition'),
        );
    }

    public function test_translations_do_not_change_the_acceptance_version_or_hash(): void
    {
        $offers = app(PublicOfferService::class);
        $hash = $offers->hash();

        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->getJson('/api/v1/legal/public-offer?locale='.$locale)
                ->assertJsonPath('data.version', config('legal.terms_version'));
        }

        $this->assertSame($hash, $offers->hash());
        $this->assertSame($offers->document(), $offers->document(PublicOfferService::CLIENT, 'uz-Cyrl'));
    }
}
