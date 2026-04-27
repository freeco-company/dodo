<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'data' => [
                'status' => $dbOk ? 'ok' : 'degraded',
                'time' => now()->toIso8601String(),
                'db' => $dbOk ? 'ok' : 'down',
                'app' => config('app.name'),
                'env' => app()->environment(),
            ],
        ]);
    }
}
