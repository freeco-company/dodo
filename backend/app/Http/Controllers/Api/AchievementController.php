<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/achievements — full unlocked + locked catalog for the user.
 *
 * @todo Port ai-game achievement catalog (which currently lives only in TS as
 *       a hard-coded array). For now we surface the unlocked rows from the
 *       achievements table; locked array is empty until the catalog is
 *       migrated to a config/seed.
 */
class AchievementController extends Controller
{
    /** @var list<array{key:string, name:string, description:string}> Catalog stub. */
    private const CATALOG = [
        ['key' => 'first_meal', 'name' => '第一餐', 'description' => '記錄第一次餐點'],
        ['key' => 'streak_3', 'name' => '三日連勝', 'description' => '連續三天打卡'],
        ['key' => 'streak_7', 'name' => '一週有你', 'description' => '連續七天打卡'],
        ['key' => 'foodie_10', 'name' => '美食家 10', 'description' => '收集 10 種食物'],
        ['key' => 'perfect_day', 'name' => '完美一日', 'description' => '單日 80 分以上'],
        ['key' => 'perfect_week', 'name' => '完美一週', 'description' => '累積 7 個完美日'],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        /** @var \Illuminate\Database\Eloquent\Collection<int,Achievement> $rows */
        $rows = Achievement::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->orderByDesc('unlocked_at')
            ->get(['achievement_key', 'achievement_name', 'unlocked_at']);

        $unlockedKeys = $rows->pluck('achievement_key')->toArray();
        $unlockedList = $rows->map(fn (Achievement $a) => [
            'key' => $a->achievement_key,
            'name' => $a->achievement_name,
            'unlocked_at' => $a->unlocked_at?->toIso8601String(),
        ])->values();

        $merged = array_map(function ($a) use ($unlockedKeys) {
            return [
                'key' => $a['key'],
                'name' => $a['name'],
                'description' => $a['description'],
                'unlocked' => in_array($a['key'], $unlockedKeys, true),
            ];
        }, self::CATALOG);

        $locked = array_values(array_filter($merged, fn ($a) => ! $a['unlocked']));

        return response()->json([
            'achievements' => $merged,
            'unlocked' => count($unlockedKeys),
            'total' => count(self::CATALOG),
            'unlocked_list' => $unlockedList,
            'locked' => $locked,
        ]);
    }
}
