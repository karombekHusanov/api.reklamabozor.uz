<?php

namespace App\Http\Controllers\Api\V1\Assistant;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Assistant\AssistantChatRequest;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantController extends ApiController
{
    public function __construct(private readonly AssistantService $assistant) {}

    /**
     * One assistant turn. The provider key stays on the server; the mini app
     * only ever talks to this endpoint.
     */
    public function chat(AssistantChatRequest $request): JsonResponse
    {
        if (! config('services.assistant.enabled')) {
            return $this->error('Assistant is not available yet.', 503);
        }

        try {
            $result = $this->assistant->reply($request->user(), $request->history());
        } catch (ValidationException $e) {
            // Daily cap reached — a 422 the client can show, not a provider error.
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return $this->error('Yordamchi hozir javob bera olmadi. Birozdan so\'ng urinib ko\'ring.', 502);
        }

        return $this->success([
            'reply' => $result['reply'],
            'draft' => $result['draft'],
        ]);
    }

    /**
     * The same turn as chat(), streamed as server-sent events so the first
     * words appear in about a second. Frames: `delta` (text), then exactly one
     * of `done` (reply + validated draft) or `error`.
     */
    public function stream(AssistantChatRequest $request): StreamedResponse|JsonResponse
    {
        if (! config('services.assistant.enabled')) {
            return $this->error('Assistant is not available yet.', 503);
        }

        $user = $request->user();
        $history = $request->history();

        return response()->stream(function () use ($user, $history): void {
            try {
                $result = $this->assistant->streamReply(
                    $user,
                    $history,
                    fn (string $chunk) => $this->emit('delta', ['text' => $chunk]),
                );

                $this->emit('done', ['reply' => $result['reply'], 'draft' => $result['draft']]);
            } catch (ValidationException $e) {
                $this->emit('error', ['message' => $e->validator->errors()->first()]);
            } catch (Throwable $e) {
                report($e);
                $this->emit('error', ['message' => 'Yordamchi hozir javob bera olmadi. Birozdan so\'ng urinib ko\'ring.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // Nginx buffers proxied responses by default, which would defeat
            // the whole point of streaming.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** One SSE frame, flushed immediately. */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
