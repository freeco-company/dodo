<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreVisit;
use App\Services\AppConfigService;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/island/* — Knowledge Island (store-scene-hotspot map).
 *
 * Slimmed port of ai-game/src/services/island.ts. Scene catalog is loaded
 * from app_config (key=island_scenes); falls back to a minimal default
 * catalog so the frontend always renders.
 *
 * @todo Phase F: full familiarity / dialog integration. Right now we only
 *       expose visit_count from store_visits — familiarity_level / hotspot
 *       visited_today / NPC dialog overrides are deferred.
 */
class IslandController extends Controller
{
    public function __construct(
        private readonly AppConfigService $config,
        private readonly EntitlementsService $entitlements,
    ) {}

    public function scenes(Request $request): JsonResponse
    {
        $user = $request->user();
        $catalog = $this->config->get('island_scenes') ?? $this->defaultScenes();
        $scenes = $catalog['scenes'] ?? [];

        $visits = StoreVisit::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->get(['store_key', 'visit_count'])
            ->keyBy('store_key');

        $level = (int) ($user->level ?? 1);
        $tier = $user->membership_tier ?? 'free';

        $out = [];
        foreach ($scenes as $s) {
            $tierReq = $s['tier_required'] ?? null;
            $minLevel = (int) ($s['min_level'] ?? 1);
            $tierOk = $tierReq === null || $this->tierSatisfies($tier, $tierReq);
            // FP-exclusive scenes are hidden from non-FP users
            if (! $tierOk && $tierReq === 'fp_lifetime') {
                continue;
            }
            $levelOk = $level >= $minLevel;
            $visit = $visits->get($s['key']);

            $out[] = [
                'key' => $s['key'],
                'name' => $s['name'],
                'emoji' => $s['emoji'] ?? '🏪',
                'backdrop' => $s['backdrop'] ?? 'pastel',
                'description' => $s['description'] ?? '',
                'unlocked' => $tierOk && $levelOk,
                'lock_reason' => ! $tierOk ? 'tier' : (! $levelOk ? 'level' : null),
                'tier_required' => $tierReq,
                'min_level' => $minLevel,
                'user_level' => $level,
                'visit_count' => $visit ? (int) $visit->visit_count : 0,
                'familiarity_level' => 'first_visit',
                'familiarity_emoji' => '🌱',
                'npc' => $s['npc'] ?? null,
                'hotspots' => array_map(
                    fn ($h) => $h + ['visited_today' => false, 'unlocked' => true],
                    $s['hotspots'] ?? [],
                ),
            ];
        }

        return response()->json([
            'tier' => $tier,
            'scenes' => $out,
        ]);
    }

    public function store(Request $request, string $scene): JsonResponse
    {
        $user = $request->user();
        $catalog = $this->config->get('island_scenes') ?? $this->defaultScenes();
        $sceneData = collect($catalog['scenes'] ?? [])->firstWhere('key', $scene);

        if (! $sceneData) {
            return response()->json(['message' => 'unknown scene'], 404);
        }

        $tierReq = $sceneData['tier_required'] ?? null;
        $minLevel = (int) ($sceneData['min_level'] ?? 1);
        $tier = $user->membership_tier ?? 'free';
        $level = (int) ($user->level ?? 1);
        $tierOk = $tierReq === null || $this->tierSatisfies($tier, $tierReq);
        $levelOk = $level >= $minLevel;

        $visit = StoreVisit::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('store_key', $scene)
            ->first();

        $today = \Carbon\Carbon::today()->toDateString();
        $consumed = (int) \App\Models\Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('date', $today)->sum('calories');
        $proteinConsumed = (float) \App\Models\Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('date', $today)->sum('protein_g');
        $calTarget = (int) ($user->daily_calorie_target ?? 1800);
        $proteinTarget = (float) ($user->daily_protein_target_g ?? 80);

        $intents = array_map(
            fn ($h) => ['emoji' => $h['emoji'] ?? '🛒', 'label' => $h['name'] ?? $h['key'] ?? 'item'],
            $sceneData['hotspots'] ?? [],
        );

        return response()->json([
            'key' => $sceneData['key'],
            'name' => $sceneData['name'],
            'emoji' => $sceneData['emoji'] ?? '🏪',
            'backdrop' => $sceneData['backdrop'] ?? 'pastel',
            'description' => $sceneData['description'] ?? '',
            'npc' => $sceneData['npc'] ?? ['emoji' => '🧑', 'name' => '店員'],
            'dialog' => $sceneData['dialog'] ?? [],
            'intents' => $intents,
            'user_state' => [
                'remaining_calories' => max(0, $calTarget - $consumed),
                'protein_needed_g' => max(0, (int) ($proteinTarget - $proteinConsumed)),
            ],
            'unlocked' => $tierOk && $levelOk,
            'lock_reason' => ! $tierOk ? 'tier' : (! $levelOk ? 'level' : null),
            'visit_count' => $visit ? (int) $visit->visit_count : 0,
            'entitlements' => $this->entitlements->get($user),
            'scene' => $sceneData,
        ]);
    }

