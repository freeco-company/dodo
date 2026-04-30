<?php

namespace App\Services;

use App\Models\StoreVisit;
use App\Models\User;

/**
 * Island chapter system — wraps the existing flat 12-store map into 6 themed
 * story chapters + 1 special FP partner zone (separate from main story arc).
 *
 * 2026-04-30 — User pivot: FP 夥伴聖殿 不是終章，是品牌漏斗特殊區。 6 章故事是
 * 給每個用戶的旅程；FP 區是給 FP 會員 / 想了解加盟的朋友的特殊入口（ADR-008
 * sensitivity rules apply — 不主動推銷, opt-out aware）。
 *
 * Chapters are **code-defined** (not migrated tables) for speed of iteration —
 * narrative copy + boss quest definitions belong with code anyway. Future
 * iterations may extract to Filament-editable AppConfig if PM wants live tweaks.
 *
 * Progression rule: chapter unlocked when user_level ≥ min_level. Chapter
 * "completed" = visited every store in chapter at least once + boss_quest met
 * (boss check is stub — Phase 5 will integrate with KB quiz / quest engine).
 *
 * 朵朵 voice on intro / outro lines — follows group-naming-and-voice.md
 * (妳 / 朋友 / 朵朵 NPC, never 您 / 會員).
 */
class IslandChapterService
{
    /**
     * The 7 chapters of 「潘朵拉飲食島 · 七章覺醒」.
     *
     * @return list<array<string, mixed>>
     */
    public function chapterDefinitions(): array
    {
        return [
            [
                'key' => 'awakening',
                'order' => 1,
                'icon' => '🌱',
                'name' => '初遇島',
                'subtitle' => '第一章 · 新手村',
                'intro' => '朵朵漂流到一座神奇的島嶼，第一個學會的本領就是——看清楚自己吃了什麼。',
                'theme' => '認識自己的飲食 / 開始記錄',
                'min_level' => 1,
                'store_keys' => ['familymart'],
                'boss' => [
                    'key' => 'log_first_3_meals',
                    'title' => '記錄前 3 餐',
                    'goal' => '在便利商店分頁吃下 3 樣食物並打卡',
                ],
                'reward' => '解鎖第二章 + 朵朵小書籤 ×1',
            ],
            [
                'key' => 'smart_convenience',
                'order' => 2,
                'icon' => '🏪',
                'name' => '智慧便利區',
                'subtitle' => '第二章 · 標籤之眼',
                'intro' => '便利商店不等於亂吃。學會看一眼營養標，妳會發現整個世界都不一樣了。',
                'theme' => '營養標籤閱讀 / 蛋白質達標',
                'min_level' => 3,
                'store_keys' => ['seven_eleven', 'pxmart'],
                'boss' => [
                    'key' => 'protein_streak_3',
                    'title' => '連續 3 天蛋白質達標',
                    'goal' => '在 7 天內累積 3 天蛋白質達到設定目標',
                ],
                'reward' => '蛋白質達人徽章 + 解鎖第三章',
            ],
            [
                'key' => 'fast_food_trial',
                'order' => 3,
                'icon' => '🍔',
                'name' => '速食試煉之谷',
                'subtitle' => '第三章 · 取捨之道',
                'intro' => '速食不可怕——可怕的是不知道自己在點什麼。會選的人，吃什麼都自由。',
                'theme' => '份量 / 替換選項 / 醬料管理',
                'min_level' => 5,
                'store_keys' => ['mcdonalds', 'kfc'],
                'boss' => [
                    'key' => 'low_cal_meal_3',
                    'title' => '挑選 3 次低卡套餐',
                    'goal' => '一週內挑選 3 次熱量 < 600 kcal 的速食組合',
                ],
                'reward' => '速食智者徽章 + 解鎖第四章',
            ],
            [
                'key' => 'beverage_maze',
                'order' => 4,
                'icon' => '☕',
                'name' => '飲品迷宮',
                'subtitle' => '第四章 · 隱形糖之戰',
                'intro' => '一杯飲料的糖，可能比一頓正餐還多。看清楚這件事，就贏了一半。',
                'theme' => '糖量 / 奶選擇 / 看穿手搖陷阱',
                'min_level' => 8,
                'store_keys' => ['starbucks', 'bubble_tea'],
                'boss' => [
                    'key' => 'sugar_low_streak_5',
                    'title' => '連續 5 天糖 < 30g',
                    'goal' => '一週內任 5 天每日糖攝取 < 30g',
                ],
                'reward' => '糖量觀察家徽章 + 解鎖第五章',
            ],
            [
                'key' => 'night_market',
                'order' => 5,
                'icon' => '🏮',
                'name' => '夜市夜行',
                'subtitle' => '第五章 · 社交餐桌',
                'intro' => '跟朋友吃飯不用變成苦行僧。夜市裡也有妳的選擇——朵朵教妳。',
                'theme' => '社交飲食 / 共享餐桌 / 局裡選食',
                'min_level' => 12,
                'store_keys' => ['night_market', 'sushi_box'],
                'boss' => [
                    'key' => 'social_scenario_3',
                    'title' => '完成 3 張社交餐 scenario card',
                    'goal' => '在卡牌系統完成 3 張「跟朋友聚餐」情境',
                ],
                'reward' => '社交餐達人徽章 + 解鎖第六章',
            ],
            [
                'key' => 'healthy_kitchen',
                'order' => 6,
                'icon' => '🥗',
                'name' => '健康日料區',
                'subtitle' => '第六章 · 主動選擇 · 終章',
                'intro' => '妳已經能看穿大多數陷阱了。最後一步：主動選——不只避開壞的，還要追求好的。',
                'theme' => '進階主動選擇 / 自煮 / 食材搭配',
                'min_level' => 15,
                'store_keys' => ['healthy_box'],
                'boss' => [
                    'key' => 'avg_score_75_30d',
                    'title' => '30 天平均分數 ≥ 75',
                    'goal' => '過去 30 天每日總分平均達 75 以上',
                ],
                'reward' => '飲食大師徽章 + 朵朵畢業信',
            ],
        ];
    }

