<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InsightResource;
use App\Models\Insight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * SPEC-cross-metric-insight-v1 PR #2 — Insight read API.
 *
 * Read-only from the user's POV; write side is the InsightEngine
 * (cron + realtime dispatch in PR #4).
 *
 *   GET    /api/insights/unread        — chat tab surface
 *   POST   /api/insights/{i}/read      — mark seen
 *   POST   /api/insights/{i}/dismiss   — not interested (doubles cooldown)
 *   GET    /api/insights/history       — paginated past insights
 *   GET    /api/insights/{i}           — detail (chart payload + actions)
 */
class InsightController extends Controller
{
    public function unread(Request $request): AnonymousResourceCollection
    {
        $insights = Insight::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->orderByDesc('fired_at')
            ->limit(10)
            ->get();

        return InsightResource::collection($insights);
    }

    public function read(Request $request, Insight $insight): JsonResponse
    {
        $this->guard($request, $insight);
        if ($insight->read_at === null) {
            $insight->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function dismiss(Request $request, Insight $insight): JsonResponse
    {
        $this->guard($request, $insight);
        if ($insight->dismissed_at === null) {
            $insight->update(['dismissed_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = $request->integer('limit', 20);

        $insights = Insight::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('fired_at')
            ->limit($limit)
            ->get();

        return InsightResource::collection($insights);
    }

    public function show(Request $request, Insight $insight): InsightResource
    {
        $this->guard($request, $insight);

        return new InsightResource($insight);
    }

    private function guard(Request $request, Insight $insight): void
    {
        if ($insight->user_id !== $request->user()->id) {
            throw new AuthorizationException('cross-tenant');
        }
    }
}
