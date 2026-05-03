<?php

namespace App\Services\Ritual;

use App\Models\ProgressSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SPEC-progress-ritual-v1 PR #2 — pick 4-9 representative snapshots from
 * the past 30 days for the monthly collage.
 *
 * Algorithm:
 *   - Take all user snapshots in the window
 *   - If ≤ 9 → return all (sorted by taken_at)
 *   - Else: keep the first + last, then evenly sample N-2 in the middle
 *
 * SPEC §4.1 step 3: «挑 4-9 張代表性照片（演算法：均勻分布在 30 天 +
 * 體重變化最大的點）» — v1 implements even time distribution; weight-delta
 * picking is a v2 enhancement (data shape stable, algo can swap locally).
 */
class PhotoSelector
{
    public const MIN_FOR_COLLAGE = 4;
    public const MAX_FOR_COLLAGE = 9;

    /** @return Collection<int, ProgressSnapshot> */
    public function selectForMonth(User $user, CarbonImmutable $monthStart): Collection
    {
        $monthEnd = $monthStart->endOfMonth();

        $snapshots = ProgressSnapshot::query()
            ->where('user_id', $user->id)
            ->whereBetween('taken_at', [$monthStart->startOfDay(), $monthEnd->endOfDay()])
            ->orderBy('taken_at')
            ->get();

        if ($snapshots->count() < self::MIN_FOR_COLLAGE) {
            return collect();
        }
        if ($snapshots->count() <= self::MAX_FOR_COLLAGE) {
            return $snapshots;
        }

        // Even distribution: always keep first + last, sample middle.
        $picked = collect([$snapshots->first()]);
        $middle = $snapshots->slice(1, $snapshots->count() - 2);
        $needed = self::MAX_FOR_COLLAGE - 2;
        $step = $middle->count() / $needed;
        for ($i = 0; $i < $needed; $i++) {
            $idx = (int) round($i * $step);
            if ($idx < $middle->count()) {
                $picked->push($middle->values()[$idx]);
            }
        }
        $picked->push($snapshots->last());

        return $picked->unique('id')->values();
    }
}
