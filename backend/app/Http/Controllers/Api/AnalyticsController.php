<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $service) {}

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:80'],
            'properties' => ['nullable', 'array'],
        ]);
        $this->service->track($request->user(), $data['event'], $data['properties'] ?? null);
        return response()->json(['ok' => true]);
    }

    public function flush(): JsonResponse
    {
        return response()->json(['flushed' => $this->service->flush()]);
    }
}
