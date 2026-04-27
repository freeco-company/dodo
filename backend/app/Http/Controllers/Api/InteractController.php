<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InteractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractController extends Controller
{
    public function __construct(private readonly InteractService $service) {}

    public function pet(Request $request): JsonResponse
    {
        return response()->json($this->service->pet($request->user()));
    }

    public function gift(Request $request): JsonResponse
    {
        return response()->json($this->service->dailyGift($request->user()));
    }

    public function giftStatus(Request $request): JsonResponse
    {
        return response()->json($this->service->giftStatus($request->user()));
    }
}
