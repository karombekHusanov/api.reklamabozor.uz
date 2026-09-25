<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicOfferTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_text_is_public(): void
    {
        $this->getJson('/api/v1/legal/public-offer')
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
}
