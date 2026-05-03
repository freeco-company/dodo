<?php

namespace App\Services\Ritual;

use Carbon\CarbonImmutable;

/**
 * SPEC-progress-ritual-v1 PR #9 — hardcoded seasonal outfit release calendar.
 *
 * v1: catalog lives in code (no DB schema work). v2 should migrate to a
 * `seasonal_releases` table when content team needs to manage releases
 * without deploys.
 *
 * Each release has:
 *   - id: stable string key (used as ritual idempotency_key suffix)
 *   - season_name: user-facing label
 *   - outfit_codes: array of outfit keys this release unlocks
 *   - release_at: when the release goes live (Asia/Taipei)
 *   - expires_at: 60 days later (limited-time)
 *
 * SeasonRevealService surfaces "current" releases (release_at <= now <
 * expires_at) on first-open-of-day, fires KEY_SEASON_REVEAL once per
 * (user, release_id).
 */
class SeasonalReleaseCatalog
{
    /**
     * @return array<int, array{
     *     id: string,
     *     season_name: string,
     *     outfit_codes: array<int, string>,
     *     release_at: CarbonImmutable,
     *     expires_at: CarbonImmutable,
     * }>
     */
    public function all(): array
    {
        $tz = 'Asia/Taipei';
        $year = (int) CarbonImmutable::now($tz)->year;
        $next = $year + 1;

        return $this->buildForYears([$year, $next]);
    }

    /** @return array<int, array<string,mixed>> */
    public function currentlyActive(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Asia/Taipei');

        return array_values(array_filter($this->all(), function ($r) use ($now) {
            return $r['release_at']->lessThanOrEqualTo($now)
                && $now->lessThan($r['expires_at']);
        }));
    }

    /** @return ?array<string,mixed> */
    public function findById(string $id): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $years
     * @return array<int, array<string,mixed>>
     */
    private function buildForYears(array $years): array
    {
        // SPEC §2.1 — 4 季 + 4 節日 = 8/year.
        $tz = 'Asia/Taipei';
        $rows = [];
        foreach ($years as $y) {
            // Seasonal — 60 day limited.
            $rows[] = $this->row("spring-{$y}", '春櫻系列 🌸', ['sakura_kimono', 'sakura'], CarbonImmutable::create($y, 2, 4, 0, 0, 0, $tz), 60);
            $rows[] = $this->row("summer-{$y}", '夏夜系列 🌊', ['summer_yukata', 'straw_hat'], CarbonImmutable::create($y, 5, 5, 0, 0, 0, $tz), 60);
            $rows[] = $this->row("autumn-{$y}", '秋楓系列 🍂', ['autumn_maple'], CarbonImmutable::create($y, 8, 7, 0, 0, 0, $tz), 60);
            $rows[] = $this->row("winter-{$y}", '冬雪系列 ❄️', ['winter_scarf'], CarbonImmutable::create($y, 11, 7, 0, 0, 0, $tz), 60);
            // Festival — 14 day limited.
            $rows[] = $this->row("midautumn-{$y}", '中秋特別款 🌕', ['mid_autumn'], CarbonImmutable::create($y, 9, 10, 0, 0, 0, $tz), 14);
            $rows[] = $this->row("xmas-{$y}", '聖誕特別款 🎄', ['christmas'], CarbonImmutable::create($y, 12, 18, 0, 0, 0, $tz), 14);
            $rows[] = $this->row("newyear-{$y}", '新年特別款 🧧', ['lunar_new_year'], CarbonImmutable::create($y, 1, 25, 0, 0, 0, $tz), 14);
            $rows[] = $this->row("qixi-{$y}", '七夕特別款 💝', ['qixi'], CarbonImmutable::create($y, 8, 1, 0, 0, 0, $tz), 14);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, mixed>
     */
    private function row(string $id, string $name, array $codes, CarbonImmutable $releaseAt, int $expiresInDays): array
    {
        return [
            'id' => $id,
            'season_name' => $name,
            'outfit_codes' => $codes,
            'release_at' => $releaseAt,
            'expires_at' => $releaseAt->addDays($expiresInDays),
        ];
    }
}
