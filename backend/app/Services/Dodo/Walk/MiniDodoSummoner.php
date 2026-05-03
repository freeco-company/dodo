<?php

namespace App\Services\Dodo\Walk;

use App\Models\Meal;
use App\Models\MiniDodoCollection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SPEC-pikmin-walk-v1 — 從 meal log / 步數階段判定該召喚哪些 mini-dodo。
 *
 * 設計原則（合規硬規則 group-fp-product-compliance.md）：
 *  - 5 色對應 5 大營養素 / 行為，**不暗示療效**（不講「補鈣」「燃脂」「排毒」）。
 *  - 中性詞「均衡」「活力」「日常」。
 *  - 觸發門檻刻意保守，避免「吃越多越好」的誤導。
 *
 * Idempotency：UNIQUE(user, date, color, source_kind) — 同來源同色同日多次餵相同 meal
 * 不會重複召喚。透過 firstOrCreate 達成。
 */
class MiniDodoSummoner
{
    /**
     * 把今日所有 meal 餵進來，回傳「本次新召喚」的 mini-dodo 列表。
     *
     * 為什麼一次吃整天 meal：mini-dodo 的判定門檻是「日總和」（蛋白質 ≥ 50g、纖維 ≥ 15g
     * 等），不是單餐。每天結算才有意義。
     *
     * @return list<MiniDodoCollection>
     */
    public function summonForMeals(User $user, Carbon $date): array
    {
        $meals = Meal::where('user_id', $user->id)
            ->whereDate('date', $date->toDateString())
            ->get();

        if ($meals->isEmpty()) {
            return [];
        }

        $sums = [
            'protein_g' => 0.0,
            'fiber_g' => 0.0,
            'fat_g' => 0.0,
            'carbs_g' => 0.0,
        ];
        $latestMealId = null;
        $latestMealAt = null;

        foreach ($meals as $meal) {
            $sums['protein_g'] += (float) ($meal->protein_g ?? 0);
            $sums['fiber_g'] += (float) ($meal->fiber_g ?? 0);
            $sums['fat_g'] += (float) ($meal->fat_g ?? 0);
            $sums['carbs_g'] += (float) ($meal->carbs_g ?? 0);
            // Track 最後一筆作 source_ref（探險日記引用用）
            $createdAt = $meal->created_at;
            if ($latestMealAt === null || ($createdAt !== null && $createdAt->gt($latestMealAt))) {
                $latestMealAt = $createdAt;
                $latestMealId = (int) $meal->id;
            }
        }

        $summoned = [];
        $rules = [
            // [color, threshold check, source_detail]
            ['red', $sums['protein_g'] >= 50.0, 'protein_balanced'],
            ['green', $sums['fiber_g'] >= 15.0, 'fiber_ok'],
            ['yellow', $sums['fat_g'] >= 25.0 && $sums['fat_g'] <= 80.0, 'fat_in_range'],
            ['purple', $sums['carbs_g'] >= 100.0, 'carbs_present'],
        ];

        foreach ($rules as [$color, $hit, $detail]) {
            if (! $hit) {
                continue;
            }

            $row = $this->firstOrCreate($user, $date, $color, MiniDodoCollection::SOURCE_MEAL, $latestMealId, $detail);
            if ($row !== null) {
                $summoned[] = $row;
            }
        }

        return $summoned;
    }

    /**
     * Steps phase 升到 bloom / fruit 時，召喚一隻 blue（活動日常）mini-dodo。
     *
     * Phase=fruit 額外送一隻 yellow（持續活力）— 不重複呼叫，因為 unique 鍵會 dedupe。
     *
     * @return list<MiniDodoCollection>
     */
    public function summonForStepsPhase(User $user, Carbon $date, string $phase, int $totalSteps): array
    {
        $summoned = [];

        if ($phase === 'bloom' || $phase === 'fruit') {
            $row = $this->firstOrCreate($user, $date, 'blue', MiniDodoCollection::SOURCE_STEPS, null, "phase_{$phase}");
            if ($row !== null) {
                $summoned[] = $row;
            }
        }

        if ($phase === 'fruit') {
            $row = $this->firstOrCreate($user, $date, 'yellow', MiniDodoCollection::SOURCE_STEPS, null, 'phase_fruit_bonus');
            if ($row !== null) {
                $summoned[] = $row;
            }
        }

        return $summoned;
    }

    /**
     * Idempotent — UNIQUE(user, date, color, source_kind) 保證 race-safe。
     */
    private function firstOrCreate(
        User $user,
        Carbon $date,
        string $color,
        string $sourceKind,
        ?int $sourceRefId,
        ?string $sourceDetail,
    ): ?MiniDodoCollection {
        return DB::transaction(function () use ($user, $date, $color, $sourceKind, $sourceRefId, $sourceDetail) {
            $existing = MiniDodoCollection::where('user_id', $user->id)
                ->whereDate('collected_on', $date->toDateString())
                ->where('color', $color)
                ->where('source_kind', $sourceKind)
                ->first();
            if ($existing !== null) {
                return null; // 已召喚過、不報為「新」
            }

            return MiniDodoCollection::create([
                'user_id' => $user->id,
                'collected_on' => $date->toDateString(),
                'color' => $color,
                'source_kind' => $sourceKind,
                'source_ref_id' => $sourceRefId,
                'source_detail' => $sourceDetail,
                'collected_at' => Carbon::now(),
            ]);
        });
    }
}
