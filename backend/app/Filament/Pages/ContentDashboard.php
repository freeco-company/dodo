<?php

namespace App\Filament\Pages;

use App\Http\Controllers\Api\AchievementController;
use App\Models\Achievement;
use App\Models\Food;
use App\Services\AppConfigService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * /admin/content — 集團主理人的「內容稽核儀表板」。
 *
 * 上線後一站看：目前有哪些美術 / 成就 / 卡牌題目 / 食物圖鑑 / 寵物故事，
 * 以及最近改了什麼。資料即時 from DB / JSON / SVG package，不用 rebuild。
 *
 * 視覺優先：每個 tab 第一眼是縮圖牆，不是表格。
 */
class ContentDashboard extends Page
{
    protected static ?string $navigationLabel = '內容圖鑑';

    protected static ?string $title = '內容圖鑑（美術 / 成就 / 題目 / 食物 / 寵物）';

    protected static string|\UnitEnum|null $navigationGroup = '內容';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'content';

    protected string $view = 'filament.pages.content-dashboard';

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        return [
            'art' => $this->loadArt(),
            'achievements' => $this->loadAchievements(),
            'cards' => $this->loadCards(),
            'foods' => $this->loadFoods(),
            'pets' => $this->loadPets(),
            'svgRoot' => $this->resolveSvgRoot(),
        ];
    }

    /**
     * 美術：掃 design-svg package + outfits + characters。回 grouped 圖檔列表。
     *
     * @return array<string, list<array{name:string, path:string, mtime:?int, size:?int}>>
     */
    private function loadArt(): array
    {
        $groups = [
            'badges' => $this->scanSvgDir($this->designSvgPath('badges')),
            'icons' => $this->scanSvgDir($this->designSvgPath('icons')),
            'anchors' => $this->scanSvgDir($this->designSvgPath('anchors')),
            'empty' => $this->scanSvgDir($this->designSvgPath('empty')),
            'solar-terms' => $this->scanSvgDir($this->designSvgPath('solar-terms')),
            'outfits' => $this->scanSvgDir($this->frontendPublicPath('outfits')),
            'characters' => $this->scanArtDir($this->frontendPublicPath('characters'), ['png', 'svg', 'jpg', 'webp']),
        ];

        return array_filter($groups, fn ($v) => ! empty($v));
    }

    /**
     * 成就：catalog + 解鎖人數。
     *
     * @return list<array{key:string, name:string, description:string, unlocked_count:int}>
     */
    private function loadAchievements(): array
    {
        $counts = Achievement::query()
            ->select('achievement_key', DB::raw('COUNT(DISTINCT pandora_user_uuid) as c'))
            ->groupBy('achievement_key')
            ->pluck('c', 'achievement_key')
            ->toArray();

        return array_map(fn ($a) => [
            'key' => $a['key'],
            'name' => $a['name'],
            'description' => $a['description'],
            'unlocked_count' => (int) ($counts[$a['key']] ?? 0),
        ], AchievementController::CATALOG);
    }

    /**
     * 卡牌題目：from app_config.question_decks.cards + 答對率。
     *
     * @return list<array<string,mixed>>
     */
    private function loadCards(): array
    {
        $deck = app(AppConfigService::class)->get('question_decks') ?? [];
        $cards = is_array($deck['cards'] ?? null) ? $deck['cards'] : [];

        // answer rate per card_id
        $stats = DB::table('card_plays')
            ->select('card_id',
                DB::raw('COUNT(*) as plays'),
                DB::raw('SUM(CASE WHEN answered_correctly = 1 THEN 1 ELSE 0 END) as correct'))
            ->whereNotNull('answered_at')
            ->groupBy('card_id')
            ->get()
            ->keyBy('card_id');

        return array_map(function ($c) use ($stats) {
            $row = $stats->get($c['id'] ?? '');
            $plays = (int) ($row->plays ?? 0);
            $correct = (int) ($row->correct ?? 0);

            return [
                'id' => $c['id'] ?? '',
                'type' => $c['type'] ?? '',
                'category' => $c['category'] ?? '',
                'rarity' => $c['rarity'] ?? 'common',
                'emoji' => $c['emoji'] ?? '🃏',
                'question' => $c['question'] ?? '',
                'hint' => $c['hint'] ?? '',
                'choices' => $c['choices'] ?? [],
                'explain' => $c['explain'] ?? '',
                'plays' => $plays,
                'correct_rate' => $plays > 0 ? round($correct / $plays * 100) : null,
            ];
        }, $cards);
    }

    /**
     * 食物圖鑑：所有 food + 全站解鎖數。
     *
     * @return list<array<string,mixed>>
     */
    private function loadFoods(): array
    {
        $unlocks = DB::table('food_discoveries')
            ->select('food_id', DB::raw('COUNT(DISTINCT user_id) as c'))
            ->groupBy('food_id')
            ->pluck('c', 'food_id')
            ->toArray();

        return Food::query()
            ->orderBy('category')
            ->orderBy('name_zh')
            ->get(['id', 'name_zh', 'category', 'calories', 'serving_description', 'verified'])
            ->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name_zh,
                'category' => $f->category,
                'calories' => $f->calories,
                'serving' => $f->serving_description,
                'emoji' => $this->foodEmoji($f->category),
                'unlocks' => (int) ($unlocks[$f->id] ?? 0),
                'verified' => (bool) $f->verified,
            ])
            ->all();
    }

    /**
     * 寵物 / 寵物故事：placeholder。schema 還沒拍板。
     */
    private function loadPets(): array
    {
        $species = ['cat' => '貓', 'penguin' => '企鵝', 'hamster' => '倉鼠', 'bear' => '熊'];
        $items = [];
        foreach ($species as $key => $label) {
            $items[] = [
                'key' => $key,
                'label' => $label,
                'sprite' => $this->frontendPublicUrl("characters/pet_{$key}.png"),
                'stories' => [], // TODO: when pet_stories table lands
            ];
        }

        return $items;
    }

    private function foodEmoji(?string $category): string
    {
        return match ($category) {
            'protein', '蛋白' => '🍗',
            'staple', '主食' => '🍚',
            'vegetable', 'fruit', '蔬果' => '🥗',
            'drink', '飲品' => '🥤',
            'meal', '餐點' => '🍱',
            'snack', '點心' => '🍪',
            default => '🍽️',
        };
    }

    /** Try multiple candidate paths for the design-svg package + return first hit. */
    private function designSvgPath(string $sub): ?string
    {
        $candidates = [
            env('PANDORA_DESIGN_SVG_PATH'),
            base_path('../../pandora-design-svg'),
            base_path('../pandora-design-svg'),
            '/Users/chris/freeco/pandora/pandora-design-svg',
        ];
        foreach ($candidates as $root) {
            if ($root && is_dir($root . DIRECTORY_SEPARATOR . $sub)) {
                return rtrim($root, '/') . '/' . $sub;
            }
        }

        return null;
    }

    private function frontendPublicPath(string $sub): ?string
    {
        $candidates = [
            env('PANDORA_FRONTEND_PUBLIC_PATH'),
            base_path('../frontend/public'),
            base_path('../../pandora-meal/frontend/public'),
        ];
        foreach ($candidates as $root) {
            if ($root && is_dir($root . DIRECTORY_SEPARATOR . $sub)) {
                return rtrim($root, '/') . '/' . $sub;
            }
        }

        return null;
    }

    private function frontendPublicUrl(string $rel): string
    {
        $base = env('PANDORA_FRONTEND_URL', '');

        return $base ? rtrim($base, '/') . '/' . ltrim($rel, '/') : '/' . ltrim($rel, '/');
    }

    /** For the blade view to embed inline SVG safely. */
    private function resolveSvgRoot(): array
    {
        return [
            'design-svg' => [
                'badges' => $this->designSvgPath('badges'),
                'icons' => $this->designSvgPath('icons'),
                'anchors' => $this->designSvgPath('anchors'),
                'empty' => $this->designSvgPath('empty'),
                'solar-terms' => $this->designSvgPath('solar-terms'),
            ],
            'frontend' => [
                'outfits' => $this->frontendPublicPath('outfits'),
                'characters' => $this->frontendPublicPath('characters'),
            ],
        ];
    }

    /** @return list<array{name:string, path:string, mtime:?int, size:?int}> */
    private function scanSvgDir(?string $dir): array
    {
        return $this->scanArtDir($dir, ['svg']);
    }

    /**
     * @param  list<string>  $exts
     * @return list<array{name:string, path:string, mtime:?int, size:?int}>
     */
    private function scanArtDir(?string $dir, array $exts): array
    {
        if (! $dir || ! is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (! in_array($ext, $exts, true)) {
                continue;
            }
            $full = $dir . '/' . $f;
            $files[] = [
                'name' => pathinfo($f, PATHINFO_FILENAME),
                'path' => $full,
                'ext' => $ext,
                'mtime' => @filemtime($full) ?: null,
                'size' => @filesize($full) ?: null,
            ];
        }
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $files;
    }
}
