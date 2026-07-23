<?php

use App\Http\Controllers\Api\V1\Admin\AdvantageController as AdminAdvantageController;
use App\Http\Controllers\Api\V1\Admin\AgentProfileController as AdminAgentProfileController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\GlobalChatController as AdminGlobalChatController;
use App\Http\Controllers\Api\V1\Admin\LegalEntityController as AdminLegalEntityController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\PortfolioModerationController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AdvantageController;
use App\Http\Controllers\Api\V1\Agent\AgentOrderController;
use App\Http\Controllers\Api\V1\Agent\AgentPortfolioController;
use App\Http\Controllers\Api\V1\Agent\AgentProfileController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Chat\ChatController;
use App\Http\Controllers\Api\V1\Chat\DirectChatController;
use App\Http\Controllers\Api\V1\Chat\GlobalChatController;
use App\Http\Controllers\Api\V1\Designer\DesignerProfileController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LegalEntityController;
use App\Http\Controllers\Api\V1\Order\OfferController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Payment\MulticardCallbackController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicAgentController;
use App\Http\Controllers\Api\V1\PublicBannerController;
use App\Http\Controllers\Api\V1\PublicClientController;
use App\Http\Controllers\Api\V1\PublicOrderController;
use App\Http\Controllers\Api\V1\Review\ReviewController;
use App\Http\Controllers\Api\V1\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Telegram bot webhook — called by Telegram servers, guarded by the secret-token header.
Route::post('/telegram/webhook', WebhookController::class);

// Multicard payment webhook — called by Multicard, guarded by source-IP allowlist + SHA1 sign.
Route::post('/payment/multicard/callback', MulticardCallbackController::class);

// Public marketplace listing of approved agents (home slider / browse).
Route::get('/agents', [PublicAgentController::class, 'index']);
// Nearest agents to a point — declared before the {agentProfile} route so
// "nearby" is not captured as a model-bound id.
Route::get('/agents/nearby', [PublicAgentController::class, 'nearby']);
Route::get('/agents/{agentProfile}', [PublicAgentController::class, 'show']);

// Public banners for the mini app home slider.
Route::get('/banners', [PublicBannerController::class, 'index']);

