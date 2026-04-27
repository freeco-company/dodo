<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public error sink for the web/mobile client. Throttled at the route level
 * to keep abuse manageable. We intentionally do NOT require auth so anonymous
 * crashes (e.g. landing page) still surface.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'context' => ['nullable', 'array'],
        ]);
        DB::table('client_errors')->insert([
            'user_id' => $request->user()?->id,
            'message' => $data['message'],
            'stack' => $data['stack'] ?? null,
            'context' => isset($data['context']) ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : null,
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }
}
