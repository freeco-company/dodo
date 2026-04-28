<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/chat_starters.ts.
 *
 * Context-aware welcome message + quick reply chips. Pure deterministic logic
 * (no AI required) so this endpoint stays usable while AI service is down.
 */
class ChatStarterService
{
    private const MASCOT_NAMES = [
        'cat' => '貓貓', 'rabbit' => '兔兔', 'bear' => '熊熊',
        'hamster' => '倉鼠', 'fox' => '狐狸', 'shiba' => '柴犬',
        'dinosaur' => '恐龍', 'penguin' => '企鵝', 'tuxedo' => '賓士貓',
    ];

    public function welcome(User $user): string
    {
        $hour = (int) Carbon::now()->format('G');
        $name = $user->name;
        $streak = (int) $user->current_streak;
        $mascot = self::MASCOT_NAMES[$user->avatar_animal] ?? '夥伴';

        $daily = $this->todayLog($user);
        $loggedToday = ($daily?->meals_logged ?? 0) > 0;

        // Streak milestone takes precedence over time-of-day greeting.
        if ($streak >= 7) {
            return "{$name}！連續 {$streak} 天了真的超棒 🔥 今天想跟夥伴聊什麼？";
        }
        if ($hour >= 22 || $hour < 5) {
            return "晚安 {$name}～夥伴開始想睡了 🌙 今天辛苦囉，有什麼想聊的嗎？";
        }
        if (! $loggedToday && $hour >= 11) {
            return "嗨 {$name}～今天還沒記錄餐食喔 🍽️ 想吃什麼{$mascot}可以幫你挑！";
        }
        if ($hour < 10) {
            return "早安 {$name} ☀️ 新的一天！{$mascot}在這裡陪你～有需要幫忙的嗎？";
        }
        if ($hour >= 17 && $hour < 20) {
            return "傍晚了～今天過得怎樣？想跟{$mascot}說說嗎 🫶";
        }

        return "嗨 {$name}！{$mascot}在這裡～有什麼想聊或想問的嗎？";
    }

    /** @return list<array{emoji:string,text:string}> */
    public function starters(User $user): array
    {
        $hour = (int) Carbon::now()->format('G');
        $daily = $this->todayLog($user);
        $remaining = (int) ($user->daily_calorie_target ?? 1800) - (int) ($daily?->total_calories ?? 0);

        $pool = [];

        // Meal-time situational
        if ($hour >= 10 && $hour < 14) {
            $pool[] = ['emoji' => '🍱', 'text' => '午餐吃什麼好？'];
        } elseif ($hour >= 17 && $hour < 20) {
            $pool[] = ['emoji' => '🍲', 'text' => '晚餐推薦？'];
        } elseif ($hour < 10) {
            $pool[] = ['emoji' => '🥐', 'text' => '早餐吃什麼好？'];
        }

        // Calorie budget
        if ($remaining > 0 && $remaining < 500) {
            $pool[] = ['emoji' => '🥗', 'text' => "還剩 {$remaining} 卡，能吃什麼？"];
        } elseif ($remaining < 0) {
            $pool[] = ['emoji' => '😰', 'text' => '今天吃太多了怎麼辦？'];
        }

        // Generic
        $pool[] = ['emoji' => '🧋', 'text' => '想喝手搖'];
        $pool[] = ['emoji' => '🏪', 'text' => '在超商能吃什麼？'];
        $pool[] = ['emoji' => '🍻', 'text' => '今天要聚餐怎麼辦'];
        $pool[] = ['emoji' => '💪', 'text' => '幫我看看今天的進度'];
        $pool[] = ['emoji' => '😮‍💨', 'text' => '好累'];
        $pool[] = ['emoji' => '🥺', 'text' => '感覺沒進步'];
        $pool[] = ['emoji' => '❓', 'text' => '蛋白質要吃多少？'];
        $pool[] = ['emoji' => '📉', 'text' => '體重停滯怎麼辦？'];

        return array_slice($pool, 0, 8);
    }

    private function todayLog(User $user): ?DailyLog
    {
        // Phase D Wave 2: read by uuid
        return DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
    }
}
