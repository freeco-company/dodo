<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * SPEC-seasonal-outfit-cards Phase 1 — calendar of seasonal / holiday
 * content windows and helpers for the Cards completion + countdown UX.
 *
 * The catalog is intentionally a code constant (not DB-driven) — the
 * content production workflow (美術 / 文案) attaches outfit / card
 * definitions out-of-band; this service answers "what's active right now"
 * and "how long until expiry" questions for the frontend countdown chip.
 *
 * Year-agnostic: dates are MM-DD anchors, materialized into the user's
 * current year (and past year for windows that span Dec→Jan).
 */
class SeasonalContentService
{
    /**
     * SPEC §2.1 release calendar.
     *
     * @var list<array{key:string,label:string,start_md:string,end_md:string,kind:string}>
     */
    public const CATALOG = [
        ['key' => 'spring',     'label' => '春櫻系列',  'start_md' => '02-04', 'end_md' => '04-04', 'kind' => 'season'],
        ['key' => 'summer',     'label' => '海洋夏夜',  'start_md' => '05-05', 'end_md' => '07-04', 'kind' => 'season'],
        ['key' => 'autumn',     'label' => '楓葉月圓',  'start_md' => '08-07', 'end_md' => '10-06', 'kind' => 'season'],
        ['key' => 'winter',     'label' => '雪夜暖爐',  'start_md' => '11-07', 'end_md' => '01-06', 'kind' => 'season'],
        ['key' => 'mid_autumn', 'label' => '中秋特別款', 'start_md' => '09-10', 'end_md' => '09-24', 'kind' => 'holiday'],
        ['key' => 'christmas',  'label' => '聖誕特別款', 'start_md' => '12-15', 'end_md' => '12-29', 'kind' => 'holiday'],
        ['key' => 'new_year',   'label' => '新年特別款', 'start_md' => '01-25', 'end_md' => '02-08', 'kind' => 'holiday'],
        ['key' => 'qixi',       'label' => '七夕特別款', 'start_md' => '08-01', 'end_md' => '08-15', 'kind' => 'holiday'],
    ];

    /**
     * Resolve which (if any) seasonal windows are active at $now.
     *
     * @return list<array{key:string,label:string,kind:string,start:string,end:string,days_remaining:int}>
     */
    public function activeAt(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now('Asia/Taipei');
        $active = [];
        foreach (self::CATALOG as $entry) {
            $window = $this->materializeWindow($entry, $now);
            if ($window === null) {
                continue;
            }
            [$start, $end] = $window;
            if ($now->greaterThanOrEqualTo($start) && $now->lessThanOrEqualTo($end)) {
                $active[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'kind' => $entry['kind'],
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'days_remaining' => max(0, (int) $now->startOfDay()->diffInDays($end->startOfDay())),
                ];
            }
        }

        return $active;
    }

    /**
     * Resolve the next upcoming window of each kind so we can show
     * "下一個季節限定 N 天後上架" hints.
     *
     * @return list<array{key:string,label:string,kind:string,start:string,end:string,days_until:int}>
     */
    public function upcomingAt(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now('Asia/Taipei');
        $out = [];
        foreach (self::CATALOG as $entry) {
            $window = $this->materializeWindow($entry, $now, futureBias: true);
            if ($window === null) {
                continue;
            }
            [$start, $end] = $window;
            if ($start->greaterThan($now)) {
                $out[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'kind' => $entry['kind'],
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'days_until' => (int) $now->startOfDay()->diffInDays($start->startOfDay()),
                ];
            }
        }
        usort($out, fn ($a, $b) => $a['days_until'] <=> $b['days_until']);

        return $out;
    }

    /**
     * Materialize a (start, end) pair around $now. Handles wrap-around
     * cases like winter (11-07 → 01-06).
     *
     * @param  array{key:string,label:string,start_md:string,end_md:string,kind:string}  $entry
     * @return array{0:CarbonImmutable,1:CarbonImmutable}|null
     */
    private function materializeWindow(array $entry, CarbonImmutable $now, bool $futureBias = false): ?array
    {
        $year = (int) $now->format('Y');
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$year}-{$entry['start_md']}", 'Asia/Taipei');
        $end = CarbonImmutable::createFromFormat('Y-m-d', "{$year}-{$entry['end_md']}", 'Asia/Taipei');
        if ($start === null || $end === null) {
            return null;
        }
        // Wrap-around (winter 11-07 → 01-06)
        if ($end->lessThan($start)) {
            $end = $end->addYear();
        }
        // If both are already past and we're looking for upcoming, push forward a year
        if ($futureBias && $end->lessThan($now)) {
            $start = $start->addYear();
            $end = $end->addYear();
        }
        // If we're looking at active windows and we're past the end, also try last-year-start
        if (! $futureBias && $now->lessThan($start)) {
            $prevStart = $start->subYear();
            $prevEnd = $end->subYear();
            if ($now->greaterThanOrEqualTo($prevStart) && $now->lessThanOrEqualTo($prevEnd)) {
                return [$prevStart, $prevEnd];
            }
        }

        return [$start, $end];
    }
}
