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
    /** @var list<array{key:string, name:string, description:string}> */
    private const CATALOG = [
        // 起步 (onboarding)
        ['key' => 'first_meal', 'name' => '第一餐', 'description' => '記錄第一次餐點'],
        ['key' => 'first_water', 'name' => '第一杯水', 'description' => '記錄第一次喝水'],
        ['key' => 'first_weight', 'name' => '第一次量體重', 'description' => '完成第一次體重紀錄'],

        // 連勝 (streaks)
        ['key' => 'streak_3', 'name' => '三日連勝', 'description' => '連續三天打卡'],
        ['key' => 'streak_7', 'name' => '一週有你', 'description' => '連續七天打卡'],
        ['key' => 'streak_14', 'name' => '雙週鐵粉', 'description' => '連續 14 天打卡'],
        ['key' => 'streak_30', 'name' => '滿月達人', 'description' => '連續 30 天打卡'],

        // 飲食記錄
        ['key' => 'foodie_10', 'name' => '美食家 10', 'description' => '收集 10 種食物'],
        ['key' => 'foodie_30', 'name' => '美食家 30', 'description' => '收集 30 種食物'],
        ['key' => 'foodie_50', 'name' => '美食家 50', 'description' => '收集 50 種食物'],
        ['key' => 'shiny_first', 'name' => '初次閃光', 'description' => '解鎖第一個閃光食物 ✨'],

        // 評分 / 品質
        ['key' => 'perfect_day', 'name' => '完美一日', 'description' => '單日飲食評分 80 分以上'],
        ['key' => 'perfect_week', 'name' => '完美一週', 'description' => '累積 7 個完美日'],
        ['key' => 'perfect_month', 'name' => '完美一月', 'description' => '累積 30 個完美日'],

        // 卡牌 / 知識
        ['key' => 'card_first_correct', 'name' => '初試身手', 'description' => '答對第一張知識卡'],
        ['key' => 'card_streak_5', 'name' => '答題連發', 'description' => '連續答對 5 張卡'],
        ['key' => 'kb_explorer', 'name' => '知識探險', 'description' => '閱讀 10 篇知識文章'],

        // 等級 / 進化
        ['key' => 'level_5', 'name' => 'LV.5 新手出師', 'description' => '練到第 5 級'],
        ['key' => 'level_10', 'name' => 'LV.10 進階夥伴', 'description' => '練到第 10 級'],
        ['key' => 'level_20', 'name' => 'LV.20 高手', 'description' => '練到第 20 級'],

        // 體重旅程
        ['key' => 'weight_minus_3', 'name' => '減 3 公斤', 'description' => '從起點減去 3 公斤'],
        ['key' => 'weight_minus_5', 'name' => '減 5 公斤', 'description' => '從起點減去 5 公斤'],

        // 社群 / 旅程
        ['key' => 'journey_day_7', 'name' => '旅程第 7 天', 'description' => '21 天旅程走到第 7 天'],
        ['key' => 'journey_day_21', 'name' => '完成 21 天旅程', 'description' => '走完一輪 21 天'],
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
