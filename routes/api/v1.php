<?php

use App\Http\Controllers\Api\V1\Admin\AgentProfileController as AdminAgentProfileController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Agent\AgentOrderController;
use App\Http\Controllers\Api\V1\Agent\AgentProfileController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Chat\ChatController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Order\OfferController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicAgentController;
use App\Http\Controllers\Api\V1\PublicBannerController;
use App\Http\Controllers\Api\V1\Review\ReviewController;
use App\Http\Controllers\Api\V1\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Telegram bot webhook — called by Telegram servers, guarded by the secret-token header.
Route::post('/telegram/webhook', WebhookController::class);

// Public marketplace listing of approved agents (home slider / browse).
Route::get('/agents', [PublicAgentController::class, 'index']);
// Nearest agents to a point — declared before the {agentProfile} route so
// "nearby" is not captured as a model-bound id.
Route::get('/agents/nearby', [PublicAgentController::class, 'nearby']);
Route::get('/agents/{agentProfile}', [PublicAgentController::class, 'show']);

// Public banners for the mini app home slider.
Route::get('/banners', [PublicBannerController::class, 'index']);

Route::prefix('auth')->group(function (): void {
    Route::post('/telegram', [AuthController::class, 'telegramLogin']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Authenticated mini app surface (any logged-in user).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/file-upload', [FileUploadController::class, 'store']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::patch('/me/role', [ProfileController::class, 'setRole']);

    Route::get('/categories', [CategoryController::class, 'index']);

    // B2C client orders + selecting a winning offer.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept']);
    // Completion handshake: client accepts or rejects the delivered work.
    Route::post('/orders/{order}/complete', [OrderController::class, 'confirmCompletion']);
    Route::post('/orders/{order}/dispute', [OrderController::class, 'dispute']);

    // Per-order client ↔ agent conversation (opened when an offer is accepted).
    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/orders/{order}/chat', [ChatController::class, 'show']);
    Route::get('/orders/{order}/chat/messages', [ChatController::class, 'messages']);
    Route::post('/orders/{order}/chat/messages', [ChatController::class, 'store']);

    // Client rates the agency once the order is completed (moderated).
    Route::post('/orders/{order}/review', [ReviewController::class, 'store']);

    Route::prefix('agent')->group(function (): void {
        Route::get('/profile', [AgentProfileController::class, 'show']);
        Route::post('/profile', [AgentProfileController::class, 'store']);
        Route::put('/profile', [AgentProfileController::class, 'update']);
        Route::patch('/profile', [AgentProfileController::class, 'updateDetails']);

        // Order opportunities + the agent's offers.
        Route::get('/orders', [AgentOrderController::class, 'index']);
        Route::post('/orders/{order}/offers', [AgentOrderController::class, 'storeOffer']);
        Route::post('/orders/{order}/submit-work', [AgentOrderController::class, 'submitWork']);
        Route::get('/offers', [AgentOrderController::class, 'myOffers']);
    });
});

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('/analytics', [AdminAnalyticsController::class, 'index']);
        Route::get('/analytics/activity', [AdminAnalyticsController::class, 'activity']);

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/active', [UserController::class, 'toggleActive']);

        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{category}', [AdminCategoryController::class, 'show']);
        Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::patch('/categories/{category}/active', [AdminCategoryController::class, 'toggleActive']);
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

        Route::get('/agents', [AdminAgentProfileController::class, 'index']);
        Route::post('/agents', [AdminAgentProfileController::class, 'store']);
        Route::get('/agents/{agentProfile}', [AdminAgentProfileController::class, 'show']);
        Route::patch('/agents/{agentProfile}/status', [AdminAgentProfileController::class, 'updateStatus']);

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::get('/orders/{order}/chat', [AdminOrderController::class, 'chat']);

        Route::get('/reviews', [AdminReviewController::class, 'index']);
        Route::patch('/reviews/{review}/status', [AdminReviewController::class, 'updateStatus']);

        Route::get('/banners', [AdminBannerController::class, 'index']);
        Route::post('/banners', [AdminBannerController::class, 'store']);
        Route::get('/banners/{banner}', [AdminBannerController::class, 'show']);
        Route::patch('/banners/{banner}', [AdminBannerController::class, 'update']);
        Route::patch('/banners/{banner}/active', [AdminBannerController::class, 'toggleActive']);
        Route::delete('/banners/{banner}', [AdminBannerController::class, 'destroy']);
    });
