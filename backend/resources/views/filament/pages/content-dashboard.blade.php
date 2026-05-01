{{-- /admin/content — 內容稽核儀表板。視覺優先：每 tab 都是縮圖牆。 --}}
<x-filament-panels::page>
    @php
        // Inline SVG helper: read file → base64 → data URI（避開靜態資產 routing）
        $inlineSvg = function (?string $path): ?string {
            if (! $path || ! is_file($path)) return null;
            $data = @file_get_contents($path);
            if ($data === false) return null;
            return 'data:image/svg+xml;base64,' . base64_encode($data);
        };
        $inlineImg = function (?string $path): ?string {
            if (! $path || ! is_file($path)) return null;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'application/octet-stream',
            };
            $data = @file_get_contents($path);
            if ($data === false) return null;
            // 1MB cap so the page doesn't bloat
            if (strlen($data) > 1024 * 1024) return null;
            return 'data:' . $mime . ';base64,' . base64_encode($data);
        };
        $rarityColor = [
            'common'    => '#9ca3af',
            'uncommon'  => '#22c55e',
            'rare'      => '#3b82f6',
            'epic'      => '#a855f7',
            'legendary' => '#f59e0b',
            'mythic'    => '#ef4444',
        ];
        $totals = [
            'art'          => array_sum(array_map('count', $art)),
            'achievements' => count($achievements),
            'achievements_unlocked_any' => collect($achievements)->where('unlocked_count', '>', 0)->count(),
            'cards'        => count($cards),
            'foods'        => count($foods),
            'foods_unlocked_any' => collect($foods)->where('unlocks', '>', 0)->count(),
            'pets'         => count($pets),
        ];
    @endphp

    <div x-data="{ tab: 'art' }" class="space-y-6">

        {{-- ─── Top stats bar ─── --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @foreach ([
                ['🎨', '美術素材', $totals['art'], 'art'],
                ['🏆', '成就', $totals['achievements'] . ' / ' . $totals['achievements_unlocked_any'] . ' 有人解鎖', 'achievements'],
                ['🎴', '卡牌題目', $totals['cards'], 'cards'],
                ['🍱', '食物圖鑑', $totals['foods'] . ' / ' . $totals['foods_unlocked_any'] . ' 被吃過', 'foods'],
                ['🐾', '寵物 species', $totals['pets'], 'pets'],
            ] as [$emoji, $label, $value, $key])
                <button type="button" @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'ring-2 ring-amber-500 bg-amber-50' : 'bg-white hover:bg-gray-50'"
                    class="text-left p-4 rounded-lg border border-gray-200 transition">
                    <div class="text-3xl">{{ $emoji }}</div>
                    <div class="text-xs text-gray-500 mt-1">{{ $label }}</div>
                    <div class="text-2xl font-bold text-gray-900 mt-1">{{ $value }}</div>
                </button>
            @endforeach
        </div>

        {{-- ─── Tab: 美術 ─── --}}
        <section x-show="tab === 'art'" class="space-y-6">
            @forelse ($art as $group => $files)
                <div class="bg-white rounded-lg border border-gray-200 p-4">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-gray-100 rounded text-xs">{{ $group }}</span>
                        <span class="text-gray-500">({{ count($files) }})</span>
                    </h3>
                    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-3">
                        @foreach ($files as $f)
                            @php $src = $inlineImg($f['path']); @endphp
                            <div class="group relative bg-gray-50 rounded p-2 hover:bg-amber-50 transition" title="{{ $f['name'] }} · {{ number_format(($f['size'] ?? 0) / 1024, 1) }}KB · {{ $f['mtime'] ? date('Y-m-d', $f['mtime']) : '' }}">
                                @if ($src)
                                    <img src="{{ $src }}" alt="{{ $f['name'] }}" class="w-full h-16 object-contain" loading="lazy">
                                @else
                                    <div class="w-full h-16 flex items-center justify-center text-gray-300 text-2xl">🖼️</div>
                                @endif
                                <div class="text-[10px] text-gray-600 mt-1 truncate text-center">{{ $f['name'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="bg-amber-50 border border-amber-200 rounded p-4 text-amber-800 text-sm">
                    找不到任何素材檔。確認 <code>pandora-design-svg</code> package 與 <code>frontend/public/</code> 路徑是否在 monorepo 結構下，或設 env <code>PANDORA_DESIGN_SVG_PATH</code> / <code>PANDORA_FRONTEND_PUBLIC_PATH</code>。
                </div>
            @endforelse
        </section>

        {{-- ─── Tab: 成就 ─── --}}
        <section x-show="tab === 'achievements'" class="space-y-3" style="display:none">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                @foreach ($achievements as $a)
                    @php
                        // best-effort badge svg lookup by key prefix
                        $badgeKey = match (true) {
                            str_contains($a['key'], 'streak_30') => 'badge_streak_gold',
                            str_contains($a['key'], 'streak_7')  => 'badge_streak_silver',
                            str_contains($a['key'], 'streak')    => 'badge_streak_bronze',
                            str_contains($a['key'], 'first')     => 'badge_first_bronze',
                            str_contains($a['key'], 'spend_10k'), str_contains($a['key'], 'full_constellation') => 'badge_milestone_gold',
                            str_contains($a['key'], 'foodie'), str_contains($a['key'], 'multi_app') => 'badge_milestone_silver',
                            default => 'badge_milestone_bronze',
                        };
                        $badgePath = ($svgRoot['design-svg']['badges'] ?? null) . '/' . $badgeKey . '.svg';
                        $badgeSrc = $inlineImg($badgePath);
                    @endphp
                    <div class="bg-white rounded-lg border border-gray-200 p-3 text-center">
                        <div class="h-20 flex items-center justify-center">
                            @if ($badgeSrc)
                                <img src="{{ $badgeSrc }}" alt="{{ $a['name'] }}" class="h-20 object-contain">
                            @else
                                <div class="text-5xl">🏆</div>
                            @endif
                        </div>
                        <div class="text-sm font-semibold text-gray-900 mt-2">{{ $a['name'] }}</div>
                        <div class="text-[11px] text-gray-500 mt-1 line-clamp-2">{{ $a['description'] }}</div>
                        <div class="mt-2 text-xs">
                            @if ($a['unlocked_count'] > 0)
                                <span class="inline-block px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded">{{ $a['unlocked_count'] }} 人解鎖</span>
                            @else
                                <span class="inline-block px-2 py-0.5 bg-gray-100 text-gray-400 rounded">尚未有人解鎖</span>
                            @endif
                        </div>
                        <div class="text-[10px] text-gray-400 mt-1 font-mono">{{ $a['key'] }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── Tab: 卡牌題目 ─── --}}
        <section x-show="tab === 'cards'" class="space-y-3" style="display:none"
            x-data="{ flipped: {} }">
            <div class="text-xs text-gray-500">點卡片翻面看題目 · 邊框顏色 = 稀有度</div>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                @foreach ($cards as $c)
                    @php $color = $rarityColor[$c['rarity']] ?? '#9ca3af'; @endphp
                    <div class="relative bg-white rounded-lg shadow-sm cursor-pointer transition hover:scale-105"
                         style="border: 3px solid {{ $color }};"
                         @click="flipped['{{ $c['id'] }}'] = !flipped['{{ $c['id'] }}']">
                        {{-- front --}}
                        <div x-show="!flipped['{{ $c['id'] }}']" class="p-3 text-center min-h-[180px] flex flex-col justify-between">
                            <div class="text-6xl mt-2">{{ $c['emoji'] }}</div>
                            <div>
                                <div class="text-[10px] uppercase tracking-wide font-bold mt-2" style="color: {{ $color }}">{{ $c['rarity'] }}</div>
                                <div class="text-xs text-gray-500">{{ $c['type'] }} · {{ $c['category'] }}</div>
                                <div class="text-xs text-gray-700 font-mono mt-1">{{ $c['id'] }}</div>
                                @if ($c['correct_rate'] !== null)
                                    <div class="mt-2 text-[11px]">
                                        <span class="text-gray-500">答對率</span>
                                        <span class="font-bold text-emerald-600">{{ $c['correct_rate'] }}%</span>
                                        <span class="text-gray-400">({{ $c['plays'] }})</span>
                                    </div>
                                @else
                                    <div class="mt-2 text-[11px] text-gray-400">尚未有人答</div>
                                @endif
                            </div>
                        </div>
                        {{-- back --}}
                        <div x-show="flipped['{{ $c['id'] }}']" class="p-3 min-h-[180px] text-left text-xs" style="display:none">
                            <div class="font-semibold text-gray-900 mb-2">{{ $c['question'] }}</div>
                            @if ($c['hint'])
                                <div class="text-gray-500 italic mb-2">💡 {{ $c['hint'] }}</div>
                            @endif
                            <ul class="space-y-1">
                                @foreach (($c['choices'] ?? []) as $choice)
                                    <li class="flex items-start gap-1">
                                        <span class="{{ ($choice['correct'] ?? false) ? 'text-emerald-600 font-bold' : 'text-gray-400' }}">
                                            {{ ($choice['correct'] ?? false) ? '✓' : '·' }}
                                        </span>
                                        <span class="{{ ($choice['correct'] ?? false) ? 'text-emerald-700' : 'text-gray-600' }}">{{ $choice['text'] ?? '' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($c['explain'])
                                <div class="mt-2 pt-2 border-t border-gray-100 text-gray-600">{{ $c['explain'] }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── Tab: 食物圖鑑 ─── --}}
        <section x-show="tab === 'foods'" class="space-y-3" style="display:none"
            x-data="{ cat: 'all' }">
            @php $cats = collect($foods)->pluck('category')->unique()->filter()->values(); @endphp
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="cat = 'all'"
                    :class="cat === 'all' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-700'"
                    class="px-3 py-1 rounded text-xs">全部 ({{ count($foods) }})</button>
                @foreach ($cats as $c)
                    @php $n = collect($foods)->where('category', $c)->count(); @endphp
                    <button type="button" @click="cat = '{{ $c }}'"
                        :class="cat === '{{ $c }}' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-700'"
                        class="px-3 py-1 rounded text-xs">{{ $c }} ({{ $n }})</button>
                @endforeach
            </div>
            <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2">
                @foreach ($foods as $f)
                    <div x-show="cat === 'all' || cat === '{{ $f['category'] }}'"
                        class="bg-white rounded border border-gray-200 p-2 text-center hover:shadow transition"
                        title="{{ $f['name'] }} · {{ $f['calories'] }} kcal · {{ $f['serving'] }}">
                        <div class="text-3xl {{ $f['unlocks'] === 0 ? 'opacity-30 grayscale' : '' }}">{{ $f['emoji'] }}</div>
                        <div class="text-xs font-medium text-gray-900 mt-1 truncate">{{ $f['name'] }}</div>
                        <div class="text-[10px] text-gray-500">{{ $f['calories'] }} kcal</div>
                        <div class="text-[10px] mt-1 {{ $f['unlocks'] > 0 ? 'text-emerald-600' : 'text-gray-300' }}">
                            @if ($f['unlocks'] > 0) {{ $f['unlocks'] }} 人解鎖 @else 未解鎖 @endif
                        </div>
                        @if (! $f['verified'])
                            <div class="text-[9px] text-amber-600 mt-0.5">未審核</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── Tab: 寵物 / 寵物故事 ─── --}}
        <section x-show="tab === 'pets'" class="space-y-3" style="display:none">
            <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-800">
                🚧 寵物故事（pet_stories）schema 尚未拍板。下面只列了目前的寵物 species。<br>
                當 pet_stories table 建立後，這裡會自動 render 每隻寵物的故事篇章列表 + 解鎖人數，無須改 code。
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach ($pets as $p)
                    <div class="bg-white rounded-lg border border-gray-200 p-4 text-center">
                        <div class="text-6xl">
                            @switch($p['key'])
                                @case('cat') 🐱 @break
                                @case('penguin') 🐧 @break
                                @case('hamster') 🐹 @break
                                @case('bear') 🐻 @break
                            @endswitch
                        </div>
                        <div class="text-sm font-semibold mt-2">{{ $p['label'] }}</div>
                        <div class="text-[10px] text-gray-400 font-mono">{{ $p['key'] }}</div>
                        <div class="text-xs text-gray-500 mt-2">故事：尚未實作</div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ─── footer hint ─── --}}
        <div class="text-[10px] text-gray-400 text-center pt-4">
            資料即時 from DB / app_config / SVG package · 不需要 rebuild · 新增成就 / 卡牌 / 食物 / 寵物故事都會自動出現
        </div>
    </div>
</x-filament-panels::page>
