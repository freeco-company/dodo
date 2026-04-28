<?php

namespace App\Services;

use App\Models\Food;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Translated from ai-game/src/services/foods.ts.
 *
 * Search strategy mirrors the legacy Node implementation so the iOS bundle
 * (still pointed at the old shape) sees identical ranking:
 *   1. exact match on name_zh / name_en
 *   2. alias hit (JSON array contains q)
 *   3. LIKE on name_zh / aliases / category
 * Results are de-duplicated by id and capped at $limit.
 *
 * MariaDB note: aliases is a json column, so we use JSON_SEARCH for the
 * alias lookup rather than the legacy SQLite `LIKE '%"q"%'` substring trick.
 */
class FoodService
{
    /**
     * @return Collection<int,Food>
     */
    public function search(string $query, int $limit = 20): Collection
    {
        $q = trim($query);
        if ($q === '') {
            return new Collection;
        }
        $like = '%'.$q.'%';

        // 1. exact match (name_zh OR name_en)
        $direct = Food::query()
            ->where(function ($w) use ($q) {
                $w->where('name_zh', $q)->orWhere('name_en', $q);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
        if ($direct->count() >= $limit) {
            return $direct->take($limit)->values();
        }

        $combined = $direct;
        $seenIds = $combined->pluck('id')->all();

        // 2. alias hit — Laravel persists json columns with the default
        //    json_encode options (i.e. CJK is escaped to \uXXXX). Match
        //    against both the raw and \u-escaped form so the typeahead
        //    works for both ASCII brand names and Chinese ingredients,
        //    and stays portable across MariaDB / SQLite test driver.
        $remaining = $limit - $combined->count();
        if ($remaining > 0) {
            $needles = array_unique([
                '%"'.$q.'"%',
                '%'.trim((string) json_encode($q), '"').'%',
            ]);
            $byAlias = Food::query()
                ->whereNotNull('aliases')
                ->where(function ($w) use ($needles) {
                    foreach ($needles as $n) {
                        $w->orWhere('aliases', 'like', $n);
                    }
                })
                ->when(! empty($seenIds), fn ($w) => $w->whereNotIn('id', $seenIds))
                ->orderBy('id')
                ->limit($remaining)
                ->get();
            $combined = $combined->concat($byAlias);
            $seenIds = $combined->pluck('id')->all();
        }

        // 3. LIKE fallback on name_zh / category. We deliberately avoid LIKE on
        //    aliases here — JSON_SEARCH already covered structured matches and
        //    a raw LIKE over JSON would match the JSON quotes and brackets.
        $remaining = $limit - $combined->count();
        if ($remaining > 0) {
            $byLike = Food::query()
                ->where(function ($w) use ($like) {
                    $w->where('name_zh', 'like', $like)
                        ->orWhere('name_en', 'like', $like)
                        ->orWhere('category', 'like', $like);
                })
                ->when(! empty($seenIds), fn ($w) => $w->whereNotIn('id', $seenIds))
                ->orderBy(DB::raw('verified'), 'desc')
                ->orderBy('name_zh')
                ->limit($remaining)
                ->get();
            $combined = $combined->concat($byLike);
        }

        return $combined->take($limit)->values();
    }
}
