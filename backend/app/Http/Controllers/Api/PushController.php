<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function __construct(private readonly PushService $service) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:' . implode(',', PushService::PLATFORMS)],
            'token' => ['required', 'string', 'min:10', 'max:512'],
            'device_info' => ['nullable', 'array'],
        ]);
        $id = $this->service->register(
            $request->user(),
            $data['platform'],
            $data['token'],
            $data['device_info'] ?? null
        );
        return response()->json(['id' => $id]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:' . implode(',', PushService::PLATFORMS)],
            'token' => ['required', 'string', 'min:10', 'max:512'],
        ]);
        $this->service->unregister($data['platform'], $data['token']);
        return response()->json(['ok' => true]);
    }
}
