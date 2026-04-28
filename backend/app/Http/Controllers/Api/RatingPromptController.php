<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RatingPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingPromptController extends Controller
{
    public function __construct(private readonly RatingPromptService $service) {}

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', RatingPromptService::KINDS)],
        ]);
        $this->service->log($request->user(), $data['kind']);
        return response()->json(['ok' => true]);
    }

    /**
     * Slimmed port of ai-game/src/services/rating_prompt.ts shouldShowRatingPrompt.
     * Returns { should_show: bool, reason: string }.
     *
     * NOTE: rating_prompt_shown_at / rating_prompt_dismissed_count columns on
     * users table are not yet migrated (RatingPromptService TODO note). Until
     * then we gate purely on streak / journey fields and conservatively
     * default to NOT showing on app_open. @todo wire cooldown columns.
     */
    public function view(Request $request): JsonResponse
    {
        $user = $request->user();
        $trigger = $request->query('trigger', 'app_open');

        $should = false;
        $reason = 'default_no_show';

        switch ($trigger) {
            case 'streak_7':
                if (($user->current_streak ?? 0) >= 7) {
                    $should = true;
                    $reason = 'streak_7_hit';
                }
                break;
            case 'achievement_unlocked':
                $should = true;
                $reason = 'achievement_unlocked';
                break;
            case 'journey_cycle_complete':
                if (($user->journey_cycle ?? 0) >= 1 && ($user->journey_day ?? 0) >= 21) {
                    $should = true;
                    $reason = 'journey_cycle_complete';
                }
                break;
            default:
                // app_open / unknown — never auto-trigger
                $should = false;
                $reason = 'no_wow_moment';
        }

        return response()->json([
            'should_show' => $should,
            'reason' => $reason,
            'trigger' => $trigger,
        ]);
    }
}
