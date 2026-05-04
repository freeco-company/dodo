<?php

namespace App\Services\Dodo\Streak;

use App\Models\User;
use App\Services\Gamification\GamificationPublisher;
use App\Services\Gamification\LocalXpWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SPEC-streak-milestone-rewards — unlock outfits / cards / XP bonus when user
 * hits a streak milestone (1 / 3 / 7 / 14 / 21 / 30 / 60 / 100).
 *
 * Called from DailyLoginStreakService::recordLogin() when is_milestone=true.
 *
 * Design:
 *   - Outfits are persisted to users.outfits_owned (real unlock; aligns with
 *     existing OutfitController catalog keys). Unknown keys → fail-soft skip.
 *   - "Cards unlocked" are descriptive labels returned to the frontend so the
 *     toast can show a reveal animation. The card *collection* state still
 *     comes from CardPlay (you collect by answering). Adding pseudo-cards to
 *     the deck is out of scope here.
 *   - XP bonus only at 21 / 30 — applied via LocalXpWriter (flag-aware: a no-op
 *     post-cutover; level mirror eventually reconciles via webhook).
 *   - Idempotent on outfits (already-owned skipped); XP bonus is idempotent
 *     because recordLogin's same-day no-op gates re-entry.
 *   - Publishes a fail-soft `meal.streak_milestone_unlocked` event for catalog
 *     observability — dropped with warning when not in publisher catalog.
 */
class StreakMilestoneRewardService
{
    /**
     * milestone day → { outfit_code?, cards: list<{code,label}>, xp_bonus? }
     *
     * Outfit codes must exist in OutfitController::CATALOG. Codes that don't
     * exist there fail-soft skip (no insert into outfits_owned).
     *
     * @var array<int, array{outfit_code?: ?string, cards: list<array{code:string,label:string}>, xp_bonus?: int}>
     */
    private const REWARDS = [
        1 => [
            'outfit_code' => null,
            'cards' => [
                ['code' => 'streak_1', 'label' => '初心徽章'],
            ],
        ],
        3 => [
            'outfit_code' => 'scarf',
            'cards' => [
                ['code' => 'streak_3', 'label' => '三日小步'],
            ],
        ],
        7 => [
            'outfit_code' => 'straw_hat',
            'cards' => [
                ['code' => 'streak_7', 'label' => '一週成就'],
            ],
        ],
        14 => [
            'outfit_code' => 'sakura',
            'cards' => [
                ['code' => 'streak_14', 'label' => '兩週決心'],
            ],
        ],
        21 => [
            'outfit_code' => 'witch_hat',
            'cards' => [
                ['code' => 'streak_21', 'label' => '習慣養成'],
            ],
            'xp_bonus' => 50,
        ],
        30 => [
            'outfit_code' => 'winter_scarf',
            'cards' => [
                ['code' => 'streak_30', 'label' => '一月里程'],
            ],
            'xp_bonus' => 100,
        ],
        60 => [
            'outfit_code' => null,
            'cards' => [
                ['code' => 'streak_60', 'label' => '兩月堅持'],
            ],
        ],
        100 => [
            'outfit_code' => 'angel_wings',
            'cards' => [
                ['code' => 'streak_100', 'label' => '百日傳奇'],
            ],
        ],
    ];

    /**
     * The set of outfit keys the local OutfitController exposes today. Codes
     * not in this allow-list are returned in `outfits_unlocked` payload as
     * skipped=true so frontend can fail-soft (and we keep one source of truth
     * for the wardrobe — OutfitController::CATALOG).
     *
     * Kept inline (vs reading the controller constant) because OutfitController
     * is HTTP-layer and shouldn't be a service-layer dependency. Drift here
     * is caught by the StreakMilestoneRewardServiceTest assertions.
     *
     * @var list<string>
     */
    private const KNOWN_OUTFIT_KEYS = [
        'none', 'ribbon', 'scarf', 'chef_apron', 'glasses', 'witch_hat',
        'headphones', 'starry_cape', 'sunglasses', 'angel_wings',
        'straw_hat', 'sakura', 'winter_scarf',
        'fp_crown', 'fp_chef', 'fp_apron_premium',
    ];

