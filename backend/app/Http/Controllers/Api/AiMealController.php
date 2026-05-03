<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\AiServiceClient;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI-powered meal endpoints — HTTP proxy to the Python ai-service
 * (ADR-002 §3). Returns 503 + AI_SERVICE_DOWN when the service is unreachable
 * or not configured.
 */
class AiMealController extends Controller
{
    /** ~5 MB base64 ≈ 3.75 MB raw — keep below ai-service 8 MB ceiling with headroom. */
    private const MAX_PHOTO_BASE64_BYTES = 5_000_000;

    public function __construct(
        private readonly AiServiceClient $ai,
        private readonly EntitlementsService $entitlements,
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Either source — exactly one. Frontend (camera/picker) uses photo_base64;
            // admin / server-side flows that already have a stored URL use image_url.
            'photo_base64' => [
                'required_without:image_url',
                'prohibits:image_url',
                'string',
                'max:'.self::MAX_PHOTO_BASE64_BYTES,
            ],
            'image_url' => [
                'required_without:photo_base64',
                'string',
                'url',
                'max:2048',
            ],
            'content_type' => ['nullable', 'string', Rule::in(['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/gif'])],
            'meal_type' => ['nullable', 'string', Rule::in(['breakfast', 'lunch', 'dinner', 'snack', 'late_night'])],
            'context' => ['nullable', 'array'],
        ]);

        $context = $data['context'] ?? [];
        if (isset($data['meal_type'])) {
            $context['meal_type'] = $data['meal_type'];
        }

        // SPEC-photo-ai-calorie-polish §5.1 — pre-flight quota check.
        // Free tier: 3 拍照辨識/天；Paid: unlimited (passes through).
        // 超額返 402 + paywall payload；frontend 朵朵語氣不打擾文案見 SPEC §5.1。
        $user = $request->user();
        if ($user !== null && ! $this->entitlements->consumePhotoAiQuota($user)) {
            return response()->json([
                'error_code' => 'PHOTO_AI_QUOTA_EXCEEDED',
                'message' => '今天的拍照次數用完了 🌱',
                'paywall' => [
                    'reason' => 'photo_ai_daily_quota',
                    'tier_required' => 'paid',
                    'fallback_hint' => '可以改用「文字描述」記錄，或明天再來',
                    'fallback_endpoint' => '/api/meals/text',
                    'reset_at_iso' => $this->entitlements->get($user)['photo_ai_quota_reset_at'] ?? null,
                ],
            ], 402);
        }

        try {
            if (isset($data['photo_base64'])) {
                $bytes = base64_decode($data['photo_base64'], true);
                if ($bytes === false || $bytes === '') {
                    return response()->json([
                        'error_code' => 'INVALID_BASE64',
                        'message' => '圖片資料無法解碼',
                    ], 422);
                }
                $contentType = $data['content_type'] ?? 'image/jpeg';
                $aiResp = $this->ai->scanMealFromBytes($request->user(), $bytes, $contentType, $context);
            } else {
                $aiResp = $this->ai->scanMeal($request->user(), $data['image_url'], $context);
            }

            // SPEC-photo-ai-correction-v2 PR #4.5 — auto-materialize Meal +
            // MealDish rows from ai-service items[] so the per-dish UI
            // (PR #148 frontend) actually has data to render. Frontend used
            // to need a separate POST /meals call; that left dishes[] empty
            // and the multi-dish correction sheet never appeared.
            //
            // Skip on not_food / empty items (degenerate / safety paths).
            $autoMeal = null;
            if (($aiResp['is_food'] ?? true)
                && is_array($aiResp['items'] ?? null)
                && count($aiResp['items']) > 0
            ) {
                $autoMeal = $this->materializeMealFromScan(
                    $request->user(),
                    $aiResp,
                    $data['meal_type'] ?? 'lunch',
                );
            }

            // Preserve full ai-service response shape + add `meal` key so
            // existing frontend (showScanResult) keeps working.
            $payload = $aiResp;
            if ($autoMeal !== null) {
                $payload['meal'] = (new \App\Http\Resources\MealResource(
                    $autoMeal->load('dishes')
                ))->toArray($request);
            }

            return response()->json($payload);
        } catch (AiServiceUnavailableException $e) {
            return response()->json(['error_code' => $e->errorCode, 'message' => 'AI 服務暫時不可用'], 503);
        }
    }

    /**
     * SPEC-photo-ai-correction-v2 PR #4.5 — turn ai-service items[] into a
     * persisted Meal + MealDish rows so the per-dish UI lights up.
     *
     * Macros: ai-service returns macro_grams (carb/protein/fat) per item.
     * Where missing (older payloads, low-confidence fallback), we proxy
     * kcal-based 40/30/30 split as a safe default — same heuristic used
     * by the frontend manual add sheet (PR #148 openAddDishSheet).
     */
    private function materializeMealFromScan(\App\Models\User $user, array $aiResp, string $mealType): \App\Models\Meal
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($user, $aiResp, $mealType) {
            /** @var \App\Models\Meal $meal */
            $meal = $user->meals()->create([
                'date' => \Carbon\CarbonImmutable::now('Asia/Taipei')->toDateString(),
                'meal_type' => $mealType,
                'food_name' => $aiResp['items'][0]['name'] ?? '一餐',
                'recognized_via' => 'photo',
                'ai_confidence' => $aiResp['overall_confidence'] ?? null,
                'dodo_comment' => $aiResp['dodo_comment'] ?? null,
                'coach_response' => $aiResp['ai_feedback'] ?? null,
                'calories' => 0,
                'carbs_g' => 0,
                'protein_g' => 0,
                'fat_g' => 0,
            ]);

            foreach ($aiResp['items'] as $idx => $item) {
                $kcal = (int) ($item['estimated_kcal'] ?? 0);
                $macro = $item['macro_grams'] ?? null;
                if (is_array($macro)) {
                    $carb = (float) ($macro['carb'] ?? 0);
                    $protein = (float) ($macro['protein'] ?? 0);
                    $fat = (float) ($macro['fat'] ?? 0);
                } else {
                    // Fallback 40/30/30 split (1g carb/protein = 4 kcal, 1g fat = 9 kcal).
                    $carb = round($kcal * 0.4 / 4, 1);
                    $protein = round($kcal * 0.3 / 4, 1);
                    $fat = round($kcal * 0.3 / 9, 1);
                }

                $meal->dishes()->create([
                    'food_name' => $item['name'] ?? "dish ".($idx + 1),
                    'food_key' => $item['food_key'] ?? null,
                    'portion_multiplier' => 1.00,
                    'kcal' => $kcal,
                    'carb_g' => $carb,
                    'protein_g' => $protein,
                    'fat_g' => $fat,
                    'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : null,
                    'source' => \App\Models\MealDish::SOURCE_AI_INITIAL,
                    'candidates_json' => $item['candidates'] ?? null,
                    'display_order' => $idx,
                ]);
            }

            app(\App\Services\MealCorrectionService::class)->recalcMealTotals($meal->fresh());

            return $meal->fresh();
        });
    }

    public function text(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'min:1', 'max:1000'],
            'context' => ['nullable', 'array'],
        ]);
        try {
            return response()->json($this->ai->describeMeal($request->user(), $data['description'], $data['context'] ?? []));
        } catch (AiServiceUnavailableException $e) {
            return response()->json(['error_code' => $e->errorCode, 'message' => 'AI 服務暫時不可用'], 503);
        }
    }
}
