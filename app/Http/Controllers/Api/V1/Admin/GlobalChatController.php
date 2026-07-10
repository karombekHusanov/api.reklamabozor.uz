<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Role;
use App\Http\Controllers\ApiController;
use App\Http\Resources\GlobalChatMessageResource;
use App\Models\GlobalChatBan;
use App\Models\GlobalChatMessage;
use App\Models\GlobalChatSetting;
use App\Services\GlobalChat\GlobalChatAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GlobalChatController extends ApiController
{
    private const MODERATABLE_ROLES = [Role::Client, Role::Agent, Role::Designer, Role::Seller];

    public function __construct(
        private readonly GlobalChatAdminService $chat,
    ) {}

    public function messages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'include_deleted' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->chat->messages([
            'search' => $validated['search'] ?? null,
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'include_deleted' => (bool) ($validated['include_deleted'] ?? false),
            'per_page' => $validated['per_page'] ?? 30,
        ]);

        return $this->success([
            'items' => GlobalChatMessageResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function deleteMessage(Request $request, GlobalChatMessage $message): JsonResponse
    {
        $deleted = $this->chat->deleteMessage($message, $request->user());

        return $this->success(new GlobalChatMessageResource($deleted), 'Message removed');
    }

    public function rules(): JsonResponse
    {
        return $this->success($this->chat->rules());
    }

    /** Role-level cooldowns; 0 seconds removes the rule for that role. */
    public function updateRoleRules(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array'],
            'rules.*.role' => ['required', Rule::in(array_map(fn (Role $r) => $r->value, self::MODERATABLE_ROLES))],
            'rules.*.cooldown_seconds' => ['required', 'integer', 'min:0', 'max:604800'],
        ]);

        $this->chat->setRoleRules($validated['rules']);

        return $this->success($this->chat->rules(), 'Rules updated');
    }

    /** Per-user cooldown override — beats the user's role rule. */
    public function setUserRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'cooldown_seconds' => ['required', 'integer', 'min:1', 'max:604800'],
        ]);

        $rule = $this->chat->setUserRule((int) $validated['user_id'], (int) $validated['cooldown_seconds']);

        return $this->success($rule, 'User rule saved');
    }

    public function removeUserRule(int $userId): JsonResponse
    {
        $this->chat->removeUserRule($userId);

        return $this->success(null, 'User rule removed');
    }

    public function bans(): JsonResponse
    {
        $paginator = $this->chat->bans();

        return $this->success([
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function storeBan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // null = permanent; otherwise hours (1h, 24h, 168h presets in the UI).
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $ban = $this->chat->ban(
            (int) $validated['user_id'],
            $request->user(),
            $validated['reason'] ?? null,
            isset($validated['duration_hours']) ? (int) $validated['duration_hours'] : null,
        );

        return $this->success($ban, 'User blocked', 201);
    }

    public function destroyBan(GlobalChatBan $ban): JsonResponse
    {
        $this->chat->unban($ban);

        return $this->success(null, 'User unblocked');
    }

    public function settings(): JsonResponse
    {
        return $this->success(GlobalChatSetting::current());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'max_message_length' => ['sometimes', 'integer', 'min:10', 'max:4000'],
            'pinned_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $settings = $this->chat->updateSettings($validated, $request->user());

        return $this->success($settings, 'Settings updated');
    }
}
