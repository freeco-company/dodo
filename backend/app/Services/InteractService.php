<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;

/**
 * Translated from ai-game/src/services/interact.ts.
 * Pet character (capped) + daily Pandora gift (random reward pool).
 */
class InteractService
{
    private const PET_CAP_PER_DAY = 5;
    private const PET_FRIENDSHIP = 2;

    /** @var list<array{kind:string, weight:int}> */
    private const GIFT_POOL = [
        ['kind' => 'xp_small',   'weight' => 30],
        ['kind' => 'xp_big',     'weight' => 15],
        ['kind' => 'friendship', 'weight' => 25],
        ['kind' => 'shield',     'weight' => 10],
        ['kind' => 'fortune',    'weight' => 20],
    ];

    /** @var array<string, array{spirit:string, msg:string}> */
    private const SPIRIT_FORTUNES = [
        'cat'      => ['spirit' => '貓貓',   'msg' => '今天身體的聲音值得被聽見，不用勉強吃完不愛的那口。'],
        'rabbit'   => ['spirit' => '兔兔',   'msg' => '輕輕地，不用急。春風從來不在意時間。'],
        'bear'     => ['spirit' => '熊熊',   'msg' => '允許自己被照顧，今晚早點睡也算進步。'],
        'hamster'  => ['spirit' => '倉鼠',   'msg' => '你今天多走的那 100 步，一年後會變成 36,500 步。'],
        'fox'      => ['spirit' => '狐狸',   'msg' => '別被「健康標章」騙了，看營養標示才是真的。'],
        'shiba'    => ['spirit' => '柴犬',   'msg' => '走出去散 10 分鐘，真的就夠了～'],
        'dinosaur' => ['spirit' => '恐龍',   'msg' => '億年的觀點看來，你現在擔心的事情，都很小。'],
        'penguin'  => ['spirit' => '企鵝',   'msg' => '跌倒不重要，爬起來繼續搖搖晃晃前進就好。'],
        'tuxedo'   => ['spirit' => '賓士貓', 'msg' => '優雅地拒絕第二份蛋糕，你值得更好的。'],
    ];

    /** @return array{friendship:int, pet_count:int, capped:bool, message:string} */
    public function pet(User $user): array
    {
        $today = Carbon::today()->toDateString();
        $count = (int) $user->daily_pet_count;
        if (optional($user->last_pet_date)->toDateString() !== $today) {
            $count = 0;
        }
        $animal = (string) ($user->avatar_animal ?? 'cat');
        if ($count >= self::PET_CAP_PER_DAY) {
            return [
                'friendship' => (int) $user->friendship,
                'pet_count' => $count,
                'capped' => true,
                'message' => "今天被摸夠了～害羞躲起來 🙈 明天再來！",
            ];
        }
        $newCount = $count + 1;
        $newFriendship = (int) $user->friendship + self::PET_FRIENDSHIP;
        $user->friendship = $newFriendship;
        $user->daily_pet_count = $newCount;
        $user->last_pet_date = $today;
        $user->save();

        $msgs = ['好舒服～😌', '呼嚕呼嚕...', '最喜歡你了 💕', '心情變好了！', '❤️‍🔥'];
        return [
            'friendship' => $newFriendship,
            'pet_count' => $newCount,
            'capped' => false,
            'message' => $msgs[min($newCount - 1, count($msgs) - 1)],
        ];
    }

    private function pickGiftKind(): string
    {
        $total = array_sum(array_column(self::GIFT_POOL, 'weight'));
        $r = random_int(0, $total - 1);
        foreach (self::GIFT_POOL as $g) {
            $r -= $g['weight'];
            if ($r < 0) return $g['kind'];
        }
        return 'xp_small';
    }

