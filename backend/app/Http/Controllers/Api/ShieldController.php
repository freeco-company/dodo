<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShieldController extends Controller
{
    public function __construct(private readonly ShieldService $service) {}

    public function refill(Request $request): JsonResponse
    {
        return response()->json($this->service->refillIfDue($request->user()));
    }

    public function use(Request $request): JsonResponse
    {
        return response()->json($this->service->use($request->user()));
    }
}
