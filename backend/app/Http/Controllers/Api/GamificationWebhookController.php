<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gamification\AchievementMirror;
use App\Services\Gamification\GroupProgressionMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * py-service → 朵朵 gamification webhook receiver. ADR-009 §2.2 / Phase B.2.
 *
 * Signature verification + idempotency are handled upstream by
 * {@see \App\Http\Middleware\VerifyGamificationWebhookSignature}, so this
 * controller can assume `event_id` is fresh and the body is authentic.
 *
 * Handled event_types:
 *   - gamification.level_up           → mirror users.level / users.xp
 *   - gamification.achievement_awarded → mirror achievements row
 * Unknown types ack 200 (forward-compat for new server-side events).
 */
class GamificationWebhookController extends Controller
{
    public function __construct(
        private readonly GroupProgressionMirror $progressionMirror,
        private readonly AchievementMirror $achievementMirror,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $eventType = (string) $request->json('event_type');
        $uuid = (string) $request->json('pandora_user_uuid');
        $payload = (array) $request->json('payload', []);

        switch ($eventType) {
            case 'gamification.level_up':
                $changed = $this->progressionMirror->applyLevelUp($uuid, $payload);

                return response()->json([
                    'status' => 'ok',
                    'event_type' => $eventType,
                    'mirrored' => $changed,
                ], 200);

            case 'gamification.achievement_awarded':
                $mirrored = $this->achievementMirror->applyAwarded($uuid, $payload);

                return response()->json([
                    'status' => 'ok',
                    'event_type' => $eventType,
                    'mirrored' => $mirrored,
                ], 200);

            default:
                // Unknown event_type — ack with 200 so py-service stops retrying.
                // Logging gives ops visibility for forward-compat surprises.
                Log::info('[GamificationWebhook] unhandled event_type', [
                    'event_type' => $eventType,
                    'event_id' => $request->json('event_id'),
                ]);

                return response()->json([
                    'status' => 'ignored',
                    'event_type' => $eventType,
                ], 200);
        }
    }
}
