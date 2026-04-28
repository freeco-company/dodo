<?php

namespace App\Services;

use App\Models\CardEventOffer;
use App\Models\CardPlay;
use App\Models\DailyLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Translated from ai-game/src/services/cards.ts (simplified, ~866 lines original).
 *
 * Parity-with-cards.ts:
 * - Stamina economy (base 3 + meal bonus + streak bonus, cap 10)
 * - Draw: pulls from card_event_offers if any pending; else NO_CARDS_AVAILABLE
 *   (full JSON deck seeding deferred to next batch)
 * - Answer: marks play answered + simple XP (no combo/achievement parity yet)
 * - Collection: distinct card_ids from card_plays
 *
 * TODO: parity with cards.ts — combo bonus, scenario xp_mod, FP recipe gating,
 * weighted rarity draw from JSON deck, achievement chain, first_solve XP, etc.
 */
class CardService
{
    private const STAMINA_BASE = 3;

    private const STAMINA_CAP = 10;

    public function __construct(
        private readonly JourneyService $journey,
        private readonly ?AppConfigService $config = null,
    ) {}

    private function config(): AppConfigService
    {
        return $this->config ?? App::make(AppConfigService::class);
    }

    /**
     * @return list<array<string,mixed>> all cards from the seeded JSON deck.
     */
    private function deck(): array
    {
        $decks = $this->config()->get('question_decks') ?? [];
        $cards = $decks['cards'] ?? [];

        return is_array($cards) ? array_values($cards) : [];
    }

    private function countPlaysToday(User $user): int
    {
        // Phase D Wave 2: read by uuid (legacy user_id retained on row by trait)
        return CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('answered_at')
            ->count();
    }

    public function getStamina(User $user): array
    {
        $today = Carbon::today()->toDateString();
        $log = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->whereDate('date', $today)->first()
            // dual-write on create: keep user_id alive until Phase F drop
            ?? DailyLog::create([
                'user_id' => $user->id,
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'date' => $today,
            ]);
        $bonuses = [];
        $mealsBonus = min(3, (int) ($log->meals_logged ?? 0));
        if ($mealsBonus > 0) {
            $bonuses[] = ['source' => 'meals', 'amount' => $mealsBonus, 'label' => "記餐 +{$mealsBonus}"];
        }
        $streakBonus = (int) $user->current_streak >= 3 ? 1 : 0;
        if ($streakBonus > 0) {
            $bonuses[] = ['source' => 'streak', 'amount' => 1, 'label' => '連勝 +1'];
        }
        $total = min(self::STAMINA_CAP, self::STAMINA_BASE + $mealsBonus + $streakBonus);
        $used = $this->countPlaysToday($user);
        $remaining = max(0, $total - $used);

        return [
            'used' => $used,
            'max' => $total,
            'remaining' => $remaining,
            'base' => self::STAMINA_BASE,
            'bonuses' => $bonuses,
            'resets_at' => Carbon::tomorrow()->toIso8601String(),
        ];
    }

