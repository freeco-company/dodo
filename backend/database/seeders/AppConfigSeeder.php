<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads every JSON file under database/seed/ into the `app_config` table.
 *
 * key   = filename without extension (e.g. `question_decks`)
 * value = full decoded JSON payload (stored re-encoded to keep JSON column)
 *
 * Why app_config and not a dedicated table per content type?
 *  - The legacy ai-game node backend used app_config as a runtime-editable
 *    KV store, and our AppConfigService already reads it with caching.
 *  - PM/content team can hot-edit cards, NPC dialogs, scenarios in production
 *    via an admin tool without a deploy.
 *  - Card draw still pulls from app_config['question_decks'].cards rather
 *    than a separate `cards` table; see CardService::draw().
 *  - Per-user "this user's currently offered card" remains in
 *    card_event_offers (event-driven NPC offers), and history in card_plays.
 */
class AppConfigSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('seed');
        if (! is_dir($dir)) {
            $this->command?->warn("seed directory missing: {$dir}");
            return;
        }

        $files = glob($dir.'/*.json') ?: [];
        $rows = [];
        $now = now();

        foreach ($files as $path) {
            $key = pathinfo($path, PATHINFO_FILENAME);
            $raw = file_get_contents($path);
            if ($raw === false) {
                $this->command?->warn("could not read {$path}");
                continue;
            }
            $decoded = json_decode($raw, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                $this->command?->warn("invalid JSON in {$path}: ".json_last_error_msg());
                continue;
            }
            // Re-encode to normalise (and to make json_encode error early if non-utf8).
            $rows[] = [
                'key' => $key,
                'value' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) return;

        // upsert so reseeding overwrites stale content.
        DB::table('app_config')->upsert($rows, ['key'], ['value', 'updated_at']);

        $this->command?->info('Seeded app_config keys: '.implode(', ', array_column($rows, 'key')));
    }
}
