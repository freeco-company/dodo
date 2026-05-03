<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC-healthkit-integration Phase 1 — REST endpoints for HealthKit /
 * Health Connect ingestion + Today widget snapshot + per-type history.
 *
 *   POST /api/health/sync          body: { metrics: [...] }
 *   GET  /api/health/today
 *   GET  /api/health/history?type=steps&days=30
 */
class HealthMetricsController extends Controller
{
    public function __construct(
        private readonly HealthMetricsService $service,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'metrics' => ['required', 'array', 'min:1', 'max:500'],
            'metrics.*.type' => ['required', 'string', 'max:32'],
            'metrics.*.value' => ['required', 'numeric'],
            'metrics.*.unit' => ['required', 'string', 'max:16'],
            'metrics.*.recorded_at' => ['required', 'date'],
            'metrics.*.source' => ['nullable', 'string', 'max:32'],
            'metrics.*.raw_payload' => ['nullable', 'array'],
        ]);

        $result = $this->service->sync($request->user(), $data['metrics']);

        // SPEC-progress-ritual-v1 PR #8 — fire ritual on weight log streak milestones.
        try {
            app(\App\Services\Ritual\StreakRitualService::class)->checkWeightLogStreak($request->user());
        } catch (\Throwable $e) { /* fail-soft */ }

        return response()->json($result, 200);
    }

    public function today(Request $request): JsonResponse
    {
        return response()->json($this->service->today($request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:steps,active_kcal,weight,workout,sleep_minutes,heart_rate'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $result = $this->service->history(
            $request->user(),
            $data['type'],
            (int) ($data['days'] ?? 30),
        );

        return response()->json($result);
    }
}