    public function draw(User $user): array
    {
        $stamina = $this->getStamina($user);
        if ($stamina['remaining'] <= 0) {
            abort(409, 'NO_STAMINA');
        }

        // 1) Event offers (pushed by NPC interactions etc.) take priority.
        $offer = CardEventOffer::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('status', 'pending')
            ->orderBy('offered_at')
            ->first();

        if ($offer) {
            $card = $this->findCardById($offer->card_id) ?? [
                'id' => $offer->card_id,
                'type' => 'event',
                'category' => 'event',
                'rarity' => 'common',
                'emoji' => '🎴',
                'question' => '',
                'choices' => [],
            ];
            $play = CardPlay::create([
                'user_id' => $user->id,
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'date' => Carbon::today()->toDateString(),
                'card_id' => $offer->card_id,
                'card_type' => $card['type'] ?? 'event',
                'rarity' => $card['rarity'] ?? 'common',
            ]);
            $offer->play_id = $play->id;
            $offer->save();

            return $this->cardPayload($play->id, $card, isNew: true, user: $user);
        }

        // 2) Random pick from JSON deck, excluding cards this user has
        //    already answered.  When the user has cleared every card we
        //    fall back to allowing repeats (so daily play never hard-stops).
        $deck = $this->deck();
        if (empty($deck)) {
            abort(409, 'NO_CARDS_AVAILABLE');
        }

        $seenIds = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereNotNull('answered_at')
            ->pluck('card_id')
            ->all();
        $seen = array_flip($seenIds);

        $unseen = array_values(array_filter(
            $deck,
            fn ($c) => isset($c['id']) && ! isset($seen[$c['id']])
        ));
        $isNew = ! empty($unseen);
        $pool = $isNew ? $unseen : $deck;
        $card = $pool[random_int(0, count($pool) - 1)];

        $play = CardPlay::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'card_id' => (string) ($card['id'] ?? 'unknown'),
            'card_type' => (string) ($card['type'] ?? 'knowledge'),
            'rarity' => (string) ($card['rarity'] ?? 'common'),
        ]);

        return $this->cardPayload($play->id, $card, isNew: $isNew, user: $user);
    }

    /** @return array<string,mixed>|null */
    private function findCardById(string $id): ?array
    {
        foreach ($this->deck() as $c) {
            if (($c['id'] ?? null) === $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Serialise a card row for /api/cards/draw.  Mirrors the legacy
     * ai-game node response shape so the frontend does not need to change.
     *
     * @param  array<string,mixed>  $card
     * @return array<string,mixed>
     */
    private function cardPayload(int $playId, array $card, bool $isNew, User $user): array
    {
        // Strip the `correct` flag from choices so a malicious client can't
        // peek the answer before submitting.
        $publicChoices = array_map(
            fn ($c) => [
                'text' => $c['text'] ?? '',
                'hint' => $c['hint'] ?? null,
            ],
            (array) ($card['choices'] ?? [])
        );

        return [
            'play_id' => $playId,
            'id' => $card['id'] ?? null,
            'type' => $card['type'] ?? 'knowledge',
            'category' => $card['category'] ?? null,
            'rarity' => $card['rarity'] ?? 'common',
            'emoji' => $card['emoji'] ?? '🎴',
            'question' => $card['question'] ?? '',
            'hint' => $card['hint'] ?? null,
            'choices' => $publicChoices,
            'is_new' => $isNew,
            'stamina' => $this->getStamina($user),
        ];
    }

    public function answer(User $user, int $playId, int $choiceIdx): array
    {
        $play = CardPlay::where('id', $playId)->where('pandora_user_uuid', $user->pandora_user_uuid)->first();
        if (! $play) {
            abort(404, 'PLAY_NOT_FOUND');
        }
        if ($play->answered_at) {
            abort(409, 'ALREADY_ANSWERED');
        }

        $card = $this->findCardById((string) $play->card_id);
        $correct = null;
        $explain = null;
        $feedback = null;
        $revealCorrectIdx = null;
        if ($card) {
            foreach ((array) ($card['choices'] ?? []) as $i => $c) {
                if ((bool) ($c['correct'] ?? false)) {
                    $revealCorrectIdx = $i;
                    break;
                }
            }
            if (isset($card['choices'][$choiceIdx])) {
                $choice = $card['choices'][$choiceIdx];
                $correct = (bool) ($choice['correct'] ?? false);
                $feedback = $choice['feedback'] ?? null;
                $explain = $card['explain'] ?? null;
            }
        }

        $firstSolve = $correct === true && ! CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('card_id', $play->card_id)
            ->where('correct', true)
            ->whereNotNull('answered_at')
            ->where('id', '!=', $play->id)
            ->exists();

        $baseXp = $correct === null ? 5 : ($correct ? 8 : 3);
        $firstSolveBonus = $firstSolve ? 5 : 0;
        $totalXp = $baseXp + $firstSolveBonus;

        $xpBreakdown = [['label' => $correct ? '答對' : ($correct === false ? '答錯' : '參與'), 'amount' => $baseXp]];
        if ($firstSolveBonus > 0) {
            $xpBreakdown[] = ['label' => '首次解鎖', 'amount' => $firstSolveBonus];
        }

        $play->choice_idx = $choiceIdx;
        $play->correct = $correct;
        $play->xp_gained = $totalXp;
        $play->answered_at = now();
        $play->save();

        $levelBefore = (int) $user->level;
        $user->xp = (int) $user->xp + $totalXp;
        $user->level = GameXp::levelForXp((int) $user->xp);
        $user->save();
        $levelAfter = (int) $user->level;

        return [
            'card_id' => $play->card_id,
            'card_type' => $play->card_type,
            'chosen_idx' => $choiceIdx,
            'correct' => $correct,
            'reveal_correct_idx' => $revealCorrectIdx,
            'feedback' => $feedback,
            'explain' => $explain,
            'first_solve' => $firstSolve,
            'combo_bonus_triggered' => false,
            'combo_count' => 0,
            'xp_gained' => $totalXp,
            'xp_breakdown' => $xpBreakdown,
            'leveled_up' => $levelAfter > $levelBefore,
            'level_after' => $levelAfter,
            'level' => $levelAfter,
            'new_achievements' => [],
            'stamina' => $this->getStamina($user),
        ];
    }

    public function collection(User $user): array
    {
        $deck = $this->deck();
        $eventCardIds = [];
        foreach ($deck as $c) {
            if (($c['type'] ?? null) === 'event') {
                $eventCardIds[(string) ($c['id'] ?? '')] = true;
            }
        }

        $plays = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereNotNull('answered_at')
            ->get(['card_id', 'card_type', 'rarity', 'correct']);

        $playByCardId = [];
        foreach ($plays as $p) {
            $cid = (string) $p->card_id;
            if (! isset($playByCardId[$cid])) {
                $playByCardId[$cid] = ['plays' => 0, 'correct' => 0];
            }
            $playByCardId[$cid]['plays']++;
            if ($p->correct) {
                $playByCardId[$cid]['correct']++;
            }
        }

        $userTier = (string) ($user->membership_tier ?? 'free');
        $userLevel = (int) ($user->level ?? 1);

        $collectedCards = [];
        $lockedCards = [];
        $byRarity = [
            'common' => ['collected' => 0, 'total' => 0],
            'rare' => ['collected' => 0, 'total' => 0],
            'legendary' => ['collected' => 0, 'total' => 0],
        ];
        $eventTotal = 0;
        $eventCollected = 0;

        foreach ($deck as $c) {
            $cid = (string) ($c['id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $rarity = (string) ($c['rarity'] ?? 'common');
            $type = (string) ($c['type'] ?? 'knowledge');
            $isEvent = $type === 'event';
            $playStat = $playByCardId[$cid] ?? null;
            $collected = $playStat !== null;

            if (isset($byRarity[$rarity])) {
                $byRarity[$rarity]['total']++;
                if ($collected) {
                    $byRarity[$rarity]['collected']++;
                }
            }
            if ($isEvent) {
                $eventTotal++;
                if ($collected) {
                    $eventCollected++;
                }
            }

            $cardMeta = [
                'card_id' => $cid,
                'type' => $type,
                'rarity' => $rarity,
                'emoji' => $c['emoji'] ?? '🎴',
                'category' => $c['category'] ?? null,
                'question' => $c['question'] ?? '',
                'is_event' => $isEvent,
                'tier_required' => $c['tier_required'] ?? null,
            ];

            if ($collected) {
                $collectedCards[] = $cardMeta + [
                    'stats' => [
                        'plays' => $playStat['plays'],
                        'correct' => $playStat['correct'],
                        'mastered' => $playStat['correct'] >= 3,
                    ],
                ];
            } else {
                $tierLocked = ! $this->tierSatisfies($userTier, $c['tier_required'] ?? null);
                $minLevel = isset($c['min_level']) ? (int) $c['min_level'] : null;
                $levelLocked = $minLevel !== null && $userLevel < $minLevel;
                if ($tierLocked || $levelLocked) {
                    $lockedCards[] = $cardMeta + [
                        'min_level' => $minLevel,
                        'lock_reason' => $tierLocked ? 'tier' : 'level',
                    ];
                }
            }
        }

        // For legacy plays whose card_id isn't in the seeded deck (test fixtures,
        // events that pre-date the deck seed), surface them in `cards`/`collected`
        // so tenant-isolation tests + back-compat queries still see them.
        $deckCardIds = array_flip(array_map(fn ($c) => (string) ($c['id'] ?? ''), $deck));
        $orphanCards = [];
        foreach ($plays->groupBy('card_id') as $cid => $group) {
            if (! isset($deckCardIds[(string) $cid])) {
                $first = $group->first();
                $orphanCards[] = [
                    'card_id' => (string) $cid,
                    'type' => (string) $first->card_type,
                    'rarity' => (string) $first->rarity,
                ];
            }
        }

        $cardsFlat = array_merge(
            array_map(
                fn ($cc) => ['card_id' => $cc['card_id'], 'type' => $cc['type'], 'rarity' => $cc['rarity']],
                $collectedCards,
            ),
            $orphanCards,
        );

        return [
            'collected' => count($cardsFlat),
            'total' => max(count($deck), count($cardsFlat)),
            'by_rarity' => $byRarity,
            'event_total' => $eventTotal,
            'event_collected' => $eventCollected,
            'collected_cards' => $collectedCards,
            'locked_cards' => $lockedCards,
            'cards' => $cardsFlat,
        ];
    }

    // ------------------------------------------------------------------
    // Event card offers (translated from ai-game/src/services/cards.ts
    // — drawEventCard / skipEventOffer / offerEventCard@get-by-id slice).
    //
    // Parity notes vs Node:
    // - The Node version keyed offers by UUID strings; here offer_id is the
    //   Laravel auto-increment int (the schema bumped legacy_id → id).
    // - status enum still uses 'pending' | 'answered' | 'missed' | 'skipped'
    //   per the create_card_event_offers migration column comment.
    // - We do NOT yet implement the candidate-rolling logic from
    //   offerEventCard(); event offers are pushed in by NPC interactions
    //   (see InteractService) and this slice only consumes them.
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function eventOfferShow(User $user, int $offerId): array
    {
        $offer = $this->loadOwnedOffer($user, $offerId);

        return $this->offerPayload($offer);
    }

    /**
     * Draw the card for a pending offer. Does NOT consume stamina (parity
     * with legacy drawEventCard).
     *
     * @return array<string,mixed>
     */
    public function eventDraw(User $user, int $offerId): array
    {
        $offer = $this->loadOwnedOffer($user, $offerId);
        if ($offer->status !== 'pending') {
            abort(409, 'OFFER_NOT_PENDING');
        }
        $expiresAt = $offer->expires_at !== null ? Carbon::parse((string) $offer->expires_at) : null;
        if ($expiresAt !== null && $expiresAt->isPast()) {
            // Sweep stale rows so the next poll does not see them.
            $offer->status = 'missed';
            $offer->save();
            abort(409, 'OFFER_EXPIRED');
        }

        $card = $this->findCardById((string) $offer->card_id);
        if (! $card) {
            abort(404, 'CARD_NOT_FOUND');
        }

        $play = CardPlay::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'card_id' => $offer->card_id,
            'card_type' => (string) ($card['type'] ?? 'event'),
            'rarity' => (string) ($card['rarity'] ?? 'common'),
        ]);
        $offer->play_id = $play->id;
        $offer->save();

        $payload = $this->cardPayload($play->id, $card, isNew: true, user: $user);
        $payload['offer_id'] = $offer->id;

        return $payload;
    }

    /**
     * Mark a pending offer as skipped so it stops surfacing today.
     *
     * @return array<string,mixed>
     */
    public function eventSkip(User $user, int $offerId): array
    {
        $offer = $this->loadOwnedOffer($user, $offerId);
        if ($offer->status === 'pending') {
            $offer->status = 'skipped';
            $offer->save();
        }

        return ['skipped' => true, 'offer_id' => $offer->id];
    }

    /**
     * Draw a specific island-scene card by its hotspot card_id.
     * Costs stamina, respects tier gating and once-per-day-per-card rule.
     *
     * @return array<string,mixed>
     */
    public function sceneDraw(User $user, string $cardId): array
    {
        $card = $this->findCardById($cardId);
        if (! $card) {
            abort(404, 'CARD_NOT_FOUND');
        }

        if (! $this->tierSatisfies((string) $user->membership_tier, $card['tier_required'] ?? null)) {
            abort(403, 'TIER_LOCKED');
        }

        // Optional level gating: when the seed flags a min_level for the
        // hotspot's parent scene, honour it.  Falling back to the card's own
        // min_level when present (some scenario cards carry it directly).
        $minLevel = $this->minLevelForHotspot($cardId)
            ?? (isset($card['min_level']) ? (int) $card['min_level'] : null);
        if ($minLevel !== null && (int) $user->level < $minLevel) {
            abort(403, 'LEVEL_LOCKED');
        }

        $stamina = $this->getStamina($user);
        if ($stamina['remaining'] <= 0) {
            abort(409, 'NO_STAMINA');
        }

        // No re-draws of the same card within the same day.
        $already = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('card_id', $cardId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('answered_at')
            ->exists();
        if ($already) {
            abort(409, 'ALREADY_ANSWERED_TODAY');
        }

        $play = CardPlay::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'card_id' => $cardId,
            'card_type' => (string) ($card['type'] ?? 'scenario'),
            'rarity' => (string) ($card['rarity'] ?? 'common'),
        ]);

        $seenBefore = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('card_id', $cardId)
            ->where('id', '!=', $play->id)
            ->exists();

        return $this->cardPayload($play->id, $card, isNew: ! $seenBefore, user: $user);
    }

    /**
     * Tenant-safe loader. Throws 404 when the offer does not belong to the
     * current user — same external behaviour as “not found” keeps tenant
     * existence opaque.
     */
    private function loadOwnedOffer(User $user, int $offerId): CardEventOffer
    {
        $offer = CardEventOffer::where('id', $offerId)
            ->where('pandora_user_uuid', $user->pandora_user_uuid)
            ->first();
        if (! $offer) {
            abort(404, 'OFFER_NOT_FOUND');
        }

        return $offer;
    }

    /**
     * @return array<string,mixed>
     */
    private function offerPayload(CardEventOffer $offer): array
    {
        $card = $this->findCardById((string) $offer->card_id);

        return [
            'id' => $offer->id,
            'card_id' => $offer->card_id,
            'status' => $offer->status,
            'event_group' => $offer->event_group,
            'play_id' => $offer->play_id,
            'offered_at' => $this->toIso($offer->offered_at),
            'expires_at' => $this->toIso($offer->expires_at),
            // Card metadata is hydrated for the UI banner; choices stay
            // hidden until the user actually draws (so the answer key is
            // not leaked to skip/poll callers).
            'card' => $card === null ? null : [
                'id' => $card['id'] ?? null,
                'type' => $card['type'] ?? null,
                'rarity' => $card['rarity'] ?? null,
                'emoji' => $card['emoji'] ?? null,
                'question' => $card['question'] ?? null,
                'hint' => $card['hint'] ?? null,
            ],
        ];
    }

    /**
     * Walk the seeded island_scenes config for a hotspot whose card_id
     * matches and return its parent scene's min_level (or null).
     */
    private function minLevelForHotspot(string $cardId): ?int
    {
        $cfg = $this->config()->get('island_scenes') ?? [];
        $scenes = is_array($cfg['scenes'] ?? null) ? $cfg['scenes'] : [];
        foreach ($scenes as $scene) {
            foreach ((array) ($scene['hotspots'] ?? []) as $h) {
                if (($h['card_id'] ?? null) === $cardId) {
                    return isset($scene['min_level']) ? (int) $scene['min_level'] : null;
                }
            }
        }

        return null;
    }

    /**
     * Eloquent's datetime cast already inflates to Carbon at runtime, but
     * Larastan annotations type these columns as string|null. Normalising
     * through Carbon::parse keeps the API contract stable AND silences the
     * "instanceof Carbon will always evaluate to false" PHPDoc inference.
     */
    private function toIso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }

    /**
     * Tier gating mirrors legacy tierSatisfies(): null requirement = open
     * to all; otherwise the user must hold the requested membership tier
     * (or any “lifetime” tier counts as satisfying lower tiers).
     */
    private function tierSatisfies(string $userTier, ?string $required): bool
    {
        if ($required === null || $required === '' || $required === 'public') {
            return true;
        }
        if ($userTier === $required) {
            return true;
        }
        // Loose hierarchy: fp_lifetime / vip cover lower paid tiers.
        $rank = ['public' => 0, 'monthly' => 1, 'yearly' => 2, 'vip' => 3, 'fp_lifetime' => 4];

        return ($rank[$userTier] ?? 0) >= ($rank[$required] ?? 0);
    }
}
