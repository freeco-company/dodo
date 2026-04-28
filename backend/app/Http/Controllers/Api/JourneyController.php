<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController extends Controller
{
    public function __construct(private readonly JourneyService $service) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->getJourney($request->user()));
    }

    public function advance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:meal_log,water,exercise,card_correct,daily_quest'],
        ]);
        return response()->json($this->service->advance($request->user(), $data['reason']));
    }

    /**
     * GET /api/journey/milestone/{day} — milestone story lines for the day-N
     * cutscene. Mirrors ai-game getMilestoneStory().
     *
     * @todo Port the full story script from ai-game/src/services/lore.ts.
     *       For now we return a generic 3-line script keyed by day so the
     *       frontend modal renders something coherent.
     */
    public function milestone(Request $request, int $day): JsonResponse
    {
        $user = $request->user();
        $animal = $user->avatar_animal ?: 'cat';

        $lines = match (true) {
            $day >= 21 => [
                "三週了！我們真的走到這裡了 ✨",
                "你比剛開始的時候，多了一份穩定。",
                "繼續陪你下一個 21 天，可以嗎？",
            ],
            $day >= 14 => [
                "兩週的累積，已經是習慣的雛形 💎",
                "你今天的選擇，過去的你會羨慕的。",
                "再 7 天就到第一個完整循環了！",
            ],
            $day >= 7 => [
                "一週的陪伴，你比想像中堅強 🔥",
                "我看見你即使忙也願意打卡。",
                "下週繼續一起，好嗎？",
            ],
            $day >= 3 => [
                "三天了，這是個小但重要的開始 🌱",
                "很多人撐不過第三天，你做到了。",
                "明天，我們再見。",
            ],
            default => [
                "今天也辛苦了～",
                "一步一步來，不用急。",
                "我會在這裡陪你。",
            ],
        };

        return response()->json([
            'day' => $day,
            'animal' => $animal,
            'lines' => $lines,
        ]);
    }
}
