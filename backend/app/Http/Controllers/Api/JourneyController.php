<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController extends Controller
{
    public function __construct(private readonly JourneyService $service) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->getJourney($request->user()));
    }

    public function advance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:meal_log,water,exercise,card_correct,daily_quest'],
        ]);
        return response()->json($this->service->advance($request->user(), $data['reason']));
    }
}
