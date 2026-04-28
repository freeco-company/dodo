<?php

namespace App\Services;

/**
 * Translated from ai-game/src/services/game.ts.
 * XP / level table + reward constants. Keep deterministic, no DB.
 */
class GameXp
{
    public const REWARDS = [
        'MEAL_LOGGED' => 5,
        'MEAL_SCORE_80_PLUS' => 10,
        'DAILY_SCORE_80_PLUS' => 15,
        'STREAK_3' => 20,
        'STREAK_7' => 50,
        'STREAK_14' => 100,
        'STREAK_30' => 200,
        'WEEKLY_REVIEW_READ' => 10,
        'CHAT_DAILY' => 3,
        'WEIGHT_LOGGED' => 5,
        'FIRST_MEAL_OF_DAY' => 5,
        'NEW_FOOD_DISCOVERED' => 8,
    ];

    /** @var list<array{level:int, xp:int, name:string, name_en:string}> */
    private const LEVEL_ANCHORS = [
        ['level' => 1, 'xp' => 0, 'name' => '種子期', 'name_en' => 'Seed'],
        ['level' => 2, 'xp' => 50, 'name' => '萌芽期', 'name_en' => 'Sprout'],
        ['level' => 3, 'xp' => 120, 'name' => '冒險期', 'name_en' => 'Explorer'],
        ['level' => 4, 'xp' => 200, 'name' => '學習期', 'name_en' => 'Learner'],
        ['level' => 5, 'xp' => 300, 'name' => '成長期', 'name_en' => 'Growing'],
        ['level' => 6, 'xp' => 400, 'name' => '前進期', 'name_en' => 'Advancing'],
        ['level' => 7, 'xp' => 500, 'name' => '穩紮期', 'name_en' => 'Rooted'],
        ['level' => 8, 'xp' => 600, 'name' => '微光期', 'name_en' => 'Glimmer'],
        ['level' => 9, 'xp' => 780, 'name' => '破繭期', 'name_en' => 'Breakthrough'],
        ['level' => 10, 'xp' => 1000, 'name' => '穩定期', 'name_en' => 'Steady'],
        ['level' => 15, 'xp' => 2000, 'name' => '蛻變期', 'name_en' => 'Transform'],
        ['level' => 20, 'xp' => 3500, 'name' => '綻放期', 'name_en' => 'Bloom'],
        ['level' => 30, 'xp' => 6000, 'name' => '閃耀期', 'name_en' => 'Radiant'],
        ['level' => 50, 'xp' => 12000, 'name' => '傳說期', 'name_en' => 'Legend'],
        ['level' => 100, 'xp' => 30000, 'name' => '永恆期', 'name_en' => 'Eternal'],
    ];

    /** @var list<array{level:int, xp:int, name:string, name_en:string}>|null */
    private static ?array $table = null;

    /** @return list<array{level:int, xp:int, name:string, name_en:string}> */
    private static function table(): array
    {
        if (self::$table !== null) {
            return self::$table;
        }
        $anchors = self::LEVEL_ANCHORS;
        $out = [];
        for ($i = 0; $i < count($anchors) - 1; $i++) {
            $a = $anchors[$i];
            $b = $anchors[$i + 1];
            $out[] = $a;
            $gap = $b['level'] - $a['level'];
            if ($gap > 1) {
                for ($k = 1; $k < $gap; $k++) {
                    $lv = $a['level'] + $k;
                    $xp = (int) round($a['xp'] + ($b['xp'] - $a['xp']) * $k / $gap);
                    $out[] = ['level' => $lv, 'xp' => $xp, 'name' => $a['name'], 'name_en' => $a['name_en']];
                }
            }
        }
        $out[] = $anchors[count($anchors) - 1];
        return self::$table = $out;
    }

    public static function levelForXp(int $xp): int
    {
        $found = self::table()[0]['level'];
        foreach (self::table() as $row) {
            if ($xp >= $row['xp']) {
                $found = $row['level'];
            } else {
                break;
            }
        }
        return $found;
    }

    /** @return array{level:int, name:string, name_en:string} */
    public static function levelDefForXp(int $xp): array
    {
        $found = self::table()[0];
        foreach (self::table() as $row) {
            if ($xp >= $row['xp']) {
                $found = $row;
            } else {
                break;
            }
        }
        return ['level' => $found['level'], 'name' => $found['name'], 'name_en' => $found['name_en']];
    }

    /** @return array{xpNeeded:int, progress:float}|null */
    public static function xpForNextLevel(int $xp): ?array
    {
        $table = self::table();
        $current = $table[0];
        $next = null;
        foreach ($table as $i => $row) {
            if ($xp >= $row['xp']) {
                $current = $row;
                $next = $table[$i + 1] ?? null;
            } else {
                break;
            }
        }
        if ($next === null) {
            return null;
        }
        $span = max(1, $next['xp'] - $current['xp']);
        $into = $xp - $current['xp'];
        return [
            'xpNeeded' => $next['xp'] - $xp,
            'progress' => max(0.0, min(1.0, $into / $span)),
        ];
    }
}
