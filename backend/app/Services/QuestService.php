<?php

namespace App\Services;

use App\Models\CardPlay;
use App\Models\DailyLog;
use App\Models\DailyQuest;
use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/quests.ts (simplified).
 *
 * Original loads quest pool from app_config.quest_definitions JSON. Pending
 * seed batch we use a minimal hard-coded pool (TODO: parity — load from
 * app_config table once that exists, with rarity weighting).
 */
class QuestService
{
    /** @var list<array{key:string, label:string, emoji:string, target:int, reward_xp:int, progress_metric:string, rarity:string}> */
    public const POOL = [
        ['key' => 'log_meals_1',      'label' => '記錄 1 餐',      'emoji' => '🍽️', 'target' => 1,    'reward_xp' => 5,  'progress_metric' => 'meals_today',           'rarity' => 'common'],
        ['key' => 'log_meals_3',      'label' => '記錄 3 餐',      'emoji' => '🍱', 'target' => 3,    'reward_xp' => 15, 'progress_metric' => 'meals_today',           'rarity' => 'rare'],
        ['key' => 'drink_water_1500', 'label' => '喝水 1500ml',    'emoji' => '💧', 'target' => 1500, 'reward_xp' => 8,  'progress_metric' => 'water_ml',              'rarity' => 'common'],
        ['key' => 'drink_water_2000', 'label' => '喝水 2000ml',    'emoji' => '🚰', 'target' => 2000, 'reward_xp' => 12, 'progress_metric' => 'water_ml',              'rarity' => 'rare'],
        ['key' => 'exercise_15',      'label' => '運動 15 分鐘',   'emoji' => '🏃', 'target' => 15,   'reward_xp' => 10, 'progress_metric' => 'exercise_min',          'rarity' => 'common'],
        ['key' => 'exercise_30',      'label' => '運動 30 分鐘',   'emoji' => '💪', 'target' => 30,   'reward_xp' => 18, 'progress_metric' => 'exercise_min',          'rarity' => 'rare'],
        ['key' => 'answer_cards_1',   'label' => '答 1 張卡',      'emoji' => '🃏', 'target' => 1,    'reward_xp' => 5,  'progress_metric' => 'cards_answered_today',  'rarity' => 'common'],
    ];

    public function __construct(
        private readonly JourneyService $journey,
        private readonly ?AppConfigService $config = null,
    ) {}

    /**
     * Pool resolution order:
     *   1. app_config.quest_definitions (runtime-editable, optional)
     *   2. self::POOL (compile-time fallback)
     *
     * @return list<array{key:string,label:string,emoji:string,target:int,reward_xp:int,progress_metric:string,rarity:string}>
     */
    private function pool(): array
    {
        if ($this->config) {
            $remote = $this->config->get('quest_definitions');
            if (is_array($remote) && ! empty($remote)) {
                $normalised = [];
                foreach ($remote as $q) {
                    if (! is_array($q) || empty($q['key'])) {
                        continue;
                    }
                    $normalised[] = [
                        'key' => (string) $q['key'],
                        'label' => (string) ($q['label'] ?? $q['key']),
                        'emoji' => (string) ($q['emoji'] ?? '⭐'),
                        'target' => (int) ($q['target'] ?? 1),
                        'reward_xp' => (int) ($q['reward_xp'] ?? 5),
                        'progress_metric' => (string) ($q['progress_metric'] ?? 'meals_today'),
                        'rarity' => (string) ($q['rarity'] ?? 'common'),
                    ];
                }
                if (! empty($normalised)) {
                    return $normalised;
                }
            }
        }

        return self::POOL;
    }

    private function dailyPick(string $userKey, string $date): array
    {
        $seed = 0;
        foreach (str_split($userKey.$date) as $c) {
            $seed = (($seed * 31) + ord($c)) & 0x7FFFFFFF;
        }
        $pool = $this->pool();
        $commons = array_values(array_filter($pool, fn ($q) => $q['rarity'] === 'common'));
        $rares = array_values(array_filter($pool, fn ($q) => $q['rarity'] === 'rare'));
        $rng = function () use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

            return $seed;
        };
        $picked = [];
        for ($i = 0; $i < 2 && count($commons) > 0; $i++) {
            $idx = $rng() % count($commons);
            $picked[] = $commons[$idx];
            array_splice($commons, $idx, 1);
        }
        if (count($rares) > 0) {
            $idx = $rng() % count($rares);
            $picked[] = $rares[$idx];
        }

        return $picked;
    }

    private function ensureToday(User $user, string $date): void
    {
        // Phase D Wave 2: read by uuid; dual-write on create
        $count = DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)->whereDate('date', $date)->count();
        if ($count > 0) {
            return;
        }
        $picked = $this->dailyPick((string) $user->id, $date);
        foreach ($picked as $q) {
            DailyQuest::create([
                'user_id' => $user->id,
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'date' => $date,
                'quest_key' => $q['key'],
                'target' => $q['target'],
                'progress' => 0,
                'reward_xp' => $q['reward_xp'],
            ]);
        }
    }

    private function refreshProgress(User $user, string $date): void
    {
        // Phase D Wave 2: all reads via uuid
        $log = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->whereDate('date', $date)->first();
        $mealsToday = (int) ($log->meals_logged ?? 0);
        $waterMl = (int) ($log->water_ml ?? 0);
        $exerciseMin = (int) ($log->exercise_minutes ?? 0);

        $cardsAnswered = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $date)
            ->whereNotNull('answered_at')
            ->count();

        $metricMap = [
            'meals_today' => $mealsToday,
            'water_ml' => $waterMl,
            'exercise_min' => $exerciseMin,
            'cards_answered_today' => $cardsAnswered,
            'unique_stores_today' => 0,
            'new_store_today' => 0,
            'intents_today' => 0,
        ];

        foreach ($this->pool() as $q) {
            $value = $metricMap[$q['progress_metric']] ?? 0;
            DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)
                ->whereDate('date', $date)
                ->where('quest_key', $q['key'])
                ->update(['progress' => $value]);
        }

        $justDone = DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $date)
            ->whereColumn('progress', '>=', 'target')
            ->whereNull('completed_at')
            ->get();

        foreach ($justDone as $q) {
            $q->completed_at = now();
            $q->save();
            if ((int) $q->reward_xp > 0) {
                // ADR-009 Phase B.3
                app(\App\Services\Gamification\LocalXpWriter::class)
                    ->apply($user, (int) $q->reward_xp);
            }
            $this->journey->tryAdvance($user, 'daily_quest');
        }
    }

    public function listToday(User $user): array
    {
        $date = Carbon::today()->toDateString();
        $this->ensureToday($user, $date);
        $this->refreshProgress($user, $date);

        $rows = DailyQuest::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $date)
            ->orderBy('target')
            ->get();

        $byKey = [];
        foreach ($this->pool() as $q) {
            $byKey[$q['key']] = $q;
        }

        $quests = $rows->map(function (DailyQuest $r) use ($byKey) {
            $def = $byKey[$r->quest_key] ?? null;

            return [
                'key' => $r->quest_key,
                'label' => $def['label'] ?? $r->quest_key,
                'emoji' => $def['emoji'] ?? '⭐',
                'target' => (int) $r->target,
                'progress' => min((int) $r->target, (int) $r->progress),
                'completed' => (bool) $r->completed_at,
                'reward_xp' => (int) $r->reward_xp,
                'rarity' => $def['rarity'] ?? 'common',
            ];
        })->all();

        return [
            'quests' => $quests,
            'all_completed' => count($quests) > 0 && collect($quests)->every(fn ($q) => $q['completed']),
        ];
    }
}
