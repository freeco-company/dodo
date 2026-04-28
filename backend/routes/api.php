<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiMealController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\DailyLogController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IdentityWebhookController;
use App\Http\Controllers\Api\InteractController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\PaywallController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Controllers\Api\RatingPromptController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\ShieldController;
use App\Http\Controllers\Api\TierController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| 朵朵 Dodo API Routes — translated from ../ai-game/src/app.ts
*/

Route::get('/health', HealthController::class);

// ----- Public -----
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:30,1');
Route::post('/webhooks/ecommerce/order', [WebhookController::class, 'ecommerceOrder']);

// /api/bootstrap — sanctum optional. Use the unauthenticated route; the
// controller resolves the user via the Sanctum bearer token on a best-effort
// basis (anon callers still get app_config without entitlements).
Route::get('/bootstrap', BootstrapController::class);

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
    Route::delete('/meals/{meal}', [MealController::class, 'destroy']);

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
    });

    Route::get('/quests/today', [QuestController::class, 'today']);

    Route::prefix('meta')->group(function () {
        Route::get('/limits', [MetaController::class, 'limits']);
        Route::get('/outfits', [MetaController::class, 'outfits']);
    });
    Route::get('/lore/spirits', [MetaController::class, 'spirits']);

    // ----- Batch C: misc -----
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

    // ----- Batch E: AI (stub 503 until Python service is wired) -----
    Route::post('/meals/scan', [AiMealController::class, 'scan']);
    Route::post('/meals/text', [AiMealController::class, 'text']);
    Route::post('/chat/message', [ChatController::class, 'message']);
    Route::get('/chat/starters', [ChatController::class, 'starters']);
});

// ADR-007 Phase 4 — platform → 朵朵 identity webhook（HMAC + nonce 由 middleware 驗）
Route::post('/internal/identity/webhook', IdentityWebhookController::class)
    ->middleware('identity.webhook');