// Public "live orders" showcase for the home carousel (social proof). Declared
// before the auth group so it wins over the client-only /orders/{order} route.
Route::get('/orders/showcase', [PublicOrderController::class, 'showcase']);

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
    Route::patch('/me/person-type', [ProfileController::class, 'setPersonType']);
    Route::get('/me/legal-entity', [LegalEntityController::class, 'show']);
    Route::post('/me/legal-entity', [LegalEntityController::class, 'store']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/clients/{user}', [PublicClientController::class, 'show'])->whereNumber('user');

    // B2C client orders + selecting a winning offer.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept']);
    // Completion handshake: client accepts or rejects the delivered work.
    Route::post('/orders/{order}/complete', [OrderController::class, 'confirmCompletion']);
    Route::post('/orders/{order}/dispute', [OrderController::class, 'dispute']);

    // Order payment (Multicard hosted checkout): (re)start checkout + poll status.
    Route::post('/orders/{order}/pay', [PaymentController::class, 'pay']);
    Route::get('/orders/{order}/payment', [PaymentController::class, 'show']);

    // Community-wide global chat, open to every authenticated user.
    Route::get('/chat/global', [GlobalChatController::class, 'meta']);
    Route::get('/chat/global/messages', [GlobalChatController::class, 'messages']);
    // Tight flood guard on top of the cooldown rules.
    Route::post('/chat/global/messages', [GlobalChatController::class, 'store'])
        ->middleware('throttle:20,1');

    // Per-order client ↔ agent conversation (opened when an offer is accepted).
    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/orders/{order}/chat', [ChatController::class, 'show']);
    Route::get('/orders/{order}/chat/messages', [ChatController::class, 'messages']);
    Route::post('/orders/{order}/chat/messages', [ChatController::class, 'store']);

    // Direct client ↔ agency chat (opened from an agent profile, no order required).
    Route::post('/agents/{agentProfile}/direct-chat', [DirectChatController::class, 'open']);

    // Advantages catalog (active) — providers pick from it in the profile editor.
    Route::get('/advantages', [AdvantageController::class, 'index']);

    // Designer profile: minimal form, no KYC, approved instantly.
    Route::post('/designer/profile', [DesignerProfileController::class, 'store']);

    // Provider portfolio ("qilgan ishlarimiz") — approved profiles only.
    Route::get('/agent/portfolio', [AgentPortfolioController::class, 'index']);
    Route::post('/agent/portfolio', [AgentPortfolioController::class, 'store']);
    Route::patch('/agent/portfolio/{portfolioItem}', [AgentPortfolioController::class, 'update']);
    Route::delete('/agent/portfolio/{portfolioItem}', [AgentPortfolioController::class, 'destroy']);
    Route::get('/direct-chats/{directChat}', [DirectChatController::class, 'show']);
    Route::get('/direct-chats/{directChat}/messages', [DirectChatController::class, 'messages']);
    Route::post('/direct-chats/{directChat}/messages', [DirectChatController::class, 'store']);

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

        // Advantages catalog CRUD + portfolio takedown.
        Route::get('/advantages', [AdminAdvantageController::class, 'index']);
        Route::post('/advantages', [AdminAdvantageController::class, 'store']);
        Route::patch('/advantages/{advantage}', [AdminAdvantageController::class, 'update']);
        Route::delete('/advantages/{advantage}', [AdminAdvantageController::class, 'destroy']);
        Route::get('/agents/{agentProfile}/portfolio', [PortfolioModerationController::class, 'index']);
        Route::patch('/portfolio-items/{portfolioItem}/visibility', [PortfolioModerationController::class, 'setVisibility']);

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::get('/orders/{order}/chat', [AdminOrderController::class, 'chat']);

        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show']);

        // Agent payouts out of escrow — manager reviews + releases (marks paid).
        Route::get('/payouts', [AdminPayoutController::class, 'index']);
        Route::patch('/payouts/{payout}/release', [AdminPayoutController::class, 'release']);

        Route::get('/reviews', [AdminReviewController::class, 'index']);
        Route::patch('/reviews/{review}/status', [AdminReviewController::class, 'updateStatus']);

        Route::get('/legal-entity-verifications', [AdminLegalEntityController::class, 'index']);
        Route::patch('/legal-entity-verifications/{legalEntityVerification}/status', [AdminLegalEntityController::class, 'updateStatus']);

        // Global chat moderation: feed, rules, bans, settings + pinned announcement.
        Route::get('/global-chat/messages', [AdminGlobalChatController::class, 'messages']);
        Route::delete('/global-chat/messages/{message}', [AdminGlobalChatController::class, 'deleteMessage'])
            ->whereNumber('message');
        Route::get('/global-chat/rules', [AdminGlobalChatController::class, 'rules']);
        Route::put('/global-chat/rules/roles', [AdminGlobalChatController::class, 'updateRoleRules']);
        Route::post('/global-chat/rules/users', [AdminGlobalChatController::class, 'setUserRule']);
        Route::delete('/global-chat/rules/users/{userId}', [AdminGlobalChatController::class, 'removeUserRule'])
            ->whereNumber('userId');
        Route::get('/global-chat/bans', [AdminGlobalChatController::class, 'bans']);
        Route::post('/global-chat/bans', [AdminGlobalChatController::class, 'storeBan']);
        Route::delete('/global-chat/bans/{ban}', [AdminGlobalChatController::class, 'destroyBan'])
            ->whereNumber('ban');
        Route::get('/global-chat/settings', [AdminGlobalChatController::class, 'settings']);
        Route::put('/global-chat/settings', [AdminGlobalChatController::class, 'updateSettings']);

        Route::get('/banners', [AdminBannerController::class, 'index']);
        Route::post('/banners', [AdminBannerController::class, 'store']);
        Route::get('/banners/{banner}', [AdminBannerController::class, 'show']);
        Route::patch('/banners/{banner}', [AdminBannerController::class, 'update']);
        Route::patch('/banners/{banner}/active', [AdminBannerController::class, 'toggleActive']);
        Route::delete('/banners/{banner}', [AdminBannerController::class, 'destroy']);
    });
