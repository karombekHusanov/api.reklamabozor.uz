<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Auth\AdminLoginRequest;
use App\Http\Requests\Api\V1\Auth\TelegramLoginRequest;
use App\Http\Requests\Api\V1\Auth\TelegramNativePollRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\TelegramNativeLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly TelegramNativeLoginService $nativeLogin,
    ) {}

    public function telegramLogin(TelegramLoginRequest $request): JsonResponse
    {
        $result = $this->authService->telegramLogin($request->validated());

        return $this->success(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
            message: $result['created'] ? 'Registration successful' : 'Login successful',
            status: $result['created'] ? 201 : 200,
        );
    }

    /**
     * Mobile app: open a Telegram login request (bot deep link + poll secret).
     */
    public function telegramNativeStart(Request $request): JsonResponse
    {
        return $this->success($this->nativeLogin->start($request->ip()), status: 201);
    }

    /**
     * Mobile app: poll a login request. 202 while waiting in Telegram, 200 with
     * the token once confirmed (redeemable once), 410 when expired/cancelled.
     */
    public function telegramNativePoll(TelegramNativePollRequest $request): JsonResponse
    {
        $result = $this->nativeLogin->poll($request->validated('code'), $request->validated('poll_secret'));

        return match ($result['status']) {
            TelegramNativeLoginService::STATUS_PENDING => $this->success(['status' => 'pending'], 'Waiting for confirmation in Telegram', 202),
            TelegramNativeLoginService::STATUS_CONFIRMED => $this->success([
                'status' => 'confirmed',
                'user' => new UserResource($result['user']->load('avatarFile')),
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ], 'Login successful'),
            TelegramNativeLoginService::STATUS_CANCELLED => $this->error('Login was cancelled in Telegram.', 410, ['code' => ['cancelled']]),
            default => $this->error('Login request expired. Start again.', 410, ['code' => ['expired']]),
        };
    }

    public function adminLogin(AdminLoginRequest $request): JsonResponse
    {
        $result = $this->authService->adminLogin(
            $request->validated('email'),
            $request->validated('password'),
            $request->ip(),
        );

        return $this->success([
            'user' => new UserResource($result['user']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(message: 'Logged out successfully');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(new UserResource($request->user()->load('avatarFile')));
    }
}
