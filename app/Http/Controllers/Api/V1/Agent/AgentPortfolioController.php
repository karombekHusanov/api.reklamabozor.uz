<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentProfileStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Agent\StorePortfolioItemRequest;
use App\Http\Requests\Api\V1\Agent\UpdatePortfolioItemRequest;
use App\Http\Resources\AgentPortfolioItemResource;
use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use App\Services\Agent\PortfolioItemFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Provider-side portfolio management ("qilgan ishlarimiz"). Items publish
 * immediately; admins can take one down, which the owner sees as is_hidden.
 */
class AgentPortfolioController extends ApiController
{
    private const PORTFOLIO_RELATIONS = ['imageFile', 'imageFiles', 'attachmentFiles'];

    /**
     * The caller's own portfolio, hidden items included (flagged).
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->approvedProfileOf($request);

        return $this->success(
            AgentPortfolioItemResource::collection(
                $profile->portfolioItems()->with(self::PORTFOLIO_RELATIONS)->get(),
            ),
        );
    }

    public function store(StorePortfolioItemRequest $request): JsonResponse
    {
        $profile = $this->approvedProfileOf($request);

        if ($profile->portfolioItems()->count() >= AgentPortfolioItem::MAX_PER_PROFILE) {
            throw ValidationException::withMessages([
                'portfolio' => ['Portfolio is full (max '.AgentPortfolioItem::MAX_PER_PROFILE.' items).'],
            ]);
        }

        $validated = $request->validated();
        [$imageFileIds, $attachmentFileIds] = $this->extractFileIds($validated);

        $item = $profile->portfolioItems()->create([
            ...$validated,
            'image_file_id' => $imageFileIds[0],
            'sort_order' => ((int) $profile->portfolioItems()->max('sort_order')) + 1,
        ]);

        PortfolioItemFiles::syncImages($item, $imageFileIds, $request->user());
        PortfolioItemFiles::syncAttachments($item, $attachmentFileIds, $request->user());

        return $this->success(
            new AgentPortfolioItemResource($item->load(self::PORTFOLIO_RELATIONS)),
            'Portfolio item added',
            201,
        );
    }

    public function update(UpdatePortfolioItemRequest $request, AgentPortfolioItem $portfolioItem): JsonResponse
    {
        $this->assertOwn($request, $portfolioItem);

        $validated = $request->validated();
        [$imageFileIds, $attachmentFileIds, $hasImages, $hasAttachments] = $this->extractFileIdsForUpdate($validated);

        if ($hasImages) {
            $validated['image_file_id'] = $imageFileIds[0];
        }

        $portfolioItem->update($validated);

        if ($hasImages) {
            PortfolioItemFiles::syncImages($portfolioItem, $imageFileIds, $request->user());
        }

        if ($hasAttachments) {
            PortfolioItemFiles::syncAttachments($portfolioItem, $attachmentFileIds, $request->user());
        }

        return $this->success(
            new AgentPortfolioItemResource($portfolioItem->load(self::PORTFOLIO_RELATIONS)),
            'Portfolio item updated',
        );
    }

    public function destroy(Request $request, AgentPortfolioItem $portfolioItem): JsonResponse
    {
        $this->assertOwn($request, $portfolioItem);

        $portfolioItem->delete();

        return $this->success(null, 'Portfolio item removed');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: list<int>, 1: list<int>}
     */
    private function extractFileIds(array &$validated): array
    {
        $imageFileIds = $validated['image_file_ids']
            ?? (isset($validated['image_file_id']) ? [(int) $validated['image_file_id']] : []);
        $attachmentFileIds = array_map(intval(...), $validated['attachment_file_ids'] ?? []);

        unset($validated['image_file_ids'], $validated['attachment_file_ids']);

        return [$imageFileIds, $attachmentFileIds];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: list<int>, 1: list<int>, 2: bool, 3: bool}
     */
    private function extractFileIdsForUpdate(array &$validated): array
    {
        $hasImages = array_key_exists('image_file_ids', $validated) || array_key_exists('image_file_id', $validated);
        $hasAttachments = array_key_exists('attachment_file_ids', $validated);

        $imageFileIds = [];
        $attachmentFileIds = [];

        if ($hasImages) {
            $imageFileIds = $validated['image_file_ids']
                ?? (isset($validated['image_file_id']) ? [(int) $validated['image_file_id']] : []);
        }

        if ($hasAttachments) {
            $attachmentFileIds = array_map(intval(...), $validated['attachment_file_ids'] ?? []);
        }

        unset($validated['image_file_ids'], $validated['attachment_file_ids']);

        return [$imageFileIds, $attachmentFileIds, $hasImages, $hasAttachments];
    }

    private function approvedProfileOf(Request $request): AgentProfile
    {
        $profile = AgentProfile::query()->where('user_id', $request->user()->id)->first();

        abort_if($profile === null || $profile->status !== AgentProfileStatus::Approved, 403);

        return $profile;
    }

    private function assertOwn(Request $request, AgentPortfolioItem $item): void
    {
        $profile = $this->approvedProfileOf($request);

        abort_if($item->agent_profile_id !== $profile->id, 404);
    }
}