    public function __construct(
        private readonly LocalXpWriter $xpWriter,
        private readonly GamificationPublisher $gamification,
    ) {}

    /**
     * @return array{
     *   outfit_unlocked: ?string,
     *   outfit_skipped: ?string,
     *   cards_unlocked: list<array{code:string,label:string}>,
     *   xp_bonus: int,
     *   level_after: ?int,
     *   leveled_up: bool,
     * }
     */
    public function unlockForMilestone(User $user, int $streak): array
    {
        $reward = self::REWARDS[$streak] ?? null;
        if ($reward === null) {
            return [
                'outfit_unlocked' => null,
                'outfit_skipped' => null,
                'cards_unlocked' => [],
                'xp_bonus' => 0,
                'level_after' => null,
                'leveled_up' => false,
            ];
        }

        $outfitUnlocked = null;
        $outfitSkipped = null;
        $code = $reward['outfit_code'] ?? null;
        if ($code !== null) {
            if (in_array($code, self::KNOWN_OUTFIT_KEYS, true)) {
                $outfitUnlocked = $this->mergeOutfit($user, $code) ? $code : null;
            } else {
                // Outfit catalog hasn't shipped this code yet — skip silently
                // but report so frontend doesn't lie about an unlock.
                $outfitSkipped = $code;
            }
        }

        $xpBonus = (int) ($reward['xp_bonus'] ?? 0);
        $levelBefore = (int) ($user->level ?? 1);
        $levelAfter = $levelBefore;
        if ($xpBonus > 0) {
            try {
                [, $levelAfter] = $this->xpWriter->apply($user, $xpBonus);
            } catch (Throwable $e) {
                // XP write failure must not break the milestone reveal.
                Log::warning('[StreakMilestoneReward] xp apply failed (soft)', [
                    'user_id' => $user->id,
                    'streak' => $streak,
                    'xp_bonus' => $xpBonus,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->safePublish($user, $streak, $reward);

        return [
            'outfit_unlocked' => $outfitUnlocked,
            'outfit_skipped' => $outfitSkipped,
            'cards_unlocked' => $reward['cards'],
            'xp_bonus' => $xpBonus,
            'level_after' => $xpBonus > 0 ? $levelAfter : null,
            'leveled_up' => $levelAfter > $levelBefore,
        ];
    }

    /**
     * Merge an outfit code into users.outfits_owned. Idempotent — already-owned
     * codes return false (no DB write, and the caller does NOT report it as a
     * fresh unlock so the toast doesn't repeat on the same milestone).
     */
    private function mergeOutfit(User $user, string $code): bool
    {
        $owned = (array) ($user->outfits_owned ?? ['none']);
        if (in_array($code, $owned, true)) {
            return false;
        }
        $owned[] = $code;
        // fill() goes through the model's array cast — direct assignment trips
        // larastan because the @property doc types it as string|null.
        $user->fill(['outfits_owned' => $owned]);
        $user->save();

        return true;
    }

    /**
     * Fail-soft publish — `meal.streak_milestone_unlocked` is not in the
     * publisher catalog yet; publisher logs + drops unknown kinds, so this
     * call is a no-op until catalog catches up. We keep the call site so the
     * day catalog adds it, observability turns on without further changes.
     */
    private function safePublish(User $user, int $streak, array $reward): void
    {
        $uuid = (string) ($user->pandora_user_uuid ?? '');
        if ($uuid === '') {
            return;
        }
        $today = now('Asia/Taipei')->toDateString();

        try {
            $this->gamification->publish(
                $uuid,
                'meal.streak_milestone_unlocked',
                "meal.streak_milestone_unlocked.{$uuid}.{$streak}.{$today}",
                [
                    'streak' => $streak,
                    'outfit_code' => $reward['outfit_code'] ?? null,
                    'card_codes' => array_map(fn ($c) => $c['code'], $reward['cards'] ?? []),
                    'xp_bonus' => (int) ($reward['xp_bonus'] ?? 0),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[StreakMilestoneReward] publish failed (soft)', [
                'user_id' => $user->id,
                'streak' => $streak,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
