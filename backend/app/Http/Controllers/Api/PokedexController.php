<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardPlay;
use App\Models\FoodDiscovery;
use App\Services\AppConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * /api/pokedex — 圖鑑（Pokemon-style food discovery）
 *
 * 2026-05-01 重構：回傳 ALL foods（含未發現），未發現的標 unlocked=false / 灰卡。
 * FP（婕樂纖）相關食物只給已加盟用戶看到（is_franchisee=true）。
 *
 * 點擊已發現的食物 → /api/pokedex/{food_id} 回傳當初解鎖的 card_play 詳情
 *（如果是透過卡牌答題解鎖）。
 */
class PokedexController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isFranchisee = (bool) ($user->is_franchisee ?? false);

        $discoveries = DB::table('food_discoveries')
            ->where('pandora_user_uuid', $user->pandora_user_uuid)
            ->get(['food_id', 'first_seen_at', 'times_eaten', 'best_score', 'is_shiny', 'unlocked_via_card_play_id'])
            ->keyBy('food_id');

        $query = DB::table('food_database')
            ->select(['id', 'name_zh', 'category', 'element', 'brand']);
        if (! $isFranchisee) {
            foreach (self::franchiseBrandMarkers() as $marker) {
                $query->where(function ($q) use ($marker) {
                    $q->whereNull('brand')->orWhere('brand', 'not like', "%{$marker}%");
                });
            }
        }
        $foods = $query->orderBy('id')->get();

        $entries = $foods->map(function ($f) use ($discoveries) {
            $d = $discoveries->get($f->id);

            return [
                'food_id' => $f->id,
                'name_zh' => $f->name_zh,
                'category' => $f->category,
                'element' => $f->element,
                'brand' => $f->brand,
                'unlocked' => $d !== null,
                'first_seen_at' => $d->first_seen_at ?? null,
                'times_eaten' => $d->times_eaten ?? 0,
                'best_score' => $d->best_score ?? null,
                'is_shiny' => (bool) ($d->is_shiny ?? false),
                'unlocked_via_card_play_id' => $d->unlocked_via_card_play_id ?? null,
            ];
        })->values();

        $shinyCount = $entries->where('is_shiny', true)->count();
        $unlockedCount = $entries->where('unlocked', true)->count();

        return response()->json([
            'entries' => $entries,
            'total' => $entries->count(),
            'unlocked_count' => $unlockedCount,
            'shiny_count' => $shinyCount,
            // Back-compat
            'discoveries' => $entries->where('unlocked', true)->values(),
            'total_discovered' => $unlockedCount,
        ]);
    }

    public function show(Request $request, int $foodId): JsonResponse
    {
        $user = $request->user();
        $isFranchisee = (bool) ($user->is_franchisee ?? false);

        $food = DB::table('food_database')->where('id', $foodId)->first();
        if (! $food) {
            return response()->json(['message' => 'food_not_found'], 404);
        }

        if (! $isFranchisee && $food->brand !== null && self::isFranchiseBrand((string) $food->brand)) {
            return response()->json(['message' => 'food_not_found'], 404);
        }

        $discovery = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('food_id', $foodId)
            ->first();

        if (! $discovery) {
            return response()->json([
                'food_id' => $food->id,
                'name_zh' => $food->name_zh,
                'category' => $food->category,
                'element' => $food->element,
                'unlocked' => false,
                'hint' => '還沒發現過，記錄一餐就能解鎖 ✨',
            ]);
        }

        $base = [
            'food_id' => $food->id,
            'name_zh' => $food->name_zh,
            'name_en' => $food->name_en,
            'category' => $food->category,
            'element' => $food->element,
            'brand' => $food->brand,
            'calories' => $food->calories,
            'protein_g' => $food->protein_g,
            'carbs_g' => $food->carbs_g,
            'fat_g' => $food->fat_g,
            'unlocked' => true,
            'first_seen_at' => $discovery->first_seen_at,
            'times_eaten' => $discovery->times_eaten,
            'best_score' => $discovery->best_score,
            'is_shiny' => (bool) $discovery->is_shiny,
        ];

        if ($discovery->unlocked_via_card_play_id) {
            $play = CardPlay::where('id', $discovery->unlocked_via_card_play_id)
                ->where('pandora_user_uuid', $user->pandora_user_uuid)
                ->first();
            if ($play) {
                $base['unlocked_via'] = self::formatPlay($play);
            }
        }

        return response()->json($base);
    }

    /** @return array<string,mixed> */
    private static function formatPlay(CardPlay $play): array
    {
        $config = App::make(AppConfigService::class);
        $cards = (array) (($config->get('question_decks') ?? [])['cards'] ?? []);
        $card = null;
        foreach ($cards as $c) {
            if (($c['id'] ?? null) === $play->card_id) {
                $card = $c;
                break;
            }
        }

        return [
            'play_id' => $play->id,
            'card_id' => $play->card_id,
            'card_type' => $play->card_type,
            'rarity' => $play->rarity,
            'answered_at' => $play->answered_at,
            'chosen_idx' => $play->choice_idx,
            'correct' => $play->correct,
            'question' => $card['question'] ?? null,
            'choices' => array_map(
                fn ($c) => [
                    'text' => $c['text'] ?? '',
                    'correct' => (bool) ($c['correct'] ?? false),
                    'feedback' => $c['feedback'] ?? null,
                ],
                (array) ($card['choices'] ?? []),
            ),
            'explain' => $card['explain'] ?? null,
        ];
    }

    /**
     * Brand-name fragments that mark a food as franchise-only (婕樂纖 brand
     * gated to is_franchisee=true users).
     *
     * Pre-launch fix (2026-05-01): dropped 2-letter `'FP'` because it
     * false-positives on imported brands containing those letters in random
     * positions (e.g. SKU codes, English brand subwords). Future-proofing:
     * if we ever need a stricter match consider adding an explicit
     * `is_franchise_only` boolean column on `food_database` rather than
     * widening this list back.
     *
     * @return list<string>
     */
    public static function franchiseBrandMarkers(): array
    {
        return ['婕樂纖', 'Fairy Pandora'];
    }

    public static function isFranchiseBrand(string $brand): bool
    {
        foreach (self::franchiseBrandMarkers() as $m) {
            if (mb_stripos($brand, $m) !== false) {
                return true;
            }
        }

        return false;
    }
}
