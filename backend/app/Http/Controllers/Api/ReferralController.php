<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $service) {}

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:4', 'max:32']]);
        $result = $this->service->apply($request->user(), $data['code']);
        if (! $result) {
            return response()->json(['error' => 'INVALID_OR_ALREADY_REDEEMED'], 422);
        }
        return response()->json($result);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->service->stats($request->user()));
    }
}
