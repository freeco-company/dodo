<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\AiServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI-powered meal endpoints. All proxy to the (not-yet-wired) Python service.
 * Until that service is up, every call returns 503 + AI_SERVICE_DOWN.
 */
class AiMealController extends Controller
{
    public function __construct(private readonly AiServiceClient $ai) {}

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image_url' => ['required', 'url', 'max:2048'],
            'context' => ['nullable', 'array'],
        ]);
        try {
            return response()->json($this->ai->scanMeal($request->user(), $data['image_url'], $data['context'] ?? []));
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
