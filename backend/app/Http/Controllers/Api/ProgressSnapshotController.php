<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProgressSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC-progress-photo-album Phase 1 — metadata-only endpoints.
 *
 *   POST /api/progress/snapshot   { taken_at, weight_kg?, mood?, notes?, photo_ref? }
 *   GET  /api/progress/timeline   ?days=90
 *
 * Tier-gating: SPEC §3 says Yearly+ unlocks the album; Monthly users get a
 * 402 paywall sheet. We approximate "Yearly+ tier" as fp_lifetime OR
 * yearly subscription (subscription_type === 'app_yearly') OR active
 * subscription with `subscription_expires_at_iso` more than 6 months
 * out (heuristic: monthly auto-renew never reaches that horizon).
 */
class ProgressSnapshotController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->yearlyTierOrAbove($user)) {
            return response()->json([
                'error_code' => 'PROGRESS_TIER_LOCKED',
                'message' => '進度照相簿是年付 / VIP 才有的功能 ✨',
                'paywall' => [
                    'reason' => 'progress_album_yearly_only',
                    'tier_required' => 'yearly',
                ],
            ], 402);
        }

        $data = $request->validate([
            'taken_at' => ['required', 'date'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:250'],
            'mood' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:500'],
            'photo_ref' => ['nullable', 'string', 'max:64'],
        ]);

        $snap = ProgressSnapshot::create([
            'user_id' => $user->id,
            'taken_at' => CarbonImmutable::parse($data['taken_at']),
            'weight_g' => isset($data['weight_kg']) ? (int) round((float) $data['weight_kg'] * 1000) : null,
            'mood' => $data['mood'] ?? null,
            'notes' => $data['notes'] ?? null,
            'photo_ref' => $data['photo_ref'] ?? null,
        ]);

        // SPEC-progress-ritual-v1 PR #8 — fire ritual on photo streak milestones.
        try {
            app(\App\Services\Ritual\StreakRitualService::class)->checkPhotoStreak($user);
        } catch (\Throwable $e) { /* fail-soft */ }

        return response()->json($this->serialize($snap), 201);
    }

    public function timeline(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->yearlyTierOrAbove($user)) {
            return response()->json([
                'error_code' => 'PROGRESS_TIER_LOCKED',
                'message' => '進度照相簿是年付 / VIP 才有的功能 ✨',
                'paywall' => [
                    'reason' => 'progress_album_yearly_only',
                    'tier_required' => 'yearly',
                ],
            ], 402);
        }

        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
        $days = (int) ($request->integer('days', 90));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $rows = ProgressSnapshot::query()
            ->where('user_id', $user->id)
            ->where('taken_at', '>=', $cutoff)
            ->orderBy('taken_at')
            ->get()
            ->map(fn (ProgressSnapshot $s) => $this->serialize($s))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'window_days' => $days,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $snap = ProgressSnapshot::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if ($snap === null) {
            return response()->json(['message' => 'Snapshot not found'], 404);
        }
        $snap->delete();

        return response()->json(null, 204);
    }

    private function yearlyTierOrAbove(\App\Models\User $user): bool
    {
        $tier = $user->membership_tier ?? null;
        if ($tier === 'fp_lifetime') {
            return true;
        }
        $sub = $user->subscription_type ?? 'none';
        if (in_array($sub, ['app_yearly', 'vip'], true)) {
            return true;
        }
        // Monthly subscribers and free users — locked.
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(ProgressSnapshot $s): array
    {
        return [
            'id' => $s->id,
            'taken_at' => $s->taken_at->toIso8601String(),
            'weight_kg' => $s->weight_g !== null ? round($s->weight_g / 1000, 2) : null,
            'mood' => $s->mood,
            'notes' => $s->notes,
            'photo_ref' => $s->photo_ref,
        ];
    }
}
