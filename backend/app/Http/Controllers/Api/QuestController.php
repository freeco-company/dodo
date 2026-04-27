<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestController extends Controller
{
    public function __construct(private readonly QuestService $service) {}

    public function today(Request $request): JsonResponse
    {
        return response()->json($this->service->listToday($request->user()));
    }
}
