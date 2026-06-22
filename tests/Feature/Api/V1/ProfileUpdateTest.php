<?php

namespace Tests\Feature\Api\V1;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requires_authentication(): void
    {
        $this->patchJson('/api/v1/me', [])->assertUnauthorized();
    }

    public function test_user_can_set_avatar_from_uploaded_file_and_me_returns_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // 1) Upload → get file id
        $fileId = $this->postJson('/api/v1/file-upload', [
            'file' => UploadedFile::fake()->image('me.png'),
        ], ['Authorization' => 'Bearer '.$token])->json('data.id');

        // 2) Set avatar by id
        $this->patchJson('/api/v1/me', [
            'avatar_file_id' => $fileId,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.avatar_file_id', $fileId)
            ->assertJsonPath('data.id', $user->id);

        // 3) GET /me resolves the file id to a URL
        $response = $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token]);
        $response->assertOk()->assertJsonPath('data.avatar_file_id', $fileId);
        $this->assertNotNull($response->json('data.avatar'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_file_id' => $fileId]);
    }

    public function test_user_cannot_set_avatar_to_another_users_file(): void
    {
        $owner = User::factory()->create();
        $foreignFile = File::factory()->create(['uploaded_by' => $owner->id]);

        $other = User::factory()->create();
        $token = $other->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me', [
            'avatar_file_id' => $foreignFile->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar_file_id']);
    }

    public function test_user_can_update_first_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Old']);
        $token = $user->createToken('test')->plainTextToken;

        $this->patchJson('/api/v1/me', [
            'first_name' => 'New',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'New');
    }
}
