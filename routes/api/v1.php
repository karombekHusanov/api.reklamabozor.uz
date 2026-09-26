<?php

use App\Http\Controllers\Api\V1\Admin\AdvantageController as AdminAdvantageController;
use App\Http\Controllers\Api\V1\Admin\AgentProfileController as AdminAgentProfileController;
use App\Http\Controllers\Api\V1\Admin\AmendmentController as AdminAmendmentController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Api\V1\Admin\GlobalChatController as AdminGlobalChatController;
use App\Http\Controllers\Api\V1\Admin\HashtagController as AdminHashtagController;
use App\Http\Controllers\Api\V1\Admin\LegalEntityController as AdminLegalEntityController;
use App\Http\Controllers\Api\V1\Admin\MxikCodeController as AdminMxikCodeController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\OrderProblemController as AdminOrderProblemController;
use App\Http\Controllers\Api\V1\Admin\OrdersByRouteReportController as AdminOrdersByRouteReportController;
use App\Http\Controllers\Api\V1\Admin\PassController as AdminPassController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PayoutController as AdminPayoutController;
use App\Http\Controllers\Api\V1\Admin\PortfolioModerationController;
use App\Http\Controllers\Api\V1\Admin\RatingController as AdminRatingController;
use App\Http\Controllers\Api\V1\Admin\RegionController as AdminRegionController;
use App\Http\Controllers\Api\V1\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\TenderAccessController as AdminTenderAccessController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AdvantageController;
use App\Http\Controllers\Api\V1\Agent\AgentOrderController;
use App\Http\Controllers\Api\V1\Agent\AgentPortfolioController;
use App\Http\Controllers\Api\V1\Agent\AgentProfileController;
use App\Http\Controllers\Api\V1\Agent\PassController as AgentPassController;
use App\Http\Controllers\Api\V1\Agent\PayoutController as AgentPayoutController;
use App\Http\Controllers\Api\V1\Assistant\AssistantController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Chat\ChatController;
use App\Http\Controllers\Api\V1\Chat\DirectChatController;
use App\Http\Controllers\Api\V1\Chat\GlobalChatController;
use App\Http\Controllers\Api\V1\Designer\DesignerProfileController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\HashtagController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Legal\PublicOfferController;
use App\Http\Controllers\Api\V1\LegalEntityController;
use App\Http\Controllers\Api\V1\Order\OfferController;
use App\Http\Controllers\Api\V1\Order\OrderAmendmentController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Order\OrderDocumentController;
use App\Http\Controllers\Api\V1\Payment\GatewayCallbackController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\PlatformContactController;
use App\Http\Controllers\Api\V1\Profile\ActivityController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicAgentController;
use App\Http\Controllers\Api\V1\PublicBannerController;
use App\Http\Controllers\Api\V1\PublicClientController;
use App\Http\Controllers\Api\V1\PublicOrderController;
use App\Http\Controllers\Api\V1\Rating\RatingController;
use App\Http\Controllers\Api\V1\RegionController;
use App\Http\Controllers\Api\V1\Review\ReviewController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\Telegram\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/platform-contact', PlatformContactController::class);
Route::get('/legal/public-offer', [PublicOfferController::class, 'show']);
Route::get('/legal/public-offer.pdf', [PublicOfferController::class, 'pdf']);
Route::get('/legal/agent-offer', [PublicOfferController::class, 'agentShow']);
Route::get('/legal/agent-offer.pdf', [PublicOfferController::class, 'agentPdf']);

// Telegram bot webhook — called by Telegram servers, guarded by the secret-token header.
Route::post('/telegram/webhook', WebhookController::class);

// Online payment gateway (Propusk / wallet top-up): provider callback (idempotent)
// and the dev-only fake checkout completion.
Route::post('/payments/gateway/callback', [GatewayCallbackController::class, 'callback'])
    ->middleware('throttle:120,1');
Route::post('/payments/atmos/callback', [GatewayCallbackController::class, 'atmosBilling'])
    ->middleware('throttle:120,1');
Route::match(['get', 'post'], '/payments/fake/{ref}/complete', [GatewayCallbackController::class, 'fakeComplete'])
    ->middleware('throttle:30,1');

// Public marketplace listing of approved agents (home slider / browse).
Route::get('/agents', [PublicAgentController::class, 'index']);
// Nearest agents to a point — declared before the {agentProfile} route so
// "nearby" is not captured as a model-bound id.
Route::get('/agents/nearby', [PublicAgentController::class, 'nearby']);
Route::get('/agents/{agentProfile}', [PublicAgentController::class, 'show']);

