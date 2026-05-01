<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/achievements — full catalog merged with user's unlocked rows.
 *
 * 2026-05-01 — contract align with frontend:
 *   { achievements: [{key, name, description, unlocked, unlocked_at?}],
 *     unlocked: <count>, total: <count> }
 * Frontend (loadAchievements) expects this shape; previous {unlocked:[], locked:[]}
 * caused the wardrobe-tab achievements section to appear empty (regression).
 *
 * Catalog mirrors the py-service ACHIEVEMENT_CATALOG (ADR-009). When a new
 * achievement is awarded by py-service, AchievementMirror writes to the
 * local achievements table; this endpoint then surfaces it as unlocked.
 */
class AchievementController extends Controller
{
    /**
     * Catalog mirrors py-service `ACHIEVEMENT_CATALOG` keys exactly
     * (see py-service/app/gamification/catalog.py §5.2). Keys MUST stay in
     * sync — frontend renders a curated grid of these and py-service
     * AchievementMirror writes inbound webhook awards into `achievements`
     * keyed by the same code. Mismatched keys = grey-forever curated cards
     * + orphaned awarded rows with empty descriptions.
     *
     * Drop / add only when the py-service catalog changes; bump
     * AchievementCatalogParityTest in lockstep.
     *
     * @var list<array{key:string, name:string, description:string}>
     */
    public const CATALOG = [
        // ── meal (潘朵拉飲食) ──
        ['key' => 'meal.first_meal', 'name' => '第一餐', 'description' => '記錄了第一筆餐食'],
        ['key' => 'meal.streak_7', 'name' => '一週有你', 'description' => '連續 7 天打卡'],
        ['key' => 'meal.streak_30', 'name' => '一個月的陪伴', 'description' => '連續 30 天打卡'],
        ['key' => 'meal.foodie_10', 'name' => '美食探索家', 'description' => '圖鑑收集 10 種食物'],

        // ── jerosse (婕樂纖) ──
        ['key' => 'jerosse.first_browse', 'name' => '好奇探索家', 'description' => '第一次逛婕樂纖'],
        ['key' => 'jerosse.first_order', 'name' => '首購達成', 'description' => '第一筆婕樂纖訂單'],
        ['key' => 'jerosse.spend_10k', 'name' => '金級夥伴', 'description' => '累積消費滿 1 萬'],

        // ── group (cross-app) ──
        ['key' => 'group.multi_app_explorer', 'name' => '跨界探索家', 'description' => '體驗 3 個以上潘朵拉系列 App'],
        ['key' => 'group.full_constellation', 'name' => '潘朵拉全收', 'description' => '集滿所有潘朵拉系列 App 的首次成就'],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        /** @var \Illuminate\Support\Collection<string,Achievement> $unlockedByKey */
        $unlockedByKey = Achievement::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->orderByDesc('unlocked_at')
            ->get(['achievement_key', 'achievement_name', 'unlocked_at'])
            ->keyBy('achievement_key');

        $achievements = array_map(function (array $entry) use ($unlockedByKey) {
            $row = $unlockedByKey->get($entry['key']);

            return [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'description' => $entry['description'],
                'unlocked' => $row !== null,
                'unlocked_at' => $row?->unlocked_at?->toIso8601String(),
            ];
        }, self::CATALOG);

        // Append any awarded achievements not in the local catalog (e.g. py-service
        // shipped a new achievement before backend deploy). They show as unlocked.
        $catalogKeys = array_column(self::CATALOG, 'key');
        foreach ($unlockedByKey as $key => $row) {
            if (in_array($key, $catalogKeys, true)) {
                continue;
            }
            $achievements[] = [
                'key' => (string) $key,
                'name' => (string) $row->achievement_name,
                'description' => '',
                'unlocked' => true,
                'unlocked_at' => $row->unlocked_at?->toIso8601String(),
            ];
        }

        $unlockedCount = count(array_filter($achievements, fn ($a) => $a['unlocked']));

        return response()->json([
            'achievements' => $achievements,
            'unlocked' => $unlockedCount,
            'total' => count($achievements),
        ]);
    }
}
