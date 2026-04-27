<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaywallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaywallController extends Controller
{
    public function __construct(private readonly PaywallService $service) {}

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', PaywallService::KINDS)],
            'trigger' => ['nullable', 'string', 'max:48'],
            'properties' => ['nullable', 'array'],
        ]);
        $this->service->logEvent(
            $request->user(),
            $data['kind'],
            $data['trigger'] ?? null,
            $data['properties'] ?? []
        );
        return response()->json(['ok' => true]);
    }
}
