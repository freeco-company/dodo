<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Internal callbacks from ai-service (FastAPI) → meal Laravel.
 *
 * SPEC-photo-ai-calorie-polish §4.2 — receiver for `ai-service/app/callback/client.py`
 * `post_food_recognition` + `post_cost_event`.
 *
 * **Not user-facing**：only ai-service should hit these endpoints. Auth via
 * `X-Internal-Secret` header (same shared secret as the publisher / lifecycle
 * client, see `services.meal_ai_service.shared_secret`).
 *
 * Persistence model：
 *   - We don't write `meals` rows here — frontend POST /api/meals is the canonical
 *     log path (user must explicitly confirm before counting toward their daily log).
 *   - We DO record cost / token usage for AiCostGuardService analytics, since the
 *     ai-service has authoritative cost numbers and these are user-blind.
 *
 * Response shape：always 200 + `{ ok: true }` on accepted; 401 on bad secret;
 * 422 on schema fail. Never 5xx — ai-service treats this as best-effort.
 */
class AiCallbackController extends Controller
{
    public function foodRecognition(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error_code' => 'UNAUTHORIZED'], 401);
        }

        $data = $request->validate([
            'userUuid' => ['required', 'string', 'max:64'],
            'mealType' => ['nullable', 'string', 'max:32'],
            'items' => ['nullable', 'array'],
            'confidence' => ['nullable', 'numeric'],
            'manualInputRequired' => ['nullable', 'boolean'],
            'aiFeedback' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:128'],
            'costUsd' => ['nullable', 'numeric'],
        ]);

        // Best-effort log — don't write `meals` (frontend confirms first).
        // We record this for ops visibility into ai-service traffic vs frontend
        // POST /api/meals conversion (drop-off ≈ users who recognized but didn't
        // confirm, useful signal for SPEC §3 progressive disclosure tuning).
        Log::info('[ai-callback] food-recognition', [
            'uuid' => $data['userUuid'],
            'meal_type' => $data['mealType'] ?? null,
            'item_count' => is_array($data['items'] ?? null) ? count($data['items']) : 0,
            'confidence' => $data['confidence'] ?? null,
            'manual' => $data['manualInputRequired'] ?? null,
            'model' => $data['model'] ?? null,
            'cost_usd' => $data['costUsd'] ?? null,
        ]);

        return response()->json(['ok' => true], 200);
    }

    public function costEvent(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error_code' => 'UNAUTHORIZED'], 401);
        }

        $data = $request->validate([
            'userUuid' => ['required', 'string', 'max:64'],
            'endpoint' => ['required', 'string', 'max:128'],
            'model' => ['required', 'string', 'max:128'],
            'tokensIn' => ['required', 'integer', 'min:0'],
            'tokensOut' => ['required', 'integer', 'min:0'],
            'costUsd' => ['nullable', 'numeric'],
        ]);

        // SPEC §5.2 cost guard wire point — ai-service is authoritative on
        // token counts (real Anthropic usage, not estimated). Persist into
        // usage_logs so AiCostGuardService::monthlyCostUsd() sees true spend.
        $user = User::query()->where('pandora_user_uuid', $data['userUuid'])->first();
        if ($user === null) {
            // Unknown uuid — log + ack so ai-service doesn't retry forever.
            Log::warning('[ai-callback] cost-event for unknown uuid', [
                'uuid' => $data['userUuid'],
                'endpoint' => $data['endpoint'],
            ]);

            return response()->json(['ok' => true, 'noted' => 'user_not_found'], 200);
        }

        // kind = 'vision' for /v1/vision/recognize, 'chat' for /v1/chat/stream, etc.
        $kind = match (true) {
            str_contains($data['endpoint'], 'vision') => 'vision',
            str_contains($data['endpoint'], 'chat') => 'chat',
            default => 'other',
        };

        UsageLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => now('Asia/Taipei')->toDateString(),
            'kind' => $kind,
            'model' => $data['model'],
            'tokens' => $data['tokensIn'] + $data['tokensOut'],
            'input_tokens' => $data['tokensIn'],
            'output_tokens' => $data['tokensOut'],
        ]);

        return response()->json(['ok' => true], 200);
    }

    private function authorized(Request $request): bool
    {
        $expected = (string) config('services.meal_ai_service.shared_secret', '');
        if ($expected === '') {
            // Unset secret → reject in non-local envs to fail loud.
            return app()->environment('local');
        }
        $provided = (string) $request->header('X-Internal-Secret', '');

        return hash_equals($expected, $provided);
    }
}
