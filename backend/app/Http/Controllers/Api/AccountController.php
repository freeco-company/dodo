<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private readonly AccountDeletionService $service) {}

    public function deleteRequest(Request $request): JsonResponse
    {
        return response()->json($this->service->request($request->user()));
    }

    public function restore(Request $request): JsonResponse
    {
        $ok = $this->service->restore($request->user());
        if (! $ok) abort(422, 'CANNOT_RESTORE');
        return response()->json(['ok' => true]);
    }

    public function purge(): JsonResponse
    {
        $count = $this->service->purge();
        return response()->json(['purged' => $count]);
    }
}
