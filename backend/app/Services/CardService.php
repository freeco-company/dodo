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
        if ($card && isset($card['choices'][$choiceIdx])) {
            $choice = $card['choices'][$choiceIdx];
            $correct = (bool) ($choice['correct'] ?? false);
            $feedback = $choice['feedback'] ?? null;
            $explain = $card['explain'] ?? null;
        }

        $play->choice_idx = $choiceIdx;
        $play->correct = $correct;
        // Simple XP curve: correct answer 8, wrong 3, missing card metadata 5.
        $play->xp_gained = $correct === null ? 5 : ($correct ? 8 : 3);
        $play->answered_at = now();
        $play->save();

        $user->xp = (int) $user->xp + $play->xp_gained;
        $user->level = GameXp::levelForXp((int) $user->xp);
        $user->save();

        return [
            'card_id' => $play->card_id,
            'card_type' => $play->card_type,
            'chosen_idx' => $choiceIdx,
            'correct' => $correct,
            'feedback' => $feedback,
            'explain' => $explain,
            'xp_gained' => $play->xp_gained,
            'level' => (int) $user->level,
            'stamina' => $this->getStamina($user),
        ];
    }

    public function collection(User $user): array
    {
        $rows = CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereNotNull('answered_at')
            ->select('card_id', 'card_type', 'rarity')
            ->groupBy('card_id', 'card_type', 'rarity')
            ->get();

        return [
            'total' => $rows->count(),
            'cards' => $rows->map(fn ($r) => [
                'card_id' => $r->card_id,
                'type' => $r->card_type,
                'rarity' => $r->rarity,
            ])->all(),
        ];
    }
}
