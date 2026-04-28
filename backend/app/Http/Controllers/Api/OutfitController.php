<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/outfits — wardrobe catalog with unlock state per user.
 * Slimmed port of ai-game/src/services/outfits.ts.
 *
 * Full unlock-by-achievement gating is simplified: outfits unlock by
 * level / streak / fp_lifetime tier here. @todo wire achievement-based
 * unlocks once the achievement catalog is migrated.
 */
class OutfitController extends Controller
{
    /** @var list<array{key:string, name:string, description:string, emoji:string, unlock_type:string, unlock_value?:int|string, unlock_hint:string, fp_exclusive?:bool}> */
    private const CATALOG = [
        ['key' => 'none', 'name' => '基本造型', 'description' => '原本的樣子，最耐看', 'emoji' => '🫧', 'unlock_type' => 'default', 'unlock_hint' => '一開始就有'],
        ['key' => 'scarf', 'name' => '溫暖圍巾', 'description' => '玫瑰色圍巾，冬天感', 'emoji' => '🧣', 'unlock_type' => 'level', 'unlock_value' => 5, 'unlock_hint' => 'LV.5 解鎖'],
        ['key' => 'glasses', 'name' => '圓框眼鏡', 'description' => '文青氣質', 'emoji' => '👓', 'unlock_type' => 'level', 'unlock_value' => 8, 'unlock_hint' => 'LV.8 解鎖'],
        ['key' => 'headphones', 'name' => '玫瑰耳機', 'description' => '音樂愛好者', 'emoji' => '🎧', 'unlock_type' => 'level', 'unlock_value' => 12, 'unlock_hint' => 'LV.12 解鎖'],
        ['key' => 'straw_hat', 'name' => '草帽', 'description' => '夏日陽光', 'emoji' => '👒', 'unlock_type' => 'streak', 'unlock_value' => 7, 'unlock_hint' => '連續 7 天達標'],
        ['key' => 'angel_wings', 'name' => '天使翅膀', 'description' => '療癒系代表', 'emoji' => '👼', 'unlock_type' => 'level', 'unlock_value' => 20, 'unlock_hint' => 'LV.20 解鎖'],
        ['key' => 'fp_crown', 'name' => 'FP 皇冠', 'description' => '只有 FP 永久會員戴得起', 'emoji' => '👑', 'unlock_type' => 'fp_lifetime', 'unlock_hint' => '加入 FP 網站會員解鎖', 'fp_exclusive' => true],
        ['key' => 'fp_chef', 'name' => 'FP 主廚裝', 'description' => '婕樂纖營養師同款', 'emoji' => '🧑‍🍳', 'unlock_type' => 'fp_lifetime', 'unlock_hint' => '加入 FP 網站會員解鎖', 'fp_exclusive' => true],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $owned = (array) ($user->outfits_owned ?? ['none']);
        $level = (int) ($user->level ?? 1);
        $longest = (int) ($user->longest_streak ?? 0);
        $isFpLifetime = $user->membership_tier === 'fp_lifetime';

        $list = array_map(function ($o) use ($owned, $level, $longest, $isFpLifetime) {
            $unlocked = in_array($o['key'], $owned, true);
            if (! $unlocked) {
                $unlocked = match ($o['unlock_type']) {
                    'default' => true,
                    'level' => $level >= (int) $o['unlock_value'],
                    'streak' => $longest >= (int) $o['unlock_value'],
                    default => $isFpLifetime,
                };
            }

            return $o + ['unlocked' => $unlocked];
        }, self::CATALOG);

        return response()->json([
            'outfits' => $list,
            'equipped' => $user->equipped_outfit ?? 'none',
        ]);
    }

    public function equip(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'outfit_key' => ['required', 'string', 'max:48'],
        ]);

        $valid = collect(self::CATALOG)->firstWhere('key', $data['outfit_key']);
        if (! $valid) {
            return response()->json(['message' => 'unknown outfit_key'], 422);
        }

        // Trust the catalog state — must be unlocked. Re-derive instead of
        // trusting the client (don't allow front-end to grant outfits).
        $owned = (array) ($user->outfits_owned ?? ['none']);
        $isFp = $user->membership_tier === 'fp_lifetime';
        $unlocked = in_array($data['outfit_key'], $owned, true)
            || $valid['unlock_type'] === 'default'
            || ($valid['unlock_type'] === 'level' && (int) ($user->level ?? 1) >= (int) ($valid['unlock_value'] ?? 999))
            || ($valid['unlock_type'] === 'streak' && (int) ($user->longest_streak ?? 0) >= (int) ($valid['unlock_value'] ?? 999))
            || ($valid['unlock_type'] === 'fp_lifetime' && $isFp);

        if (! $unlocked) {
            return response()->json(['message' => 'outfit is locked'], 403);
        }

        $user->equipped_outfit = $data['outfit_key'];
        if (! in_array($data['outfit_key'], $owned, true)) {
            $owned[] = $data['outfit_key'];
            $user->outfits_owned = $owned;
        }
        $user->save();

        return response()->json([
            'equipped' => $user->equipped_outfit,
            'outfits_owned' => $user->outfits_owned,
        ]);
    }
}
