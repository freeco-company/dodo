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

                return response()->json($this->ai->scanMealFromBytes($request->user(), $bytes, $contentType, $context));
            }

            return response()->json($this->ai->scanMeal($request->user(), $data['image_url'], $context));
        } catch (AiServiceUnavailableException $e) {
            return response()->json(['error_code' => $e->errorCode, 'message' => 'AI 服務暫時不可用'], 503);
        }
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
