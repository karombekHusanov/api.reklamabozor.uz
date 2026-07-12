<?php

namespace Tests\Feature\Api\V1\Agent;

use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPortfolioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: AgentProfile, 2: array<string, string>}
     */
    private function approvedAgent(): array
    {
        $user = User::factory()->create();
        $profile = AgentProfile::factory()->for($user)->approved()->create();

        return [$user, $profile, ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken]];
    }

    public function test_approved_agent_can_add_and_list_portfolio_items(): void
    {
        [$user, , $headers] = $this->approvedAgent();
        $image = File::factory()->create(['uploaded_by' => $user->id]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_id' => $image->id,
            'title' => 'Chilonzor billboard',
            'description' => 'LED banner, 3x6',
            'link_url' => 'https://example.com/case',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Chilonzor billboard')
            ->assertJsonPath('data.is_hidden', false);

        $this->getJson('/api/v1/agent/portfolio', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_pending_agent_cannot_manage_portfolio(): void
    {
        $user = User::factory()->create();
        AgentProfile::factory()->for($user)->create(); // pending
        $image = File::factory()->create(['uploaded_by' => $user->id]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_id' => $image->id,
            'title' => 'X',
        ], ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken])
            ->assertForbidden();
    }

    public function test_cannot_use_someone_elses_image(): void
    {
        [, , $headers] = $this->approvedAgent();
        $foreign = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_id' => $foreign->id,
            'title' => 'X',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image_file_id');
    }

    public function test_portfolio_is_capped(): void
    {
        [$user, $profile, $headers] = $this->approvedAgent();
        AgentPortfolioItem::factory()->count(AgentPortfolioItem::MAX_PER_PROFILE)->for($profile)->create();
        $image = File::factory()->create(['uploaded_by' => $user->id]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_id' => $image->id,
            'title' => 'One too many',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('portfolio');
    }

    public function test_owner_can_update_and_delete_but_not_others(): void
    {
        [, $profile, $headers] = $this->approvedAgent();
        $item = AgentPortfolioItem::factory()->for($profile)->create();
        $foreignItem = AgentPortfolioItem::factory()->create();

        $this->patchJson("/api/v1/agent/portfolio/{$item->id}", ['title' => 'Yangi nom'], $headers)
            ->assertOk()
            ->assertJsonPath('data.title', 'Yangi nom');

        $this->patchJson("/api/v1/agent/portfolio/{$foreignItem->id}", ['title' => 'Hack'], $headers)
            ->assertNotFound();

        $this->deleteJson("/api/v1/agent/portfolio/{$item->id}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('agent_portfolio_items', ['id' => $item->id]);
    }

    public function test_agent_can_add_portfolio_with_multiple_images_and_attachments(): void
    {
        [$user, , $headers] = $this->approvedAgent();
        $image1 = File::factory()->create(['uploaded_by' => $user->id]);
        $image2 = File::factory()->create(['uploaded_by' => $user->id]);
        $pdf = File::factory()->create([
            'uploaded_by' => $user->id,
            'mime_type' => 'application/pdf',
            'original_name' => 'brief.pdf',
        ]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_ids' => [$image1->id, $image2->id],
            'attachment_file_ids' => [$pdf->id],
            'title' => 'Chilonzor LED',
            'description' => '3x6 banner',
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.image', $image1->url())
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.id', $image1->id)
            ->assertJsonPath('data.images.1.id', $image2->id)
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.original_name', 'brief.pdf');

        $this->assertDatabaseHas('agent_portfolio_item_images', [
            'file_id' => $image1->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('agent_portfolio_item_attachments', [
            'file_id' => $pdf->id,
            'sort_order' => 0,
        ]);
    }

    public function test_owner_can_replace_portfolio_images_and_attachments(): void
    {
        [$user, $profile, $headers] = $this->approvedAgent();
        $item = AgentPortfolioItem::factory()->for($profile)->create();
        $newImage = File::factory()->create(['uploaded_by' => $user->id]);
        $newPdf = File::factory()->create([
            'uploaded_by' => $user->id,
            'mime_type' => 'application/pdf',
        ]);

        $this->patchJson("/api/v1/agent/portfolio/{$item->id}", [
            'image_file_ids' => [$newImage->id],
            'attachment_file_ids' => [$newPdf->id],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.image', $newImage->url())
            ->assertJsonCount(1, 'data.images')
            ->assertJsonCount(1, 'data.attachments');
    }

    public function test_cannot_attach_someone_elses_files(): void
    {
        [$user, , $headers] = $this->approvedAgent();
        $ownImage = File::factory()->create(['uploaded_by' => $user->id]);
        $foreign = File::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->postJson('/api/v1/agent/portfolio', [
            'image_file_ids' => [$ownImage->id],
            'attachment_file_ids' => [$foreign->id],
            'title' => 'X',
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment_file_ids.0');
    }

    public function test_admin_takedown_hides_item_from_public_profile(): void
    {
        [, $profile, $headers] = $this->approvedAgent();
        $item = AgentPortfolioItem::factory()->for($profile)->create();

        $admin = User::factory()->admin()->create();
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        // The guard caches the resolved user per app instance — reset between identities.
        $this->app->make('auth')->forgetGuards();

        $this->patchJson("/api/v1/admin/portfolio-items/{$item->id}/visibility", ['hidden' => true], $adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.is_hidden', true);

        // Public profile omits the hidden item…
        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.portfolio');

        // …while the owner still sees it, flagged.
        $this->app->make('auth')->forgetGuards();
        $this->getJson('/api/v1/agent/portfolio', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.is_hidden', true);

        // Restore brings it back.
        $this->app->make('auth')->forgetGuards();
        $this->patchJson("/api/v1/admin/portfolio-items/{$item->id}/visibility", ['hidden' => false], $adminHeaders)
            ->assertOk();
        $this->getJson("/api/v1/agents/{$profile->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.portfolio');
    }
}
