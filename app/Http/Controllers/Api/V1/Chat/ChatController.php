<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Chat\StoreChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ChatResource;
use App\Models\Order;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends ApiController
{
    public function __construct(
        private readonly ChatService $chats,
    ) {}

    /**
     * All conversations the authenticated user takes part in.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->success(ChatResource::collection($this->chats->listForUser($request->user())));
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
