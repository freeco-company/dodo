<?php

namespace App\Services;

use App\Models\JourneyAdvance;
use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/journey.ts.
 * 21-day habit journey, milestones at 3/7/14/21, loops into next cycle.
 */
class JourneyService
{
    public const LENGTH = 21;

    /** @var list<array{day:int, label:string, reward_xp:int, reward_emoji:string, reward_kind:string}> */
    public const MILESTONES = [
        ['day' => 3,  'label' => '三日小步', 'reward_xp' => 20,  'reward_emoji' => '🌱', 'reward_kind' => 'xp'],
        ['day' => 7,  'label' => '一週有你', 'reward_xp' => 50,  'reward_emoji' => '🔥', 'reward_kind' => 'title'],
        ['day' => 14, 'label' => '雙週達人', 'reward_xp' => 80,  'reward_emoji' => '💎', 'reward_kind' => 'card'],
        ['day' => 21, 'label' => '三週里程', 'reward_xp' => 150, 'reward_emoji' => '👑', 'reward_kind' => 'outfit'],
    ];

    public function getJourney(User $user): array
    {
        $today = Carbon::today()->toDateString();
        $cycle = (int) ($user->journey_cycle ?? 1);
        $day = (int) ($user->journey_day ?? 0);
        $advancedToday = optional($user->journey_last_advance_date)->toDateString() === $today;

        $milestones = array_map(
            fn ($m) => $m + ['achieved_this_cycle' => $day >= $m['day']],
            self::MILESTONES
        );
        $next = null;
        foreach (self::MILESTONES as $m) {
            if ($m['day'] > $day) {
                $next = $m;
                break;
            }
        }

        // Phase D Wave 2: read by uuid
        $recent = JourneyAdvance::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('cycle', $cycle)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['day', 'reason', 'created_at'])
            ->map(fn ($r) => [
                'day' => (int) $r->day,
                'reason' => $r->reason,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all();

        return [
            'cycle' => $cycle,
            'day' => $day,
            'total_days' => self::LENGTH,
            'advanced_today' => $advancedToday,
            'next_milestone' => $next,
            'days_to_next_milestone' => $next ? max(0, $next['day'] - $day) : 0,
            'milestones' => $milestones,
            'started_at' => optional($user->journey_started_at)->toIso8601String(),
            'cycle_progress_pct' => (int) round(($day / self::LENGTH) * 100),
            'recent_advances' => $recent,
        ];
    }

    public function advance(User $user, string $reason): array
    {
        $allowed = ['meal_log', 'water', 'exercise', 'card_correct', 'daily_quest'];
        if (! in_array($reason, $allowed, true)) {
            throw new \InvalidArgumentException('INVALID_REASON');
        }

        $today = Carbon::today()->toDateString();
        $alreadyAdvanced = optional($user->journey_last_advance_date)->toDateString() === $today;

        $out = [
            'advanced' => false,
            'reason' => $reason,
            'new_day' => (int) ($user->journey_day ?? 0),
            'cycle' => (int) ($user->journey_cycle ?? 1),
            'cycle_completed' => false,
            'milestones_crossed' => [],
            'xp_gained' => 0,
        ];
        if ($alreadyAdvanced) {
            return $out;
        }

        $nextDay = (int) ($user->journey_day ?? 0) + 1;
        $nextCycle = (int) ($user->journey_cycle ?? 1);
        $cycleCompleted = false;
        if ($nextDay > self::LENGTH) {
            $nextCycle += 1;
            $nextDay = 1;
            $cycleCompleted = true;
        }

        $crossed = array_values(array_filter(
            self::MILESTONES,
            fn ($m) => $m['day'] === $nextDay,
        ));
        $xpGained = array_sum(array_column($crossed, 'reward_xp'));

        $user->journey_day = $nextDay;
        $user->journey_cycle = $nextCycle;
        $user->journey_last_advance_date = $today;
        if (! $user->journey_started_at) {
            $user->journey_started_at = now();
        }
        if ($xpGained > 0) {
            $user->xp = (int) $user->xp + $xpGained;
            $user->level = GameXp::levelForXp((int) $user->xp);
        }
        $user->save();

        JourneyAdvance::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'cycle' => $nextCycle,
            'day' => $nextDay,
            'reason' => $reason,
        ]);

        return [
            'advanced' => true,
            'reason' => $reason,
            'new_day' => $nextDay,
            'cycle' => $nextCycle,
            'cycle_completed' => $cycleCompleted,
            'milestones_crossed' => $crossed,
            'xp_gained' => $xpGained,
        ];
    }

    /** Silent helper used by other services. */
    public function tryAdvance(User $user, string $reason): ?array
    {
        try {
            return $this->advance($user, $reason);
        } catch (\Throwable) {
            return null;
        }
    }
}
