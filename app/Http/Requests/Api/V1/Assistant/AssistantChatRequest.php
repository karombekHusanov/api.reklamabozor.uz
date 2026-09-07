<?php

namespace App\Http\Requests\Api\V1\Assistant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The client sends the whole visible conversation; caps here bound both the
 * provider bill and the blast radius of anything pasted into the chat.
 */
class AssistantChatRequest extends FormRequest
{
    /** Turns kept from the client's history (older ones are dropped). */
    public const MAX_MESSAGES = 12;

    public const MAX_CHARS = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:'.self::MAX_CHARS],
        ];
    }

    /**
     * Only role/content survive — anything else the client sends is discarded
     * before it can reach the provider.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(): array
    {
        $messages = collect($this->validated('messages'))
            ->map(fn (array $message): array => [
                'role' => $message['role'],
                'content' => trim($message['content']),
            ])
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values();

        // The provider expects the exchange to end on the user's turn.
        if ($messages->isEmpty() || $messages->last()['role'] !== 'user') {
            return $messages->all();
        }

        return $messages->all();
    }
}
