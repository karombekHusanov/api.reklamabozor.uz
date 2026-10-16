<?php

namespace Tests\Feature\Api\V1;

use App\Enums\AgentContractStatus;
use App\Enums\OrderDocumentType;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\Contract;
use App\Models\File;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Payment;
use App\Models\User;
use App\Services\File\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AUDIT_2026-10-16 §2.3 — KYC scans, contracts, invoices and acts live on a
 * non-served disk and stream through GET /files/{file} with a participant check.
 */
class PrivateFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    /** A generic upload as the mini app produces it: public disk, bytes present. */
    private function publicUpload(User $uploader, string $bytes = 'scan-bytes'): File
    {
        $file = File::factory()->for($uploader, 'uploader')->create();
        Storage::disk('public')->put($file->path, $bytes);

        return $file;
    }

    private function privatePdf(?int $uploadedBy, string $bytes = '%PDF-1.4 test'): File
    {
        return app(FileService::class)->storeContents($bytes, 'doc.pdf', 'application/pdf', $uploadedBy, 'contracts', private: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function kycPayload(File $passport, File $certificate): array
    {
        return [
            'company_name' => 'Nova Media Group',
            'legal_form' => 'MChJ',
            'inn' => '123456789',
            'director_name' => 'Ali Valiyev',
            'director_passport' => 'AA1234567',
            'director_passport_file_id' => $passport->id,
            'registration_certificate_file_id' => $certificate->id,
            'bank_name' => 'Ipoteka Bank',
            'bank_account' => '20208000900123456789',
            'mfo' => '00440',
            'phone' => '+998901234567',
            'legal_address' => 'Toshkent sh., Chilonzor t., 1-uy',
            'director_pinfl' => '12345678901234',
            'director_position' => 'Direktor',
            'accept_offer' => true,
        ];
    }

    /**
     * @return array{0: Order, 1: User, 2: User} order, client, agent
     */
    private function deal(): array
    {
        $client = User::factory()->create();
        $agent = User::factory()->agent()->create();
        $order = Order::factory()->for($client, 'client')->create();
        Offer::factory()->for($order)->for($agent, 'agent')->accepted()->create();

        return [$order, $client, $agent];
    }

    public function test_generated_documents_are_stored_on_the_private_disk(): void
    {
        $file = $this->privatePdf(null);

        $this->assertTrue($file->is_private);
        $this->assertSame('local', $file->disk);
        $this->assertSame("/api/v1/files/{$file->id}", $file->url());
        $this->assertStringNotContainsString('/storage/', $file->absoluteUrl());
        Storage::disk('local')->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
    }

    public function test_public_uploads_are_untouched(): void
    {
        $file = $this->publicUpload(User::factory()->create());

        $this->assertFalse($file->is_private);
        $this->assertStringStartsWith('/storage/', $file->url());
    }

    public function test_agent_kyc_application_moves_the_scans_private(): void
    {
        $user = User::factory()->create();
        $passport = $this->publicUpload($user, 'passport-bytes');
        $certificate = $this->publicUpload($user, 'certificate-bytes');
        $passportPublicPath = $passport->path;

        $this->actingAs($user)
            ->postJson('/api/v1/agent/profile', $this->kycPayload($passport, $certificate))
            ->assertCreated()
            ->assertJsonPath('data.director_passport_file', "/api/v1/files/{$passport->id}")
            ->assertJsonPath('data.registration_certificate_file', "/api/v1/files/{$certificate->id}");

        foreach ([$passport, $certificate] as $file) {
            $file->refresh();
            $this->assertTrue($file->is_private);
            $this->assertSame('local', $file->disk);
            Storage::disk('local')->assertExists($file->path);
        }

        Storage::disk('public')->assertMissing($passportPublicPath);
        $this->assertSame('passport-bytes', Storage::disk('local')->get($passport->path));
    }

    public function test_individual_kyc_application_moves_the_scans_private(): void
    {
        $user = User::factory()->create();
        $passport = $this->publicUpload($user);
        $selfie = $this->publicUpload($user);
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/individual/profile', [
            'full_name' => 'Dilnoza Test',
            'passport_file_id' => $passport->id,
            'selfie_file_id' => $selfie->id,
            'category_ids' => [$category->id],
        ])->assertCreated();

        $this->assertTrue($passport->refresh()->is_private);
        $this->assertTrue($selfie->refresh()->is_private);
        Storage::disk('local')->assertExists($selfie->path);
    }

    public function test_signed_contract_upload_goes_private(): void
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->create([
            'contract_status' => AgentContractStatus::AwaitingSignature,
        ]);
        $signed = $this->publicUpload($user);

        $this->actingAs($user)->postJson('/api/v1/agent/profile/contract', ['file_id' => $signed->id])
            ->assertOk()
            ->assertJsonPath('data.contract.signed_file', "/api/v1/files/{$signed->id}");

        $this->assertTrue($signed->refresh()->is_private);
        $this->assertSame($signed->id, $profile->fresh()->signed_contract_file_id);
    }

    public function test_kyc_file_streams_to_its_owner_and_admins_only(): void
    {
        $owner = User::factory()->create();
        $passport = $this->publicUpload($owner, 'passport-bytes');
        AgentProfile::factory()->for($owner)->create(['director_passport_file_id' => $passport->id]);
        app(FileService::class)->makePrivate($passport);

        $this->getJson("/api/v1/files/{$passport->id}")->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/files/{$passport->id}")
            ->assertForbidden();

        $response = $this->actingAs($owner)->get("/api/v1/files/{$passport->id}");
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('passport-bytes', $response->streamedContent());

        $this->actingAs(User::factory()->admin()->create())
            ->get("/api/v1/files/{$passport->id}")
            ->assertOk();
    }

    public function test_contract_pdf_streams_to_the_client_and_the_agent_only(): void
    {
        [$order, $client, $agent] = $this->deal();
        $pdf = $this->privatePdf($client->id);

        Contract::create([
            'order_id' => $order->id,
            'offer_id' => $order->acceptedOffer()->value('id'),
            'number' => 'RB-1-2026',
            'total' => 1_000_000,
            'client_snapshot' => [],
            'agent_snapshot' => [],
            'items_snapshot' => [],
            'pdf_file_id' => $pdf->id,
            'hash' => hash('sha256', 'x'),
            'version' => 'v2',
            'generated_at' => now(),
        ]);

        $this->actingAs($client)->get("/api/v1/files/{$pdf->id}")->assertOk();
        $this->actingAs($agent)->get("/api/v1/files/{$pdf->id}")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/files/{$pdf->id}")->assertForbidden();
    }

    public function test_commission_act_is_hidden_from_the_client_even_though_it_was_stored_on_their_behalf(): void
    {
        [$order, $client, $agent] = $this->deal();
        $offerId = $order->acceptedOffer()->value('id');

        $commission = $this->privatePdf($client->id);
        $work = $this->privatePdf($client->id);

        foreach ([[OrderDocumentType::CommissionAct, $commission], [OrderDocumentType::WorkAct, $work]] as [$type, $file]) {
            OrderDocument::create([
                'order_id' => $order->id,
                'offer_id' => $offerId,
                'type' => $type->value,
                'number' => 'RB-1-2026/'.$type->numberSuffix(),
                'total' => 1_000_000,
                'snapshot' => [],
                'pdf_file_id' => $file->id,
                'hash' => hash('sha256', 'x'),
                'version' => 'v1',
                'generated_at' => now(),
            ]);
        }

        $this->actingAs($client)->getJson("/api/v1/files/{$commission->id}")->assertForbidden();
        $this->actingAs($agent)->get("/api/v1/files/{$commission->id}")->assertOk();

        $this->actingAs($client)->get("/api/v1/files/{$work->id}")->assertOk();
        $this->actingAs($agent)->get("/api/v1/files/{$work->id}")->assertOk();
    }

    public function test_invoice_streams_to_the_order_parties(): void
    {
        [$order, $client, $agent] = $this->deal();
        $invoice = $this->privatePdf($client->id);

        Payment::factory()->create([
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'payer_id' => $client->id,
            'invoice_file_id' => $invoice->id,
        ]);

        $this->actingAs($client)->get("/api/v1/files/{$invoice->id}")->assertOk();
        $this->actingAs($agent)->get("/api/v1/files/{$invoice->id}")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/files/{$invoice->id}")->assertForbidden();
    }

    public function test_unattached_private_file_is_readable_by_its_uploader_only(): void
    {
        $uploader = User::factory()->create();
        $file = $this->privatePdf($uploader->id);

        $this->actingAs($uploader)->get("/api/v1/files/{$file->id}")->assertOk();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/files/{$file->id}")->assertForbidden();
    }

    public function test_missing_bytes_return_404_not_500(): void
    {
        $uploader = User::factory()->create();
        $file = $this->privatePdf($uploader->id);
        Storage::disk('local')->delete($file->path);

        $this->actingAs($uploader)->getJson("/api/v1/files/{$file->id}")->assertNotFound();
    }

    public function test_files_privatize_command_moves_referenced_public_files(): void
    {
        $user = User::factory()->create();
        $passport = $this->publicUpload($user, 'passport-bytes');
        $avatar = $this->publicUpload($user, 'avatar-bytes');
        AgentProfile::factory()->for($user)->create(['director_passport_file_id' => $passport->id]);
        $user->forceFill(['avatar_file_id' => $avatar->id])->save();

        $this->artisan('files:privatize', ['--dry-run' => true])
            ->expectsOutputToContain('1 file(s) would be moved')
            ->assertSuccessful();

        $this->assertFalse($passport->refresh()->is_private);
        Storage::disk('public')->assertExists($passport->path);

        $this->artisan('files:privatize')
            ->expectsOutputToContain('1 file(s) moved')
            ->assertSuccessful();

        $passport->refresh();
        $this->assertTrue($passport->is_private);
        $this->assertSame('local', $passport->disk);
        Storage::disk('local')->assertExists($passport->path);
        $this->assertSame('passport-bytes', Storage::disk('local')->get($passport->path));

        // Public files referenced elsewhere (avatar) stay public and in place.
        $this->assertFalse($avatar->refresh()->is_private);
        Storage::disk('public')->assertExists($avatar->path);

        // Idempotent re-run.
        $this->artisan('files:privatize')
            ->expectsOutputToContain('0 file(s) moved')
            ->assertSuccessful();
    }
}
