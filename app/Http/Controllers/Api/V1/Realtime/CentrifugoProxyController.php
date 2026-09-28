<?php

namespace App\Http\Controllers\Api\V1\Realtime;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Chat\StoreChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\DirectChatMessageResource;
use App\Models\DirectChat;
use App\Models\Order;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Chat\DirectChatService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Centrifugo RPC proxy: the mini app sends chat actions over its WebSocket
 * (`centrifuge.rpc(method, data)`), Centrifugo forwards them here with the
 * authenticated user id from the connection token. Same services as the HTTP
 * endpoints, so validation, the agent image ban and notifications are shared;
 * the services then push the result to both participants' `user:{id}` channels.
 *
 * Protocol: always HTTP 200 with `{result: {data}}` or `{error: {code, message}}`.
 */
class CentrifugoProxyController extends Controller
{
    private const SEND_PER_MINUTE = 60;

    public function __construct(
        private readonly ChatService $orderChats,
        private readonly DirectChatService $directChats,
    ) {}

    public function rpc(Request $request): JsonResponse
    {
        $user = User::query()->whereKey((int) $request->input('user'))->where('is_active', true)->first();
        if ($user === null) {
            return $this->error(401, 'Unauthorized');
        }

        $data = (array) $request->input('data', []);

        try {
            return match ((string) $request->input('method')) {
                'chat.send' => $this->send($user, $data),
                'chat.read' => $this->read($user, $data),
                default => $this->error(404, 'Unknown method'),
            };
        } catch (ValidationException $e) {
            return $this->error(422, (string) collect($e->errors())->flatten()->first());
        } catch (ModelNotFoundException) {
            return $this->error(404, 'Not found');
        } catch (HttpExceptionInterface $e) {
            return $this->error($e->getStatusCode(), $e->getMessage() ?: 'Request failed');
        } catch (Throwable $e) {
            report($e);

            return $this->error(500, 'Server error');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function send(User $user, array $data): JsonResponse
    {
        $key = 'chat-rpc-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::SEND_PER_MINUTE)) {
            return $this->error(429, 'Too many messages. Try again in a moment.');
        }
        RateLimiter::hit($key);

        $target = $this->target($data);
        $valid = Validator::make($data, (new StoreChatMessageRequest)->rules())->validate();
        $body = $valid['body'] ?? null;
        $fileIds = array_map(intval(...), $valid['file_ids'] ?? []);

        $message = $target instanceof DirectChat
            ? new DirectChatMessageResource($this->directChats->send($user, $target, $body, $fileIds))
            : new ChatMessageResource($this->orderChats->send($user, $target, $body, $fileIds));

        return $this->result(['message' => $message->resolve()]);
    }

    /** @param  array<string, mixed>  $data */
    private function read(User $user, array $data): JsonResponse
    {
        $target = $this->target($data);

        if ($target instanceof DirectChat) {
            $this->directChats->markRead($user, $target);
        } else {
            $this->orderChats->markRead($user, $this->orderChats->forOrder($user, $target));
        }

        return $this->result(['ok' => true]);
    }

    /**
     * `{type: 'direct', id: <direct chat id>}` or `{type: 'order', id: <order id>}`
     * — the same ids the HTTP routes use.
     *
     * @param  array<string, mixed>  $data
     */
    private function target(array $data): DirectChat|Order
    {
        $valid = Validator::make($data, [
            'type' => ['required', 'in:direct,order'],
            'id' => ['required', 'integer', 'min:1'],
        ])->validate();

        return $valid['type'] === 'direct'
            ? DirectChat::query()->findOrFail($valid['id'])
            : Order::query()->findOrFail($valid['id']);
    }

    /** @param  array<string, mixed>  $data */
    private function result(array $data): JsonResponse
    {
        return response()->json(['result' => ['data' => $data]]);
    }

    private function error(int $code, string $message): JsonResponse
    {
        // Centrifugo reserves codes below 400 for itself.
        return response()->json(['error' => ['code' => max(400, $code), 'message' => $message]]);
    }
}
