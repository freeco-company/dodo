<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FastingSession;
use App\Services\EntitlementsService;
use App\Services\FastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * SPEC-fasting-timer Phase 1 — REST endpoints for fasting timer.
 *
 *   POST /api/fasting/start    body: { mode, target_duration_minutes? }
 *   POST /api/fasting/end      body: {}
 *   GET  /api/fasting/current  → snapshot or null
 *   GET  /api/fasting/history  ?page=1&per_page=20
 */
class FastingController extends Controller
{
    public function __construct(
        private readonly FastingService $service,
        private readonly EntitlementsService $entitlements,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:16:8,14:10,18:6,20:4,custom'],
            'target_duration_minutes' => ['nullable', 'integer', 'min:780', 'max:1320'],
            'started_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        try {
            $session = $this->service->start($user, $data);
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return response()->json([
            'session' => $this->serialize($session),
            'snapshot' => $this->service->snapshot($user),
        ], 201);
    }

    public function end(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ended_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        try {
            $session = $this->service->end($user, $data['ended_at'] ?? null);
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return response()->json([
            'session' => $this->serialize($session),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json([
            'snapshot' => $this->service->snapshot($request->user()),
        ]);
    }

    /**
     * SPEC-v2 §2.5 — retroactively change session start time (forgot-to-start fix).
     */
    public function markStartedAt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'started_at' => ['required', 'date'],
        ]);
        try {
            $session = $this->service->markStartedAt(
                $request->user(),
                \Carbon\CarbonImmutable::parse($data['started_at']),
            );
        } catch (RuntimeException $e) {
            return $this->translate($e);
        }

        return response()->json([
            'session' => $this->serialize($session),
            'snapshot' => $this->service->snapshot($request->user()),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->service->history(
            $request->user(),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (FastingSession $s) => $this->serialize($s))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'history_capped_days' => $this->isHistoryCapped($request) ? FastingService::FREE_HISTORY_DAYS : null,
            ],
        ]);
    }

    private function isHistoryCapped(Request $request): bool
    {
        return ! $this->entitlements->isPaid($request->user());
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(FastingSession $session): array
    {
        return [
            'id' => $session->id,
            'mode' => $session->mode,
            'target_duration_minutes' => (int) $session->target_duration_minutes,
            'started_at' => $session->started_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'completed' => (bool) $session->completed,
        ];
    }

    private function translate(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'fasting_already_active' => response()->json([
                'error_code' => 'FASTING_ALREADY_ACTIVE',
                'message' => '妳已經有一個進行中的斷食 🌱',
            ], 422),
            'fasting_no_active' => response()->json([
                'error_code' => 'FASTING_NO_ACTIVE',
                'message' => '目前沒有進行中的斷食',
            ], 404),
            'fasting_mode_locked' => response()->json([
                'error_code' => 'FASTING_MODE_LOCKED',
                'message' => '這個模式需要升級訂閱才能解鎖 ✨',
                'paywall' => [
                    'reason' => 'fasting_advanced_mode',
                    'tier_required' => 'paid',
                ],
            ], 402),
            'fasting_start_in_future', 'fasting_start_too_old' => response()->json([
                'error_code' => strtoupper($e->getMessage()),
                'message' => $e->getMessage() === 'fasting_start_in_future'
                    ? '開始時間不能在未來'
                    : '只能調整 24 小時內的時間',
            ], 422),
            'fasting_mode_invalid', 'fasting_target_out_of_range', 'fasting_ended_before_started' => response()->json([
                'error_code' => strtoupper($e->getMessage()),
                'message' => '輸入無效',
            ], 422),
            default => response()->json([
                'error_code' => 'FASTING_ERROR',
                'message' => '無法處理此操作',
            ], 422),
        };
    }
}
