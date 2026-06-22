<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\V1\Auth\AdminLoginRequest;
use App\Http\Requests\Api\V1\Auth\TelegramLoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $authService) {}

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

    public function adminLogin(AdminLoginRequest $request): JsonResponse
    {
        $result = $this->authService->adminLogin(
            $request->validated('email'),
            $request->validated('password'),
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
