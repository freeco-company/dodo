<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/suggest/next-meal — next-meal hint card on the home tab.
 *
 * Slim heuristic stub: looks at today's logged meal_types to pick the
 * next missing slot (breakfast → lunch → dinner → snack) and returns
 * a generic suggestion. Real AI-driven personalization is deferred to
 * the Python service.
 *
 * @todo Replace with Python AI call (ai-game services/suggest.ts uses
 *       the user's allergies / dietary_type / dislike_foods to pick a
 *       specific food row from food_database). Frontend already handles
 *       the response shape, so this is a drop-in upgrade later.
 */
class SuggestController extends Controller
{
    public function nextMeal(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        $loggedTypes = Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('date', $today)
            ->pluck('meal_type')
            ->unique()
            ->values()
            ->toArray();

        $order = ['breakfast', 'lunch', 'dinner', 'snack'];
        $next = null;
        foreach ($order as $slot) {
            if (! in_array($slot, $loggedTypes, true)) {
                $next = $slot;
                break;
            }
        }

        if ($next === null) {
            return response()->json([
                'next_meal_type' => null,
                'message' => '今天的三餐都記錄完了，太棒了！',
                'food_suggestions' => [],
            ]);
        }

        $defaults = [
            'breakfast' => ['燕麥粥', '蛋吐司', '無糖豆漿'],
            'lunch' => ['雞胸肉沙拉', '糙米飯便當', '鮭魚便當'],
            'dinner' => ['烤雞腿', '清蒸魚', '青菜豆腐湯'],
            'snack' => ['希臘優格', '無調味堅果', '蘋果'],
        ];

        return response()->json([
            'next_meal_type' => $next,
            'message' => "建議的{$next}選項",
            'food_suggestions' => $defaults[$next],
        ]);
    }
}
