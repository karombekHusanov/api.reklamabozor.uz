<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\ApiController;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\DirectChatMessageResource;
use App\Models\DirectChat;
use App\Services\Admin\ChatAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Client ↔ agent chat history for operators (read-only).
 */
class ChatController extends ApiController
{
    public function __construct(
        private readonly ChatAdminService $chats,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(ChatAdminService::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'order_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->chats->list([
            'type' => $validated['type'] ?? 'direct',
            'search' => $validated['search'] ?? null,
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'order_id' => isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ]);

        return $this->success([
            'items' => collect($paginator->items())->map($this->chats->summary(...))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(string $type, int $id): JsonResponse
    {
        abort_unless(in_array($type, ChatAdminService::TYPES, true), 404);

        $chat = $this->chats->find($type, $id);
        $messages = $chat instanceof DirectChat
            ? DirectChatMessageResource::collection($chat->messages)
            : ChatMessageResource::collection($chat->messages);

        return $this->success([
            'chat' => $this->chats->summary($chat),
            'messages' => $messages,
        ]);
    }
}
