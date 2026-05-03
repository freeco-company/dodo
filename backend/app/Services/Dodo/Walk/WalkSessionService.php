<?php

namespace App\Services\Dodo\Walk;

use App\Models\DailyWalkSession;
use App\Models\MiniDodoCollection;
use App\Models\User;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SPEC-pikmin-walk-v1 — 步數階段 + mini-dodo 召喚 + 既有遊戲化系統勾連。
 *
 * 流程：
 *  1) sync(steps) → upsert daily_walk_sessions (today)
 *  2) 階段升級 → MiniDodoSummoner.summonForStepsPhase()
 *  3) 階段 = fruit 且 goal_published=false → publish meal.steps_goal_achieved
 *  4) 順手吃當日 meals 推 macro mini-dodo（meal log 寫入時也會被 hook 呼叫，這裡是
 *     步數同步路徑的補強：兩條路徑都召喚 → unique 鍵保證不重複）
 *
 * 為什麼不直接從 health_metrics 算：health_metrics 是原始事件流（多筆/天），
 * daily_walk_sessions 是 game state（每天一筆 + phase + flags）。Game state 拆出來後
 * idempotent gamification publish + home widget 撈一次都簡單。
 */
class WalkSessionService
{
    public const STEPS_GOAL = 8000; // align with SPEC §1: phase=fruit 同門檻

    public function __construct(
        private readonly MiniDodoSummoner $summoner,
        private readonly GamificationPublisher $gamification,
    ) {}

    /**
     * 同步 today 的步數總值（接 native plugin / 手動）。
     *
     * @return array{
     *   session: DailyWalkSession,
     *   newly_summoned: list<MiniDodoCollection>,
     *   phase_advanced: bool,
     *   goal_published_now: bool
     * }
     */
    public function sync(User $user, Carbon $date, int $totalSteps): array
    {
        $totalSteps = max(0, $totalSteps);

        return DB::transaction(function () use ($user, $date, $totalSteps) {
            $session = DailyWalkSession::where('user_id', $user->id)
                ->whereDate('walk_date', $date->toDateString())
                ->first();
            $isNew = $session === null;
            if ($isNew) {
                $session = new DailyWalkSession([
                    'user_id' => $user->id,
                    'walk_date' => $date->toDateString(),
                ]);
            }
            $previousPhase = $session->peak_phase ?? DailyWalkSession::PHASE_SEED;

            // Steps 永不下修（HealthKit 同步偶會回傳較舊 snapshot）；只接受新高。
            if (! $isNew && $totalSteps < (int) $session->total_steps) {
                $totalSteps = (int) $session->total_steps;
            }

            $newPhase = DailyWalkSession::phaseFromSteps($totalSteps);
            $phaseAdvanced = DailyWalkSession::phaseIsHigher($newPhase, $previousPhase);

            $session->total_steps = $totalSteps;
            $session->peak_phase = $newPhase;
            $session->last_synced_at = Carbon::now();
            $session->save();

            // 階段升級才召喚（避免每次 sync 都 race-create）
            $newlySummoned = [];
            if ($phaseAdvanced) {
                $newlySummoned = $this->summoner->summonForStepsPhase($user, $date, $newPhase, $totalSteps);
            }

            // Publish 一次性：到 fruit 且還沒 publish 過
            $goalPublishedNow = false;
            if ($newPhase === DailyWalkSession::PHASE_FRUIT && ! $session->goal_published) {
                $this->publishGoal($user, $date, $totalSteps);
                $session->goal_published = true;
                $session->save();
                $goalPublishedNow = true;
            }

            // 摘要寫進 session.mini_dodos_summoned_json（home widget 一次撈用）
            $this->refreshSummonedSummary($session);

            return [
                'session' => $session->fresh(),
                'newly_summoned' => $newlySummoned,
                'phase_advanced' => $phaseAdvanced,
                'goal_published_now' => $goalPublishedNow,
            ];
        });
    }

    /**
     * 根據 today meal log 推送 macro mini-dodo（meal write hook 用）。
     *
     * @return list<MiniDodoCollection>
     */
    public function summonForMealsToday(User $user, Carbon $date): array
    {
        $newly = $this->summoner->summonForMeals($user, $date);
        if ($newly === []) {
            return [];
        }

        $session = DailyWalkSession::where('user_id', $user->id)
            ->whereDate('walk_date', $date->toDateString())
            ->first();
        if ($session === null) {
            $session = DailyWalkSession::create([
                'user_id' => $user->id,
                'walk_date' => $date->toDateString(),
                'total_steps' => 0,
                'peak_phase' => DailyWalkSession::PHASE_SEED,
            ]);
        }
        $this->refreshSummonedSummary($session);

        return $newly;
    }

