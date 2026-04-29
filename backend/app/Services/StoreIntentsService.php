<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Loads the curated store decision tree (intent + 3 recommendations each)
 * from storage/app/store_intents.json. Migrated from ai-game data file.
 *
 * Cached 5 minutes — the file is read-only at runtime; ops can edit it
 * and bust the cache via Cache::forget('store_intents').
 */
class StoreIntentsService
{
    private const CACHE_KEY = 'store_intents';

    private const CACHE_TTL = 300;

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $path = storage_path('app/store_intents.json');
            if (! is_file($path)) {
                return ['stores' => []];
            }
            $raw = (string) file_get_contents($path);
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : ['stores' => []];
        });
    }

    /**
     * Return the intents (with prompt_line + recommendations) for a given
     * store key. Empty array when the store has no curated tree yet.
     *
     * @return list<array<string, mixed>>
     */
    public function intentsFor(string $storeKey): array
    {
        $all = $this->all();
        $store = $all['stores'][$storeKey] ?? null;
        if (! is_array($store)) {
            return [];
        }
        $intents = $store['intents'] ?? [];

        return is_array($intents) ? array_values($intents) : [];
    }
}
