<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    public function __construct(private readonly CheckinService $service) {}

    public function logWater(Request $request): JsonResponse
    {
        $data = $request->validate(['ml' => ['required', 'integer', 'min:0', 'max:6000']]);
        return response()->json($this->service->logWater($request->user(), $data['ml']));
    }

    public function setWater(Request $request): JsonResponse
    {
        $data = $request->validate(['ml' => ['required', 'integer', 'min:0', 'max:5000']]);
        return response()->json($this->service->setWater($request->user(), $data['ml']));
    }

    public function logExercise(Request $request): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:0', 'max:600']]);
        return response()->json($this->service->logExercise($request->user(), $data['minutes']));
    }

    public function setExercise(Request $request): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:0', 'max:300']]);
        return response()->json($this->service->setExercise($request->user(), $data['minutes']));
    }

    public function logWeight(Request $request): JsonResponse
    {
        $data = $request->validate(['weight_kg' => ['required', 'numeric', 'min:20', 'max:300']]);
        return response()->json($this->service->logWeight($request->user(), (float) $data['weight_kg']));
    }

    public function goals(): JsonResponse
    {
        return response()->json([
            'water_goal_ml' => CheckinService::DAILY_WATER_GOAL_ML,
            'water_cap_ml' => CheckinService::DAILY_WATER_CAP_ML,
            'exercise_goal_min' => CheckinService::DAILY_EXERCISE_GOAL_MIN,
            'exercise_cap_min' => CheckinService::DAILY_EXERCISE_CAP_MIN,
        ]);
    }
}