    /**
     * Today 的 game state（home widget / 探險 tab）。
     *
     * @return array{
     *   date: string,
     *   total_steps: int,
     *   phase: string,
     *   goal_steps: int,
     *   collected: list<array{color:string,source_kind:string,source_detail:?string,collected_at:string}>,
     *   collected_color_count: int
     * }
     */
    public function getToday(User $user, Carbon $date): array
    {
        $session = DailyWalkSession::where('user_id', $user->id)
            ->whereDate('walk_date', $date->toDateString())
            ->first();

        $totalSteps = $session !== null ? (int) $session->total_steps : 0;
        $phase = $session !== null ? (string) $session->peak_phase : DailyWalkSession::PHASE_SEED;

        $collections = MiniDodoCollection::where('user_id', $user->id)
            ->whereDate('collected_on', $date->toDateString())
            ->orderBy('collected_at')
            ->get(['color', 'source_kind', 'source_detail', 'collected_at']);

        $collected = $collections->map(fn (MiniDodoCollection $c) => [
            'color' => $c->color,
            'source_kind' => $c->source_kind,
            'source_detail' => $c->source_detail,
            'collected_at' => $c->collected_at->toIso8601String(),
        ])->values()->all();

        $colorCount = $collections->pluck('color')->unique()->count();

        return [
            'date' => $date->toDateString(),
            'total_steps' => (int) $totalSteps,
            'phase' => $phase,
            'goal_steps' => self::STEPS_GOAL,
            'collected' => $collected,
            'collected_color_count' => $colorCount,
        ];
    }

    /**
     * History trend (last N days).
     *
     * @return list<array{date:string,steps:int,phase:string,colors:list<string>}>
     */
    public function getHistory(User $user, Carbon $endDate, int $days): array
    {
        $days = max(1, min($days, 90));
        $start = $endDate->copy()->subDays($days - 1)->startOfDay();

        $sessions = DailyWalkSession::where('user_id', $user->id)
            ->whereDate('walk_date', '>=', $start->toDateString())
            ->whereDate('walk_date', '<=', $endDate->toDateString())
            ->orderBy('walk_date')
            ->get();

        // 預構 keyed map for O(1) lookup
        $sessionByDate = [];
        foreach ($sessions as $s) {
            $sessionByDate[$s->walk_date->toDateString()] = $s;
        }

        $colorsByDate = MiniDodoCollection::where('user_id', $user->id)
            ->whereDate('collected_on', '>=', $start->toDateString())
            ->whereDate('collected_on', '<=', $endDate->toDateString())
            ->get(['collected_on', 'color'])
            ->groupBy(fn ($c) => Carbon::parse($c->collected_on)->toDateString())
            ->map(fn ($items) => $items->pluck('color')->unique()->values()->all())
            ->all();

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i);
            $key = $d->toDateString();
            $session = $sessionByDate[$key] ?? null;
            $out[] = [
                'date' => $key,
                'steps' => $session !== null ? (int) $session->total_steps : 0,
                'phase' => $session !== null ? (string) $session->peak_phase : DailyWalkSession::PHASE_SEED,
                'colors' => array_values((array) ($colorsByDate[$key] ?? [])),
            ];
        }

        return $out;
    }

    private function refreshSummonedSummary(DailyWalkSession $session): void
    {
        $rows = MiniDodoCollection::where('user_id', $session->user_id)
            ->whereDate('collected_on', $session->walk_date->toDateString())
            ->orderBy('collected_at')
            ->get(['color', 'source_kind', 'collected_at']);

        $summary = $rows->map(fn (MiniDodoCollection $c) => [
            'color' => $c->color,
            'source_kind' => $c->source_kind,
            'collected_at' => $c->collected_at->toIso8601String(),
        ])->values()->all();

        $session->mini_dodos_summoned_json = $summary;
        $session->save();
    }

    private function publishGoal(User $user, Carbon $date, int $totalSteps): void
    {
        $uuid = (string) ($user->pandora_user_uuid ?? '');
        if ($uuid === '') {
            Log::debug('[Walk] skip publish — user lacks pandora_user_uuid', ['user' => $user->id]);

            return;
        }

        $idempotency = sprintf('meal.steps_goal_achieved.%s.%s', $user->id, $date->toDateString());
        $this->gamification->publish(
            $uuid,
            'meal.steps_goal_achieved',
            $idempotency,
            [
                'date' => $date->toDateString(),
                'total_steps' => $totalSteps,
                'phase' => DailyWalkSession::PHASE_FRUIT,
            ],
        );
    }
}
