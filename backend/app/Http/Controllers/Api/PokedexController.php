<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/pokedex — Pokemon-style food discovery list.
 * Slimmed port of ai-game/src/services/pokedex.ts.
 */
class PokedexController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Join food_discoveries → food_database for display fields. Keep query
        // small (limit 500) — frontend renders a grid; the pokedex tab is not
        // a heavy hitter and 500 is generous (avg user discovers ~50 foods).
        $rows = DB::table('food_discoveries as fd')
            ->leftJoin('food_database as f', 'fd.food_id', '=', 'f.id')
            ->where('fd.pandora_user_uuid', $user->pandora_user_uuid)
            ->orderByDesc('fd.first_seen_at')
            ->limit(500)
            ->get([
                'fd.id', 'fd.food_id', 'fd.first_seen_at', 'fd.times_eaten',
                'fd.best_score', 'fd.is_shiny',
                'f.name_zh', 'f.category', 'f.element',
            ]);

        $shinyCount = $rows->where('is_shiny', 1)->count();

        return response()->json([
            'discoveries' => $rows,
            'total_discovered' => $rows->count(),
            'shiny_count' => $shinyCount,
        ]);
    }
}
