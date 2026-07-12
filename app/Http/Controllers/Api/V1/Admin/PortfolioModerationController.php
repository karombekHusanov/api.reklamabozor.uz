<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Resources\AgentPortfolioItemResource;
use App\Models\AgentPortfolioItem;
use App\Models\AgentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portfolio takedown: items auto-publish, admins hide (or restore) the odd
 * bad apple. Hiding keeps the row so the provider still sees it flagged.
 */
class PortfolioModerationController extends ApiController
{
    /**
     * All portfolio items of one agency, hidden included.
     */
    public function index(AgentProfile $agentProfile): JsonResponse
    {
        return $this->success(
            AgentPortfolioItemResource::collection(
                $agentProfile->portfolioItems()->with(['imageFile', 'imageFiles', 'attachmentFiles'])->get(),
            ),
        );
    }

    public function setVisibility(Request $request, AgentPortfolioItem $portfolioItem): JsonResponse
    {
        $validated = $request->validate([
            'hidden' => ['required', 'boolean'],
        ]);

        $portfolioItem->update($validated['hidden']
            ? ['hidden_at' => now(), 'hidden_by' => $request->user()->id]
            : ['hidden_at' => null, 'hidden_by' => null]);

        return $this->success(
            new AgentPortfolioItemResource($portfolioItem->load(['imageFile', 'imageFiles', 'attachmentFiles'])),
            $validated['hidden'] ? 'Portfolio item hidden' : 'Portfolio item restored',
        );
    }
}
