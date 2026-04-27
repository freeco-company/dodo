<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RatingPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingPromptController extends Controller
{
    public function __construct(private readonly RatingPromptService $service) {}

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', RatingPromptService::KINDS)],
        ]);
        $this->service->log($request->user(), $data['kind']);
        return response()->json(['ok' => true]);
    }
}
