<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiServiceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AiServiceClient;
use App\Services\Dodo\Walk\WalkSessionService;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SPEC-pikmin-walk-v1 — Pikmin Bloom 風計步深度遊戲化 API。
 *
 * 4 endpoints：
 *   GET  /api/walk/today        今日 session + mini-dodo 召喚
 *   POST /api/walk/sync         同步步數（native plugin / 手動）
 *   GET  /api/walk/history      最近 N 天趨勢
 *   GET  /api/walk/diary        每日朵朵探險日記（paid 跑 ai narrative，free 跑 stub）
 */
class WalkController extends Controller
{
    public function __construct(
        private readonly WalkSessionService $walks,
        private readonly EntitlementsService $entitlements,
        private readonly AiServiceClient $ai,
    ) {}

    public function today(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = Carbon::today($user->timezone ?? config('app.timezone'));

        return response()->json(['data' => $this->walks->getToday($user, $today)]);
    }

    public function sync(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'total_steps' => 'required|integer|min:0|max:200000',
            'date' => 'sometimes|date_format:Y-m-d',
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'])
            : Carbon::today($user->timezone ?? config('app.timezone'));
        $totalSteps = (int) $validated['total_steps'];

        $result = $this->walks->sync($user, $date, $totalSteps);

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'total_steps' => $result['session']->total_steps,
                'phase' => $result['session']->peak_phase,
                'phase_advanced' => $result['phase_advanced'],
                'goal_published_now' => $result['goal_published_now'],
                'newly_summoned' => array_map(fn ($mini) => [
                    'color' => $mini->color,
                    'source_kind' => $mini->source_kind,
                    'source_detail' => $mini->source_detail,
                ], $result['newly_summoned']),
            ],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $days = (int) $request->query('days', 7);
        if (! in_array($days, [7, 14, 30, 60, 90], true)) {
            $days = 7;
        }
        $today = Carbon::today($user->timezone ?? config('app.timezone'));

        return response()->json([
            'data' => $this->walks->getHistory($user, $today, $days),
        ]);
    }

    /**
     * 每日朵朵探險日記 — 走路 + mini-dodo 收集摘要 + 朵朵旁白。
     *
     * paid：呼叫 ai-service /v1/reports/narrative kind=walk_diary
     * free：deterministic stub（也是合規後盾，ai 故障 fallback 用）
     */
    public function diary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dateStr = (string) $request->query('date', '');
        $tz = $user->timezone ?? config('app.timezone');
        $date = $dateStr !== '' ? Carbon::parse($dateStr) : Carbon::today($tz);

        $today = $this->walks->getToday($user, $date);
        $colors = collect($today['collected'])->pluck('color')->unique()->values()->all();
        $payload = [
            'date' => $date->toDateString(),
            'total_steps' => $today['total_steps'],
            'phase' => $today['phase'],
            'colors_collected' => $colors,
        ];

        $tier = $this->entitlements->isPaid($user) ? 'paid' : 'free';

        // Free tier: deterministic stub（不打 ai-service）
        if ($tier === 'free') {
            return response()->json(['data' => [
                'payload' => $payload,
                'narrative' => $this->stubNarrative($payload),
                'stub_mode' => true,
            ]]);
        }

        // Paid: ai narrative + stub fallback
        try {
            $narrative = $this->ai->narrative($user, 'walk_diary', 'paid', $payload);
        } catch (AiServiceUnavailableException) {
            $narrative = $this->stubNarrative($payload);
            $narrative['stub_mode'] = true;
        }

        return response()->json(['data' => [
            'payload' => $payload,
            'narrative' => $narrative,
            'stub_mode' => $narrative['stub_mode'],
        ]]);
    }

    /**
     * 合規 stub — 中性詞、不暗示療效、依步數階段給對應旁白。
     *
     * @param  array{date:string,total_steps:int,phase:string,colors_collected:list<string>}  $payload
     * @return array{headline:string,lines:list<string>,model:string,cost_usd:float,stub_mode:bool}
     */
    private function stubNarrative(array $payload): array
    {
        $steps = $payload['total_steps'];
        $phase = $payload['phase'];
        $colorsCount = count($payload['colors_collected']);

        $headlineByPhase = [
            'seed' => '今天剛起步，朵朵陪你慢慢來 🌱',
            'sprout' => '小芽冒出來了，繼續走一段吧 🌿',
            'bloom' => '花開了！步數已過半 🌸',
            'fruit' => '結果啦！今日目標達成 🎉',
        ];
        $headline = $headlineByPhase[$phase] ?? '走走停停都是你的節奏';

        $lines = [
            sprintf('今天走了 %s 步', number_format($steps)),
        ];
        if ($colorsCount > 0) {
            $lines[] = sprintf('收集到 %d 種 mini-dodo 小夥伴', $colorsCount);
        } else {
            $lines[] = '還沒有 mini-dodo 出現，記一餐就會跑出來囉';
        }
        if ($phase === 'fruit') {
            $lines[] = '今天的步數已經很夠了，記得補充水分 💧';
        } elseif ($phase === 'bloom') {
            $lines[] = '再走一段就到目標了，加油';
        } elseif ($phase === 'sprout') {
            $lines[] = '節奏剛剛好，慢慢累積就好';
        } else {
            $lines[] = '從今天的小步開始，明天會更輕鬆';
        }

        return [
            'headline' => $headline,
            'lines' => $lines,
            'model' => 'stub',
            'cost_usd' => 0.0,
            'stub_mode' => true,
        ];
    }
}
