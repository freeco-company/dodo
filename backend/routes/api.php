<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AiMealController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AppleNotificationController;
use App\Http\Controllers\Api\AppleSignInController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\DailyLogController;
use App\Http\Controllers\Api\EcpayCallbackController;
use App\Http\Controllers\Api\EntitlementsController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\FranchiseController;
use App\Http\Controllers\Api\GooglePubSubController;
use App\Http\Controllers\Api\GrowthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IapController;
use App\Http\Controllers\Api\FranchiseWebhookController;
use App\Http\Controllers\Api\IdentityWebhookController;
use App\Http\Controllers\Api\InteractController;
use App\Http\Controllers\Api\IslandController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\LineSignInController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\MePreferencesController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\OutfitController;
use App\Http\Controllers\Api\PaywallController;
use App\Http\Controllers\Api\PokedexController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Controllers\Api\RatingPromptController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\ShieldController;
use App\Http\Controllers\Api\SuggestController;
use App\Http\Controllers\Api\TierController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| 朵朵 Dodo API Routes — translated from ../ai-game/src/app.ts
*/

Route::get('/health', HealthController::class);

// ----- Public -----
// Pre-launch security: credential stuffing rate limits.
//   /auth/login → 5/min IP + per-(email,IP) `login` named limiter
//   /auth/register → 10/hour IP (registration is heavier and rarer)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,60');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware(['throttle:5,1', 'throttle:login']);
    // OAuth sign-in: identity_token / id_token are signature-verified server-side
    // (AppleIdTokenVerifier hits Apple JWKS; LineIdTokenVerifier hits LINE verify
    // endpoint). Throttle is looser than /login because OAuth tokens are not
    // password-guessable, but tight enough to blunt scripted abuse.
    Route::post('/apple', [AppleSignInController::class, 'signin'])
        ->middleware('throttle:10,60');
    Route::post('/line', [LineSignInController::class, 'signin'])
        ->middleware('throttle:10,60');
});

Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:30,1');
// 婕樂纖 → 朵朵 ecommerce/order webhook (HMAC-SHA256 signed; see middleware)
Route::post('/webhooks/ecommerce/order', [WebhookController::class, 'ecommerceOrder'])
    ->middleware('ecommerce.webhook');

// ----- Phase E: IAP server-to-server webhooks (signature handled in controller) -----
Route::post('/iap/apple/notifications', AppleNotificationController::class);
Route::post('/iap/google/pubsub', GooglePubSubController::class);

// ----- Phase E: ECPay server-to-server callbacks (signature handled in client) -----
Route::post('/ecpay/notify', [EcpayCallbackController::class, 'notify']);
Route::post('/ecpay/return', [EcpayCallbackController::class, 'returnUrl']);

// /api/bootstrap — sanctum optional. Use the unauthenticated route; the
// controller resolves the user via the Sanctum bearer token on a best-effort
// basis (anon callers still get app_config without entitlements).
Route::get('/bootstrap', BootstrapController::class)->middleware('throttle:60,1');

// ----- Admin (X-Admin-Token) -----
Route::middleware('admin.token')->prefix('admin')->group(function () {
    Route::post('/account/purge-expired', [AccountController::class, 'purge']);
    Route::post('/analytics/flush', [AnalyticsController::class, 'flush']);
    Route::get('/seo', [SeoController::class, 'index']);
    Route::put('/seo', [SeoController::class, 'upsert']);
    Route::post('/tier', [TierController::class, 'adminSet']);
});

