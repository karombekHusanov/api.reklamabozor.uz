<?php

namespace App\Http\Controllers\Api\V1\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, TelegramWebhookHandler $handler): JsonResponse
    {
        $expected = config('services.telegram.webhook_secret');
        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($expected) || ! is_string($provided) || ! hash_equals((string) $expected, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        $handler->handle($request->all());

        // Always 200 so Telegram does not retry the update.
        return response()->json(['ok' => true]);
    }
}
