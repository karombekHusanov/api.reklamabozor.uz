<?php

namespace Tests\Feature\Api\V1;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    private function userToken(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    public function test_upload_requires_authentication(): void
    {
        $this->postJson('/api/v1/file-upload', [])->assertUnauthorized();
    }

    public function test_user_can_upload_an_image_and_gets_id_and_url(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/v1/file-upload', [
            'file' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ], ['Authorization' => 'Bearer '.$this->userToken()]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'url', 'original_name', 'mime_type', 'size']]);

        $fileId = $response->json('data.id');

        $this->assertDatabaseHas('files', ['id' => $fileId, 'disk' => 'public']);

        $path = File::find($fileId)->path;
        Storage::disk('public')->assertExists($path);
    }

    public function test_upload_rejects_disallowed_mime(): void
    {
        Storage::fake('public');

        $this->postJson('/api/v1/file-upload', [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ], ['Authorization' => 'Bearer '.$this->userToken()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_upload_rejects_oversized_file(): void
    {
        Storage::fake('public');

        // 6 MB > default 5 MB limit
        $this->postJson('/api/v1/file-upload', [
            'file' => UploadedFile::fake()->create('big.pdf', 6144, 'application/pdf'),
        ], ['Authorization' => 'Bearer '.$this->userToken()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }
}