    /**
     * POST /api/island/consume-visit — burns one of the monthly free visits
     * (no-op for unlimited tiers). Mirrors ai-game consumeIslandVisit().
     */
    public function consumeVisit(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'scene_key' => ['required', 'string', 'max:48'],
        ]);

        $ent = $this->entitlements->get($user);
        if (! $ent['unlimited_island']) {
            if ($ent['island_quota_remaining'] <= 0) {
                return response()->json([
                    'message' => 'island visit quota exhausted',
                    'entitlements' => $ent,
                ], 402);
            }
            $user->island_visits_used = (int) ($user->island_visits_used ?? 0) + 1;
            $user->save();
        }

        // Bump store visit familiarity counter
        $visit = StoreVisit::firstOrNew([
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'store_key' => $data['scene_key'],
        ]);
        $visit->user_id = $user->id;
        $visit->visit_count = (int) ($visit->visit_count ?? 0) + 1;
        $visit->last_visit_at = now();
        if (! $visit->first_visit_at) {
            $visit->first_visit_at = now();
        }
        $visit->save();

        return response()->json([
            'consumed' => ! $ent['unlimited_island'],
            'visit_count' => (int) $visit->visit_count,
            'entitlements' => $this->entitlements->get($user->fresh()),
        ]);
    }

    private function tierSatisfies(string $userTier, string $required): bool
    {
        // Hierarchy (low → high): free < retail < fp_franchise < fp_lifetime
        $rank = ['free' => 0, 'retail' => 1, 'fp_franchise' => 2, 'fp_lifetime' => 3];

        return ($rank[$userTier] ?? 0) >= ($rank[$required] ?? 0);
    }

    /** @return array<string,mixed> */
    private function defaultScenes(): array
    {
        return [
            'version' => 1,
            'scenes' => [
                [
                    'key' => 'seven_eleven',
                    'name' => '7-11',
                    'emoji' => '🏪',
                    'backdrop' => 'mint',
                    'description' => '社區的便利商店',
                    'min_level' => 1,
                    'hotspots' => [],
                ],
                [
                    'key' => 'familymart',
                    'name' => '全家',
                    'emoji' => '🏬',
                    'backdrop' => 'sky',
                    'description' => '便利又齊全',
                    'min_level' => 1,
                    'hotspots' => [],
                ],
                [
                    'key' => 'pxmart',
                    'name' => '全聯',
                    'emoji' => '🛒',
                    'backdrop' => 'peach',
                    'description' => '生鮮超市',
                    'min_level' => 2,
                    'hotspots' => [],
                ],
                [
                    'key' => 'fp_shop',
                    'name' => 'FP 旗艦店',
                    'emoji' => '👑',
                    'backdrop' => 'gold',
                    'description' => '婕樂纖會員專屬',
                    'tier_required' => 'fp_lifetime',
                    'min_level' => 1,
                    'hotspots' => [],
                ],
            ],
        ];
    }
}
