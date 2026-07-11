<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\ApiController;
use App\Http\Resources\GlobalChatMessageResource;
use App\Services\Chat\MessageAttachments;
use App\Services\GlobalChat\GlobalChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalChatController extends ApiController
{
    public function __construct(
        private readonly GlobalChatService $chat,
    ) {}

    /**
     * Chat state for the composer: enabled flag, pinned announcement,
     * the caller's ban / cooldown status.
     */
    public function meta(Request $request): JsonResponse
    {
        return $this->success($this->chat->meta($request->user()));
    }

    /**
     * Message feed. `after_id` = polling for new messages,
     * `before_id` = loading older history.
     */
    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $messages = $this->chat->messages(
            isset($validated['after_id']) ? (int) $validated['after_id'] : null,
            isset($validated['before_id']) ? (int) $validated['before_id'] : null,
        );

        return $this->success(GlobalChatMessageResource::collection($messages));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'required_without:file_ids'],
            'file_ids' => ['nullable', 'array', 'max:'.MessageAttachments::MAX_PER_MESSAGE, 'required_without:body'],
            'file_ids.*' => ['integer', 'exists:files,id'],
        ]);

        $message = $this->chat->post(
            $request->user(),
            $validated['body'] ?? null,
            array_map(intval(...), $validated['file_ids'] ?? []),
        );

        return $this->success(new GlobalChatMessageResource($message), 'Message sent', 201);
    }
}