    /**
     * FP 夥伴專區 — 獨立於 6 章故事之外的「品牌漏斗特殊區」。
     *
     * - FP 會員 (membership_tier = fp_lifetime): 解鎖完整 fp_shop / fp_base 體驗
     * - 非 FP: 顯示「想了解 FP 夥伴計畫？」入口（ADR-008 sensitivity — gentle / opt-outable）
     *
     * 2026-04-30：明確不放在章節 array 中，避免「終章」誤導。
     *
     * @return array<string, mixed>
     */
    public function fpZone(User $user): array
    {
        $tier = $user->membership_tier ?? 'free';
        $isFp = $tier === 'fp_lifetime';

        return [
            'key' => 'fp_zone',
            'icon' => '✨',
            'name' => 'FP 夥伴專區',
            'subtitle' => $isFp ? 'FP 永久會員 · 已解鎖' : '加盟後解鎖',
            'intro' => $isFp
                ? '夥伴空間 — 妳是 FP 大家庭的一員。fp_shop 和 fp_base 等妳。'
                : '想知道朵朵這套系統怎麼幫妳自用回本？這裡是 FP 夥伴入口，有興趣再點進來看看。',
            'theme' => '品牌漏斗 / FP 自用回本 / 經營後盾',
            'store_keys' => ['fp_shop', 'fp_base'],
            'is_fp_member' => $isFp,
            'unlocked' => $isFp,
            'cta_label' => $isFp ? '進入夥伴空間' : '了解 FP 夥伴',
            'cta_kind' => $isFp ? 'enter' : 'inquire', // frontend uses 'inquire' to open franchise CTA flow
            'note' => $isFp ? null : 'ADR-008：FP CTA 不主動推銷 / 不感興趣可永久關閉 / 30 天 cooldown',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chaptersForUser(User $user): array
    {
        $chapters = $this->chapterDefinitions();
        $level = (int) ($user->level ?? 1);

        $visits = StoreVisit::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->get(['store_key', 'visit_count'])
            ->keyBy('store_key');

        $out = [];
        $unlockedFar = true;
        foreach ($chapters as $c) {
            $unlocked = $level >= $c['min_level'];
            $storesVisited = 0;
            foreach ($c['store_keys'] as $sk) {
                $visit = $visits->get($sk);
                if ($visit && (int) $visit->visit_count > 0) {
                    $storesVisited++;
                }
            }
            $totalStores = count($c['store_keys']);
            $storeProgress = $totalStores === 0 ? 0 : round(($storesVisited / $totalStores) * 100);
            // Chapter "completed" stub — Phase 5 will hook real boss check
            $bossDone = $totalStores > 0 && $storesVisited === $totalStores;
            $status = ! $unlocked ? 'locked' : ($bossDone ? 'completed' : 'in_progress');

            $out[] = [
                'key' => $c['key'],
                'order' => $c['order'],
                'icon' => $c['icon'],
                'name' => $c['name'],
                'subtitle' => $c['subtitle'],
                'intro' => $c['intro'],
                'theme' => $c['theme'],
                'min_level' => $c['min_level'],
                'user_level' => $level,
                'unlocked' => $unlocked,
                'status' => $status,
                'store_keys' => $c['store_keys'],
                'stores_visited' => $storesVisited,
                'stores_total' => $totalStores,
                'store_progress_percent' => $storeProgress,
                'boss' => $c['boss'],
                'boss_completed' => $bossDone,
                'reward' => $c['reward'],
                'is_current' => $unlocked && ! $bossDone && $unlockedFar,
            ];
            if ($unlocked && ! $bossDone) {
                $unlockedFar = false;
            }
        }

        return [
            'chapters' => $out,
            'user_level' => $level,
            'next_unlock' => $this->nextUnlockHint($out, $level),
            'fp_zone' => $this->fpZone($user),
        ];
    }

    private function nextUnlockHint(array $chapters, int $level): ?array
    {
        foreach ($chapters as $c) {
            if (! $c['unlocked']) {
                return [
                    'chapter_key' => $c['key'],
                    'chapter_name' => $c['name'],
                    'min_level' => $c['min_level'],
                    'levels_away' => $c['min_level'] - $level,
                ];
            }
        }

        return null;
    }
}