    private function buildReward(string $kind, User $user): array
    {
        switch ($kind) {
            case 'xp_small':
                return ['kind' => $kind, 'title' => '小驚喜', 'subtitle' => '+10 XP', 'emoji' => '✨',
                    'xp_gained' => 10, 'friendship_gained' => 0, 'shield_gained' => 0];
            case 'xp_big':
                return ['kind' => $kind, 'title' => '大驚喜', 'subtitle' => '+25 XP', 'emoji' => '💎',
                    'xp_gained' => 25, 'friendship_gained' => 0, 'shield_gained' => 0];
            case 'friendship':
                return ['kind' => $kind, 'title' => '溫柔抱抱', 'subtitle' => '好感度 +8', 'emoji' => '💝',
                    'xp_gained' => 0, 'friendship_gained' => 8, 'shield_gained' => 0];
            case 'shield':
                return ['kind' => $kind, 'title' => '連續護盾 +1', 'subtitle' => '錯過一天也不怕了 🛡️', 'emoji' => '🛡️',
                    'xp_gained' => 0, 'friendship_gained' => 0, 'shield_gained' => 1];
            case 'fortune':
                $animal = (string) ($user->avatar_animal ?? 'cat');
                $f = self::SPIRIT_FORTUNES[$animal] ?? self::SPIRIT_FORTUNES['cat'];
                return [
                    'kind' => $kind,
                    'title' => "{$f['spirit']} 的話",
                    'subtitle' => $f['msg'],
                    'emoji' => '🔮',
                    'xp_gained' => 5,
                    'friendship_gained' => 2,
                    'shield_gained' => 0,
                    'fortune_spirit_key' => $animal,
                ];
        }
        return ['kind' => 'xp_small', 'title' => '小驚喜', 'subtitle' => '+10 XP', 'emoji' => '✨',
            'xp_gained' => 10, 'friendship_gained' => 0, 'shield_gained' => 0];
    }

    private function msUntilMidnight(): int
    {
        return (int) (Carbon::tomorrow()->getTimestampMs() - now()->getTimestampMs());
    }

    public function dailyGift(User $user): array
    {
        $today = Carbon::today()->toDateString();
        $nextMs = $this->msUntilMidnight();
        $nextAt = Carbon::tomorrow()->toIso8601String();

        if (optional($user->last_gift_date)->toDateString() === $today) {
            return [
                'claimed' => false,
                'current_xp' => (int) $user->xp,
                'level' => (int) $user->level,
                'next_available_in_ms' => $nextMs,
                'next_available_at' => $nextAt,
                'message' => '今天的潘朵拉盒子已經打開過了，明天請早 🎁',
            ];
        }

        $kind = $this->pickGiftKind();
        if ($kind === 'shield' && (int) $user->streak_shields >= 2) {
            $kind = 'xp_big';
        }
        $reward = $this->buildReward($kind, $user);

        // ADR-009 Phase B.3 — gate the xp/level mirror write
        if ((bool) config('services.pandora_gamification.local_xp_writes_enabled', true)) {
            $user->xp = (int) $user->xp + $reward['xp_gained'];
            $user->level = GameXp::levelForXp((int) $user->xp);
        }
        $user->friendship = (int) $user->friendship + $reward['friendship_gained'];
        $user->streak_shields = min(2, (int) $user->streak_shields + $reward['shield_gained']);
        $user->last_gift_date = $today;
        $user->save();

        return [
            'claimed' => true,
            'reward' => $reward,
            'current_xp' => (int) $user->xp,
            'level' => (int) $user->level,
            'next_available_in_ms' => $nextMs,
            'next_available_at' => $nextAt,
            'message' => "盒子打開了：{$reward['title']}",
        ];
    }

    public function giftStatus(User $user): array
    {
        $today = Carbon::today()->toDateString();
        return [
            'can_open' => optional($user->last_gift_date)->toDateString() !== $today,
            'next_available_in_ms' => $this->msUntilMidnight(),
            'next_available_at' => Carbon::tomorrow()->toIso8601String(),
        ];
    }
}
