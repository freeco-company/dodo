<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TierController extends Controller
{
    public function __construct(private readonly TierService $service) {}

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate(['ref_code' => ['required', 'string', 'max:64']]);
        return response()->json($this->service->applyRefCode($request->user(), $data['ref_code']));
    }

    public function adminSet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'tier' => ['required', 'in:public,fp_lifetime'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);
        $user = User::findOrFail($data['user_id']);
        return response()->json($this->service->adminSetTier($user, $data['tier'], $data['reason'] ?? 'admin_manual'));
    }

    public function mockSubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:app_monthly,app_yearly'],
        ]);
        return response()->json($this->service->mockSubscribe($request->user(), $data['type'] ?? 'app_monthly'));
    }
}