// Public banners for the mini app home slider.
Route::get('/banners', [PublicBannerController::class, 'index']);
// Impression / click tracking — public (guests count too), lightly throttled.
Route::post('/banners/{banner}/view', [PublicBannerController::class, 'view'])
    ->middleware('throttle:120,1');
Route::post('/banners/{banner}/click', [PublicBannerController::class, 'click'])
    ->middleware('throttle:120,1');

// Public "live orders" showcase for the home carousel (social proof). Declared
// before the auth group so it wins over the client-only /orders/{order} route.
Route::get('/orders/showcase', [PublicOrderController::class, 'showcase']);

// Public hashtag suggest (active catalog) for order wizard autocomplete / filters.
Route::get('/hashtags', [HashtagController::class, 'index']);

// Public "live pulse" stats for the home JONLI marquee (online counts, etc.).
Route::get('/stats/live', [StatsController::class, 'live'])->middleware('throttle:120,1');

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
    // Showcase detail — full order view for authenticated users (providers see
    // can_offer + my_offer). Placed before /orders/{order} to avoid conflict.
    Route::get('/orders/showcase/{order}', [PublicOrderController::class, 'show']);

    Route::post('/file-upload', [FileUploadController::class, 'store']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::patch('/me/person-type', [ProfileController::class, 'setPersonType']);
    Route::post('/me/accept-terms', [ProfileController::class, 'acceptTerms']);
    Route::get('/me/legal-entity', [LegalEntityController::class, 'show']);
    Route::post('/me/legal-entity', [LegalEntityController::class, 'store']);

    Route::get('/me/rating', [RatingController::class, 'me']);
    Route::get('/me/activity', [ActivityController::class, 'show']);
    Route::post('/me/activity/live-orders/seen', [ActivityController::class, 'markLiveOrdersSeen']);

    // AI assistant — explains the app and drafts an order. Tight throttle:
    // every call costs a provider request.
    Route::post('/assistant/chat', [AssistantController::class, 'chat'])
        ->middleware('throttle:10,1');
    // Same turn, streamed (SSE) — the chat's default path.
    Route::post('/assistant/chat/stream', [AssistantController::class, 'stream'])
        ->middleware('throttle:10,1');

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/regions', [RegionController::class, 'index']);
    Route::get('/clients/{user}', [PublicClientController::class, 'show'])->whereNumber('user');

    // B2C client orders + selecting a winning offer.
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    // Acts closing the order (work + commission) — client, agent or manager.
    Route::get('/orders/{order}/documents', [OrderDocumentController::class, 'index']);

    // Three-party contract the client confirms in the accept drawer.
    Route::get('/offers/{offer}/contract-preview', [OfferController::class, 'contractPreview']);
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept']);
    Route::post('/offers/{offer}/chat', [OfferController::class, 'openChat'])
        ->middleware('throttle:20,1');
    // Completion handshake: client accepts or rejects the delivered work.
    Route::post('/orders/{order}/complete', [OrderController::class, 'confirmCompletion']);
    Route::post('/orders/{order}/dispute', [OrderController::class, 'dispute']);
    // Client cancels their own order — open for offers, or unpaid checkout.
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    // Tezkor: client reopens the request (drops the claim) or closes it as agreed.
    Route::post('/orders/{order}/release', [OrderController::class, 'release']);
    Route::post('/orders/{order}/close', [OrderController::class, 'close']);
    // Client reports an agent who took the advance but never started —
    // flags the order into the admin problem-orders queue.
    Route::post('/orders/{order}/report-no-start', [OrderController::class, 'reportNoStart']);

    // Order payment: an offline invoice (cash / bank transfer) a manager
    // confirms (or Kapitalbank auto-reconciliation matches for bank transfer).
    Route::post('/orders/{order}/pay/offline', [PaymentController::class, 'payOffline']);
    Route::get('/orders/{order}/payment', [PaymentController::class, 'show']);
    Route::get('/orders/{order}/payments', [PaymentController::class, 'index']);

    // Additional agreements (Qo'shimcha kelishuv): either party proposes a change
    // to an active deal; client, agent, and (when flagged) operator must approve.
    Route::get('/orders/{order}/amendments', [OrderAmendmentController::class, 'index']);
    // Addendum text: from draft rows (before sending) and from a stored one.
    Route::post('/orders/{order}/amendments/preview', [OrderAmendmentController::class, 'preview'])
        ->middleware('throttle:30,1');
    Route::get('/amendments/{amendment}/document', [OrderAmendmentController::class, 'document']);
    Route::post('/orders/{order}/amendments', [OrderAmendmentController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::post('/amendments/{amendment}/approve', [OrderAmendmentController::class, 'approve']);
    Route::post('/amendments/{amendment}/reject', [OrderAmendmentController::class, 'reject']);
    Route::post('/amendments/{amendment}/cancel', [OrderAmendmentController::class, 'cancel']);

    // Community-wide global chat, open to every authenticated user.
    Route::get('/chat/global', [GlobalChatController::class, 'meta']);
    Route::get('/chat/global/unread', [GlobalChatController::class, 'unread']);
    Route::post('/chat/global/read', [GlobalChatController::class, 'markSeen']);
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
    Route::get('/direct-chats/{directChat}', [DirectChatController::class, 'show']);
    Route::get('/direct-chats/{directChat}/messages', [DirectChatController::class, 'messages']);
    Route::post('/direct-chats/{directChat}/messages', [DirectChatController::class, 'store']);
    Route::post('/direct-chats/{directChat}/block', [DirectChatController::class, 'block']);
    Route::delete('/direct-chats/{directChat}/block', [DirectChatController::class, 'unblock']);

    // Advantages catalog (active) — providers pick from it in the profile editor.
    Route::get('/advantages', [AdvantageController::class, 'index']);

    // Designer profile: minimal form, no KYC, approved instantly.
    Route::post('/designer/profile', [DesignerProfileController::class, 'store']);

    // Provider portfolio ("qilgan ishlarimiz") — approved profiles only.
    Route::get('/agent/portfolio', [AgentPortfolioController::class, 'index']);
    Route::post('/agent/portfolio', [AgentPortfolioController::class, 'store']);
    Route::patch('/agent/portfolio/{portfolioItem}', [AgentPortfolioController::class, 'update']);
    Route::delete('/agent/portfolio/{portfolioItem}', [AgentPortfolioController::class, 'destroy']);

    // Client rates the agency once the order is completed (moderated).
    Route::post('/orders/{order}/review', [ReviewController::class, 'store']);
    Route::get('/orders/{order}/reviews', [ReviewController::class, 'index']);

    // Rating criteria definitions per role (public list for the review form).
    Route::get('/review-criteria', [ReviewController::class, 'criteria']);

    Route::prefix('agent')->group(function (): void {
        Route::get('/profile', [AgentProfileController::class, 'show']);
        Route::post('/profile', [AgentProfileController::class, 'store']);
        Route::put('/profile', [AgentProfileController::class, 'update']);
        Route::patch('/profile', [AgentProfileController::class, 'updateDetails']);
        // Upload the signed (wet-signature + stamp) platform agreement scan.
        Route::post('/profile/contract', [AgentProfileController::class, 'uploadSignedContract']);
        // (Re-)accept the current agency partnership offer.
        Route::post('/profile/accept-offer', [AgentProfileController::class, 'acceptOffer']);

        // Order opportunities + the agent's offers.
        Route::get('/orders', [AgentOrderController::class, 'index']);
        Route::get('/orders/{order}', [AgentOrderController::class, 'showOrder']);
        Route::post('/orders/{order}/offers', [AgentOrderController::class, 'storeOffer']);
        Route::post('/orders/{order}/submit-work', [AgentOrderController::class, 'submitWork']);
        // Tezkor: agent lets go of their claim, or closes it as agreed.
        Route::post('/orders/{order}/release', [AgentOrderController::class, 'release']);
        Route::post('/orders/{order}/close', [AgentOrderController::class, 'close']);
        Route::post('/orders/{order}/review', [AgentOrderController::class, 'storeReview']);
        Route::get('/offers', [AgentOrderController::class, 'myOffers']);
        Route::get('/offers/{offer}', [AgentOrderController::class, 'showOffer']);
        Route::post('/offers/{offer}/chat', [AgentOrderController::class, 'openOfferChat'])
            ->middleware('throttle:20,1');
        Route::post('/offers/{offer}/withdraw', [AgentOrderController::class, 'withdrawOffer'])
            ->middleware('throttle:20,1');
        Route::patch('/offers/{offer}', [AgentOrderController::class, 'updateOffer'])
            ->middleware('throttle:10,1');
        // Contract built from the draft pricelist, shown before the agent sends it.
        Route::post('/offers/{offer}/contract-preview', [AgentOrderController::class, 'previewContract'])
            ->middleware('throttle:30,1');
        Route::put('/offers/{offer}/pricelist', [AgentOrderController::class, 'setPricelist'])
            ->middleware('throttle:20,1');

        // Earnings: escrow payouts owed/paid + withdrawable balance.
        Route::get('/payouts', [AgentPayoutController::class, 'index']);

        // Propusk (daily pass) + dormant wallet.
        Route::get('/pass', [AgentPassController::class, 'show']);
        Route::post('/pass/purchase', [AgentPassController::class, 'purchase'])->middleware('throttle:10,1');
        // In-app card form (ATMOS merchant API): card → SMS code → confirm.
        Route::post('/pass/card', [AgentPassController::class, 'cardStart'])->middleware('throttle:6,1');
        Route::post('/pass/card/{reference}/confirm', [AgentPassController::class, 'cardConfirm'])
            ->whereUuid('reference')->middleware('throttle:10,1');
        Route::get('/pass/history', [AgentPassController::class, 'history']);
        Route::get('/wallet', [AgentPassController::class, 'wallet']);
        Route::post('/wallet/topup', [AgentPassController::class, 'topup'])->middleware('throttle:10,1');
        // In-app card form top-up (confirm via /pass/card/{reference}/confirm).
        Route::post('/wallet/card', [AgentPassController::class, 'walletCardStart'])->middleware('throttle:6,1');
        // Cards bound at the provider (token) — pay with `card_id`, no card/SMS.
        Route::get('/cards', [AgentPassController::class, 'cards']);
        Route::delete('/cards/{card}', [AgentPassController::class, 'deleteCard'])->whereNumber('card');
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
        // Manager-granted Tender permission (needs a verified legal entity).
        Route::post('/users/{user}/tender-access', [AdminTenderAccessController::class, 'grant']);
        Route::delete('/users/{user}/tender-access', [AdminTenderAccessController::class, 'revoke']);

        // Propusk: manual grant + wallet (adjust works even while the wallet is off).
        Route::post('/users/{user}/pass', [AdminPassController::class, 'grant']);
        Route::get('/users/{user}/wallet', [AdminPassController::class, 'wallet']);
        Route::post('/users/{user}/wallet/adjust', [AdminPassController::class, 'adjustWallet']);
        Route::get('/passes', [AdminPassController::class, 'index']);
        Route::get('/passes/summary', [AdminPassController::class, 'summary']);
        Route::get('/passes/transactions', [AdminPassController::class, 'transactions']);
        Route::get('/passes/settings', [AdminPassController::class, 'settings']);
        Route::put('/passes/settings', [AdminPassController::class, 'updateSettings']);

        Route::get('/categories', [AdminCategoryController::class, 'index']);
        Route::post('/categories', [AdminCategoryController::class, 'store']);
        Route::get('/categories/{category}', [AdminCategoryController::class, 'show']);
        Route::patch('/categories/{category}', [AdminCategoryController::class, 'update']);
        Route::patch('/categories/{category}/active', [AdminCategoryController::class, 'toggleActive']);
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy']);

        Route::get('/regions', [AdminRegionController::class, 'index']);
        Route::post('/regions', [AdminRegionController::class, 'store']);
        Route::get('/regions/{region}', [AdminRegionController::class, 'show']);
        Route::patch('/regions/{region}', [AdminRegionController::class, 'update']);
        Route::patch('/regions/{region}/active', [AdminRegionController::class, 'toggleActive']);
        Route::delete('/regions/{region}', [AdminRegionController::class, 'destroy']);

        Route::get('/agents', [AdminAgentProfileController::class, 'index']);
        Route::post('/agents', [AdminAgentProfileController::class, 'store']);
        Route::get('/agents/{agentProfile}', [AdminAgentProfileController::class, 'show']);
        Route::patch('/agents/{agentProfile}/status', [AdminAgentProfileController::class, 'updateStatus']);
        Route::post('/agents/{agentProfile}/contract/reject', [AdminAgentProfileController::class, 'rejectContract']);

        // Advantages catalog CRUD + portfolio takedown.
        Route::get('/advantages', [AdminAdvantageController::class, 'index']);
        Route::post('/advantages', [AdminAdvantageController::class, 'store']);
        Route::patch('/advantages/{advantage}', [AdminAdvantageController::class, 'update']);
        Route::delete('/advantages/{advantage}', [AdminAdvantageController::class, 'destroy']);

        // Order hashtag catalog CRUD + merge.
        Route::get('/hashtags', [AdminHashtagController::class, 'index']);
        Route::post('/hashtags', [AdminHashtagController::class, 'store']);
        Route::patch('/hashtags/{hashtag}', [AdminHashtagController::class, 'update']);
        Route::post('/hashtags/{hashtag}/merge', [AdminHashtagController::class, 'merge']);
        Route::delete('/hashtags/{hashtag}', [AdminHashtagController::class, 'destroy']);

        Route::get('/agents/{agentProfile}/portfolio', [PortfolioModerationController::class, 'index']);
        Route::patch('/portfolio-items/{portfolioItem}/visibility', [PortfolioModerationController::class, 'setVisibility']);

        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        Route::get('/orders/{order}/chat', [AdminOrderController::class, 'chat']);

        // Problem orders: an unresolved quality dispute past its correction
        // window, or an agent who never started a paid deal — a manager
        // records a manual refund or dismisses the report.
        Route::get('/order-problems', [AdminOrderProblemController::class, 'index']);
        Route::get('/order-problems/{order}', [AdminOrderProblemController::class, 'show']);
        Route::post('/order-problems/{order}/refund', [AdminOrderProblemController::class, 'refund']);
        Route::post('/order-problems/{order}/dismiss', [AdminOrderProblemController::class, 'dismiss']);

        // MXIK (IKPU) classifier catalogue — fiscal codes for pricelist rows.
        Route::get('/mxik-codes', [AdminMxikCodeController::class, 'index']);
        Route::get('/mxik-codes/coverage', [AdminMxikCodeController::class, 'coverage']);
        Route::post('/mxik-codes', [AdminMxikCodeController::class, 'store']);
        Route::patch('/mxik-codes/{mxikCode}', [AdminMxikCodeController::class, 'update']);
        Route::delete('/mxik-codes/{mxikCode}', [AdminMxikCodeController::class, 'destroy']);

        // Finance reporting: period totals + the two registers as CSV.
        Route::get('/finance/summary', [AdminFinanceController::class, 'summary']);
        Route::get('/finance/payments.csv', [AdminFinanceController::class, 'paymentsRegister']);
        Route::get('/finance/payouts.csv', [AdminFinanceController::class, 'payoutsRegister']);
        Route::get('/finance/gateway-payments.csv', [AdminFinanceController::class, 'gatewayPaymentsRegister']);

        // Per-account Tender vs Tezkor order counts (commission-leakage view).
        Route::get('/reports/orders-by-route', AdminOrdersByRouteReportController::class);

        Route::get('/payments', [AdminPaymentController::class, 'index']);
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show']);
        Route::post('/payments/{payment}/refund', [AdminPaymentController::class, 'refund']);
        // Offline money (cash desk / bank statement) — manager settles it here.
        Route::post('/payments/{payment}/confirm', [AdminPaymentController::class, 'confirm']);
        Route::post('/payments/{payment}/reject', [AdminPaymentController::class, 'reject']);

        // Operator review of additional agreements (Qo'shimcha kelishuv).
        Route::get('/amendments', [AdminAmendmentController::class, 'index']);
        Route::get('/amendments/{amendment}', [AdminAmendmentController::class, 'show']);
        Route::post('/amendments/{amendment}/approve', [AdminAmendmentController::class, 'approve']);
        Route::post('/amendments/{amendment}/reject', [AdminAmendmentController::class, 'reject']);
        // Money the operator hands back (or writes off) for an applied addendum.
        Route::post('/amendments/{amendment}/refund', [AdminAmendmentController::class, 'refund']);
        Route::post('/amendments/{amendment}/waive-refund', [AdminAmendmentController::class, 'waiveRefund']);
        Route::post('/amendments/{amendment}/expire', [AdminAmendmentController::class, 'expire']);

        // Agent payouts out of escrow — manager reviews + releases (marks paid).
        Route::get('/payouts', [AdminPayoutController::class, 'index']);
        Route::patch('/payouts/{payout}/release', [AdminPayoutController::class, 'release']);

        Route::get('/users/{user}/rating', [AdminRatingController::class, 'show']);

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