// ----- Authenticated (sanctum) -----
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/daily-logs', [DailyLogController::class, 'index']);
    Route::post('/daily-logs', [DailyLogController::class, 'store']);
    Route::get('/daily-logs/{date}', [DailyLogController::class, 'show'])
        ->where('date', '\d{4}-\d{2}-\d{2}');

    Route::get('/meals', [MealController::class, 'index']);
    Route::post('/meals', [MealController::class, 'store']);
    Route::get('/meals/{meal}', [MealController::class, 'show']);
    Route::put('/meals/{meal}/correct', [MealController::class, 'correct']);
    Route::delete('/meals/{meal}', [MealController::class, 'destroy']);

    // ----- SPEC-fasting-timer Phase 1 -----
    Route::post('/fasting/start', [\App\Http\Controllers\Api\FastingController::class, 'start']);
    Route::post('/fasting/end', [\App\Http\Controllers\Api\FastingController::class, 'end']);
    Route::get('/fasting/current', [\App\Http\Controllers\Api\FastingController::class, 'current']);
    Route::get('/fasting/history', [\App\Http\Controllers\Api\FastingController::class, 'history']);

    // ----- ADR-008 alignment: current-user-scoped (no uid in path) -----
    Route::get('/me/dashboard', [MeController::class, 'dashboard']);
    Route::get('/me/settings', [MeController::class, 'getSettings']);
    Route::patch('/me/settings', [MeController::class, 'patchSettings']);
    // 個資法 §10 right-to-access. Heavy endpoint — throttle to 3/hour.
    Route::get('/me/data-export', [MeController::class, 'dataExport'])
        ->middleware('throttle:3,60');
    Route::get('/me/growth/timeseries', [GrowthController::class, 'timeseries']);
    Route::get('/me/growth/weekly-review', [GrowthController::class, 'weeklyReview']);

    // Phase 5 — knowledge base (營養知識庫) — App-side reads
    Route::get('/knowledge', [KnowledgeController::class, 'index']);
    Route::get('/knowledge/daily', [KnowledgeController::class, 'daily']);
    Route::get('/knowledge/saved', [KnowledgeController::class, 'saved']);
    Route::get('/knowledge/categories', [KnowledgeController::class, 'categories']);
    Route::get('/knowledge/{slug}', [KnowledgeController::class, 'show']);
    Route::post('/knowledge/{slug}/save', [KnowledgeController::class, 'save']);

    Route::get('/paywall', [PaywallController::class, 'view']);
    Route::get('/rating-prompt', [RatingPromptController::class, 'view']);

    Route::get('/pokedex', [PokedexController::class, 'index']);
    Route::get('/pokedex/{food_id}', [PokedexController::class, 'show'])->whereNumber('food_id');
    Route::get('/achievements', [AchievementController::class, 'index']);
    Route::get('/entitlements', [EntitlementsController::class, 'show']);

    Route::get('/calendar', [CalendarController::class, 'index']);
    Route::get('/reports/weekly/{date}', [ReportController::class, 'weekly'])
        ->where('date', '\d{4}-\d{2}-\d{2}');
    Route::get('/suggest/next-meal', [SuggestController::class, 'nextMeal']);

    Route::get('/outfits', [OutfitController::class, 'index']);
    Route::post('/outfits/equip', [OutfitController::class, 'equip']);

    Route::prefix('island')->group(function () {
        Route::get('/scenes', [IslandController::class, 'scenes']);
        Route::get('/chapters', [IslandController::class, 'chapters']);
        Route::get('/store/{scene}', [IslandController::class, 'store'])
            ->where('scene', '[a-z0-9_-]+');
        Route::post('/consume-visit', [IslandController::class, 'consumeVisit']);
    });

    Route::get('/journey/milestone/{day}', [JourneyController::class, 'milestone'])
        ->where('day', '\d+');

    // /cards/event-offer/next must be declared BEFORE /cards/event-offer/{offer_id}
    // because the existing eventOffer route uses {offer_id:\d+} and `next` is
    // not numeric — so route order/regex naturally disambiguates. Still, keep
    // an explicit declaration in case the regex changes.
    Route::get('/cards/event-offer/next', [CardController::class, 'eventOfferNext']);

    // ----- Batch A: gamification -----
    Route::prefix('checkin')->group(function () {
        Route::post('/water', [CheckinController::class, 'logWater']);
        Route::post('/water/set', [CheckinController::class, 'setWater']);
        Route::post('/exercise', [CheckinController::class, 'logExercise']);
        Route::post('/exercise/set', [CheckinController::class, 'setExercise']);
        Route::post('/weight', [CheckinController::class, 'logWeight']);
        Route::get('/goals', [CheckinController::class, 'goals']);
    });

    Route::get('/journey', [JourneyController::class, 'show']);
    Route::post('/journey/advance', [JourneyController::class, 'advance']);

    Route::prefix('interact')->group(function () {
        Route::post('/pet', [InteractController::class, 'pet']);
        Route::post('/gift', [InteractController::class, 'gift']);
        Route::get('/gift/status', [InteractController::class, 'giftStatus']);
    });

    Route::prefix('shield')->group(function () {
        Route::post('/refill', [ShieldController::class, 'refill']);
        Route::post('/use', [ShieldController::class, 'use']);
    });

    Route::prefix('cards')->group(function () {
        Route::post('/draw', [CardController::class, 'draw']);
        Route::post('/answer', [CardController::class, 'answer']);
        Route::get('/stamina', [CardController::class, 'stamina']);
        Route::get('/collection', [CardController::class, 'collection']);

        // Event card offers (NPC-pushed, time-limited)
        Route::get('/event-offer/{offer_id}', [CardController::class, 'eventOffer'])
            ->where('offer_id', '\d+');
        Route::post('/event-draw', [CardController::class, 'eventDraw']);
        Route::post('/event-skip', [CardController::class, 'eventSkip']);

        // Scene cards (island hotspot, location-based)
        Route::post('/scene-draw', [CardController::class, 'sceneDraw']);
    });

    // Food database lookup (typeahead in meal logging UI)
    Route::get('/foods/search', [FoodController::class, 'search']);

    Route::get('/quests/today', [QuestController::class, 'today']);

    Route::prefix('meta')->group(function () {
        Route::get('/limits', [MetaController::class, 'limits']);
        Route::get('/outfits', [MetaController::class, 'outfits']);
    });
    Route::get('/lore/spirits', [MetaController::class, 'spirits']);

    // ----- Batch C: misc -----
    // ADR-003 §2.3 加盟漏斗 CTA 事件
    Route::post('/franchise/cta-view', [FranchiseController::class, 'ctaView']);
    Route::post('/franchise/cta-click', [FranchiseController::class, 'ctaClick']);

    // ADR-008 UX sensitivity — 使用者主動關閉 / 重新開啟 franchise CTA
    Route::post('/me/franchise-cta-silence', [MePreferencesController::class, 'franchiseCtaSilence']);

    Route::post('/referrals/redeem', [ReferralController::class, 'redeem']);
    Route::get('/referrals/me', [ReferralController::class, 'me']);
    Route::post('/paywall/event', [PaywallController::class, 'event']);
    Route::post('/account/delete-request', [AccountController::class, 'deleteRequest']);
    Route::post('/account/restore', [AccountController::class, 'restore']);
    Route::post('/rating-prompt/event', [RatingPromptController::class, 'event']);
    Route::post('/analytics/track', [AnalyticsController::class, 'track']);
    Route::post('/push/register', [PushController::class, 'register']);
    Route::post('/push/unregister', [PushController::class, 'unregister']);

    // ----- Batch D: subscription / monetization -----
    Route::post('/tier/redeem', [TierController::class, 'redeem']);
    Route::post('/subscribe/mock', [TierController::class, 'mockSubscribe']);

    // ----- Phase E: real IAP receipt verify (auth'd; called by RN App after purchase) -----
    Route::post('/iap/verify', [IapController::class, 'verify'])
        ->middleware('throttle:20,1');
    // Apple §3.1.1 — Restore Purchases (Capacitor IAP plugin → batch verify)
    Route::post('/iap/restore', [IapController::class, 'restore'])
        ->middleware('throttle:10,1');

    // ----- Batch E: AI (stub 503 until Python service is wired) -----
    Route::post('/meals/scan', [AiMealController::class, 'scan']);
    Route::post('/meals/text', [AiMealController::class, 'text']);
    Route::post('/chat/message', [ChatController::class, 'message']);
    Route::get('/chat/starters', [ChatController::class, 'starters']);
});

