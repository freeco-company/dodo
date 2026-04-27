<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * ADR — Card pool architecture decision (2026-04-28).
 *
 * Original task suggested two options:
 *   (a) Flatten question_decks + knowledge_decks into rows in
 *       `card_event_offers` (one row per card, status=pending, user=null).
 *   (b) Build a separate `card_pool` / `cards` table acting as the master
 *       pool, then assign a row to a user on draw.
 *
 * We chose neither.  Rationale:
 *
 *   1. Content lives in app_config.question_decks already — that table is
 *      runtime-editable and cached.  Duplicating into a relational table
 *      means content edits need migrations or a sync job.
 *   2. card_event_offers semantically means "this user has been offered this
 *      specific card right now" — pre-seeding it with status=pending for
 *      user_id=null violates the FK + the user-facing meaning, and the
 *      column is non-nullable (`foreignId('user_id')->constrained()`).
 *   3. The legacy ai-game backend draws by random sampling against the JSON
 *      and excluding what's already in card_plays for that user — we keep
 *      the same pattern in CardService::draw().
 *
 * Therefore there is no pool to seed; this seeder is intentionally a no-op
 * and exists only as a paper-trail.  card_event_offers stays empty until a
 * gameplay event (NPC interaction, scenario hook) creates one for a user.
 *
 * If we ever decide to move to option (b), the migration is:
 *   - new table `card_pool(card_id, type, category, rarity, payload json)`
 *   - CardEventOfferSeeder becomes a real loader from question_decks.cards
 *   - CardService::draw() switches to a join-based "unseen" query.
 */
class CardEventOfferSeeder extends Seeder
{
    public function run(): void
    {
        // No-op by design. See class docblock.
    }
}
