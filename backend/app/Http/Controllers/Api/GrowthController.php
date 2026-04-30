<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GrowthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrowthController extends Controller
{
    public function __construct(private readonly GrowthService $growth) {}

    /**
     * GET /api/me/growth/timeseries?metric=weight_kg&days=30
     *
     * Densified day-by-day series (nulls for un-logged days) — frontend chart
     * decides whether to skip-render nulls or interpolate.
     */
    public function timeseries(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => 'sometimes|string',
            'days' => 'sometimes|integer|min:1|max:365',
        ]);

        $requested = $request->string('metric', 'weight_kg')->toString();
        $metric = in_array($requested, GrowthService::SUPPORTED_METRICS, true) ? $requested : 'weight_kg';
        $days = (int) $request->integer('days', 30);

        $series = $this->growth->timeseries($request->user(), $metric, $days);

        return response()->json([
            'metric' => $metric,
            'days' => $days,
            'points' => $series,
        ]);
    }

    /**
     * GET /api/me/growth/weekly-review
     *
     * 7-day rollup vs previous 7 days + 朵朵 commentary speech bubble.
     */
    public function weeklyReview(Request $request): JsonResponse
    {
        return response()->json($this->growth->weeklyReview($request->user()));
    }
}
