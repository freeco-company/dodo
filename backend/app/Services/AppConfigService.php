<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Translated (slimmed) from ai-game/src/services/app_config.ts.
 *
 * Runtime-editable content store: pricing tiers, disclaimer copy, push
 * templates, etc. Cached 30s in-process to keep /api/bootstrap cheap.
 */
class AppConfigService
{
    private const CACHE_TTL = 30;

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("app_config:{$key}", self::CACHE_TTL, function () use ($key, $default) {
            $row = DB::table('app_config')->where('key', $key)->first(['value']);
            if (! $row) return $default;
            $decoded = json_decode((string) $row->value, true);
            return $decoded ?? $default;
        });
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $rows = DB::table('app_config')->get(['key', 'value']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->key] = json_decode((string) $r->value, true);
        }
        return $out;
    }

    /**
     * Stable content version derived from latest config row's updated_at.
     * Frontend uses this to decide whether to invalidate cached config.
     */
    public function contentVersion(): string
    {
        return (string) Cache::remember('app_config:_version', self::CACHE_TTL, function () {
            $latest = DB::table('app_config')->max('updated_at');
            return $latest ? (string) strtotime((string) $latest) : '0';
        });
    }

    public function set(string $key, mixed $value): void
    {
        DB::table('app_config')->upsert(
            [[
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]],
            ['key'],
            ['value', 'updated_at']
        );
        Cache::forget("app_config:{$key}");
    }
}
