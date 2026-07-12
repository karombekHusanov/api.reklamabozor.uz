<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Chat\StoreChatMessageRequest;
use App\Http\Resources\DirectChatMessageResource;
use App\Http\Resources\DirectChatResource;
use App\Models\AgentProfile;
use App\Models\DirectChat;
use App\Services\Chat\DirectChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectChatController extends ApiController
{
    public function __construct(
        private readonly DirectChatService $chats,
    ) {}

    /**
     * Open (or return) a direct client ↔ agency conversation from the agent profile.
     */
    public function open(Request $request, AgentProfile $agentProfile): JsonResponse
    {
        $chat = $this->chats->open($request->user(), $agentProfile);

        return $this->success(new DirectChatResource($chat->load(['client', 'agent.agentProfile', 'lastMessage.attachments'])));
    }

    public function show(Request $request, DirectChat $directChat): JsonResponse
    {
        $chat = $this->chats->forChat($request->user(), $directChat);
        $messages = $this->chats->messages($request->user(), $directChat);

        return $this->success([
            'chat' => new DirectChatResource($chat),
            'messages' => DirectChatMessageResource::collection($messages),
        ]);
    }

    public function messages(Request $request, DirectChat $directChat): JsonResponse
    {
        $after = $request->query('after');
        $messages = $this->chats->messages(
            $request->user(),
            $directChat,
            $after !== null ? (int) $after : null,
        );

        return $this->success(DirectChatMessageResource::collection($messages));
    }

    public function store(StoreChatMessageRequest $request, DirectChat $directChat): JsonResponse
    {
        $message = $this->chats->send(
            $request->user(),
            $directChat,
            $request->validated('body'),
            array_map(intval(...), $request->validated('file_ids') ?? []),
        );

        return $this->success(new DirectChatMessageResource($message), 'Message sent', 201);
    }
}