// ADR-007 Phase 4 — platform → 朵朵 identity webhook（HMAC + nonce 由 middleware 驗）
Route::post('/internal/identity/webhook', IdentityWebhookController::class)
    ->middleware('identity.webhook');

// ADR-009 Phase B.2 — py-service → 朵朵 gamification webhook
// (HMAC + event_id idempotency 由 middleware 驗)
Route::post('/internal/gamification/webhook', [\App\Http\Controllers\Api\GamificationWebhookController::class, 'handle'])
    ->middleware('gamification.webhook');

// PG-93 — py-service → 潘朵拉飲食 lifecycle cache invalidate
// (HMAC + nonce idempotency 由 middleware 驗；route 後直接呼叫 LifecycleClient::forget)
Route::post('/internal/lifecycle/invalidate', [\App\Http\Controllers\Api\LifecycleInvalidateController::class, 'handle'])
    ->middleware('lifecycle.invalidate');

// 母艦 (pandora-js-store) → 朵朵 加盟身份同步 webhook
// franchisee.activated → User/DodoUser is_franchisee=true; franchisee.deactivated → false
Route::post('/internal/franchisee/webhook', FranchiseWebhookController::class)
    ->middleware('franchisee.webhook');

// SPEC-photo-ai-calorie-polish §4.2 — ai-service (FastAPI) → 朵朵 callback receivers
// Auth via X-Internal-Secret (shared with services.meal_ai_service.shared_secret).
// Best-effort logging endpoints; ai-service treats failure as non-fatal.
Route::post('/internal/ai-callback/food-recognition',
    [\App\Http\Controllers\Api\Internal\AiCallbackController::class, 'foodRecognition']);
Route::post('/internal/ai-callback/cost-event',
    [\App\Http\Controllers\Api\Internal\AiCallbackController::class, 'costEvent']);
