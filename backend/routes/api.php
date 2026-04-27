<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyLogController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MealController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 朵朵 Dodo API Routes
|--------------------------------------------------------------------------
|
| 已翻自 ../ai-game/src/app.ts 的部分 endpoint。完整列表 92 條，本檔已實作的：
| - GET  /api/health
| - POST /api/auth/register
| - POST /api/auth/login
| - POST /api/auth/logout      (sanctum)
| - GET  /api/me               (sanctum)
| - GET  /api/daily-logs       (sanctum)
| - POST /api/daily-logs       (sanctum)
| - GET  /api/daily-logs/{date}(sanctum)
| - GET  /api/meals            (sanctum)
| - POST /api/meals            (sanctum)
| - GET  /api/meals/{meal}     (sanctum)
| - DELETE /api/meals/{meal}   (sanctum)
|
| 待翻清單見 docs/ai-context 與 ../ai-game/src/app.ts
*/

Route::get('/health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

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
});
