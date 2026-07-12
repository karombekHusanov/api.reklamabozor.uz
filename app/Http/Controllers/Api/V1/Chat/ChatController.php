<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Chat\StoreChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatResource;
use App\Http\Resources\DirectChatResource;
use App\Models\Order;
use App\Services\Chat\ChatService;
use App\Services\Chat\DirectChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends ApiController
{
    public function __construct(
        private readonly ChatService $chats,
        private readonly DirectChatService $directChats,
    ) {}

    /**
     * All conversations the authenticated user takes part in.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
        ]);

        $agentProfileId = isset($validated['agent_profile_id']) ? (int) $validated['agent_profile_id'] : null;

        $orderChats = $this->chats->listForUser($request->user(), $agentProfileId);
        $directChats = $this->directChats->listForUser($request->user(), $agentProfileId);

        $items = collect()
            ->concat(ChatResource::collection($orderChats)->resolve($request))
            ->concat(DirectChatResource::collection($directChats)->resolve($request))
            ->sortByDesc(fn (array $item) => $item['updated_at'])
            ->values();

        return $this->success($items);
    }

    /**
     * The order's conversation with its full message history.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $chat = $this->chats->forOrder($request->user(), $order);
        $messages = $this->chats->messages($request->user(), $order);

        return $this->success([
            'chat' => new ChatResource($chat),
            'messages' => ChatMessageResource::collection($messages),
        ]);
    }

    /**
     * Poll for new messages (optionally after a known message id).
     */
    public function messages(Request $request, Order $order): JsonResponse
    {
        $after = $request->query('after');
        $messages = $this->chats->messages(
            $request->user(),
            $order,
            $after !== null ? (int) $after : null,
        );

        return $this->success(ChatMessageResource::collection($messages));
    }

    public function store(StoreChatMessageRequest $request, Order $order): JsonResponse
    {
        $message = $this->chats->send(
            $request->user(),
            $order,
            $request->validated('body'),
            array_map(intval(...), $request->validated('file_ids') ?? []),
        );

        return $this->success(new ChatMessageResource($message), 'Message sent', 201);
    }
}
