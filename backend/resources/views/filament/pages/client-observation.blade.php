<x-filament-panels::page>
    {{-- UX sensitivity 提醒（同 FunnelDashboard）— 強顏色置頂 --}}
    <div class="mb-4 rounded-lg border-2 border-amber-600 bg-amber-50 p-4 text-sm leading-relaxed text-amber-900 dark:border-amber-500 dark:bg-amber-950/40 dark:text-amber-200">
        <div class="text-base font-semibold">⚠️ 本頁是「人工關心客戶」工具，<u>不是</u>行銷名單</div>
        <p class="mt-1">
            客戶<strong>很敏感</strong>。看到資料後請<strong>用人話聊天</strong>方式關心，
            <strong>不要</strong>把產品建議當行銷話術硬推。客戶 opt-out
            （preferences 中關閉「對加盟方案不感興趣」）的，<strong>絕對不要</strong>用此資料行銷。
        </p>
    </div>

    {{-- Search bar --}}
    <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <form wire:submit.prevent="doSearch" class="flex items-center gap-2">
            <input
                type="text"
                wire:model="search"
                placeholder="輸入客戶 email / 姓名 / pandora_user_uuid 查詢"
                class="flex-1 rounded-lg border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
            />
            <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700">
                查詢
            </button>
        </form>
    </div>

    @php($data = $this->clientData())

    @if (! $data && $search)
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            找不到符合「{{ $search }}」的客戶
        </div>
    @endif

    @if ($data)
        @php($u = $data['user'])
        @php($wr = $data['weekly_review'])
        @php($cur = $wr['current'] ?? [])
        @php($deltas = $wr['deltas'] ?? [])
        @php($commentary = $wr['dodo_commentary'] ?? null)
        @php($sparkline = $data['sparkline_path'])

        {{-- Header card --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $u->name ?: '（未命名）' }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $u->email }}</div>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-300">
                        <span>Lv.{{ $u->level }}</span>
                        <span>連續 {{ $u->current_streak }} 天</span>
                        <span>歷史最長 {{ $u->longest_streak }} 天</span>
                        <span>會員：{{ $u->membership_tier }}</span>
                        <span>訂閱：{{ $u->subscription_type }}</span>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-gray-500 dark:text-gray-400">目前體重</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                        {{ $u->current_weight_kg ? number_format($u->current_weight_kg, 1) . ' kg' : '—' }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        起 {{ $u->start_weight_kg ?: '—' }} / 標 {{ $u->target_weight_kg ?: '—' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Weight 30-day sparkline --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">📈 體重 30 天曲線</div>
            @if ($sparkline)
                <svg viewBox="0 0 600 80" preserveAspectRatio="none" class="h-20 w-full">
                    <path d="{{ $sparkline }}" stroke="#E89F7A" stroke-width="2" fill="none" stroke-linejoin="round" stroke-linecap="round"/>
                </svg>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    本週體重變化：
                    @if (($deltas['weight_change_kg'] ?? null) !== null)
                        {{ $deltas['weight_change_kg'] > 0 ? '+' : '' }}{{ $deltas['weight_change_kg'] }} kg
                    @else
                        資料不足
                    @endif
                </div>
            @else
                <div class="text-xs text-gray-500 dark:text-gray-400">尚無體重紀錄</div>
            @endif
        </div>

        {{-- Weekly review --}}
        @if ($commentary)
            <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50/50 p-4 dark:border-amber-700 dark:bg-amber-950/30">
                <div class="mb-1 text-sm font-bold text-amber-900 dark:text-amber-200">朵朵 weekly summary：{{ $commentary['headline'] }}</div>
                <ul class="ml-5 list-disc text-sm text-amber-800 dark:text-amber-300">
                    @foreach ($commentary['lines'] ?? [] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-amber-700 dark:text-amber-400 sm:grid-cols-4">
                    <div>記錄：{{ $cur['days_logged'] ?? 0 }} 天</div>
                    <div>餐次：{{ $cur['meals_logged'] ?? 0 }}</div>
                    <div>蛋白：{{ $cur['avg_protein_g'] ?? '—' }} g</div>
                    <div>分數：{{ $cur['avg_score'] ?? '—' }}</div>
                </div>
            </div>
        @endif

        {{-- Product suggestions --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">💡 產品建議（人工判斷參考）</div>
            <div class="space-y-2">
                @foreach ($data['suggestions'] as $s)
                    <div class="rounded border-l-4 border-amber-500 bg-amber-50 p-3 text-sm dark:bg-amber-950/40">
                        <div class="font-semibold text-amber-900 dark:text-amber-200">{{ $s['label'] }}</div>
                        <div class="text-xs text-amber-700 dark:text-amber-300">原因：{{ $s['reason'] }}</div>
                        <div class="mt-1 text-xs text-gray-700 dark:text-gray-300"><strong>建議產品：</strong>{{ $s['product'] }}</div>
                        <div class="text-xs italic text-gray-600 dark:text-gray-400"><strong>tone tip：</strong>{{ $s['tone'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Last 7 days log --}}
        <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">📋 最近 7 天紀錄</div>
            @if ($data['last_7_days']->isEmpty())
                <div class="text-xs text-gray-500 dark:text-gray-400">這週還沒有紀錄</div>
            @else
                <table class="w-full text-xs">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-1">日期</th>
                            <th>分</th>
                            <th>卡路里</th>
                            <th>蛋白</th>
                            <th>水</th>
                            <th>運動</th>
                            <th>體重</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['last_7_days'] as $log)
                            <tr class="border-t border-gray-100 text-gray-700 dark:border-gray-800 dark:text-gray-300">
                                <td class="py-1">{{ $log->date->format('m/d') }}</td>
                                <td>{{ $log->total_score }}</td>
                                <td>{{ $log->total_calories }}</td>
                                <td>{{ number_format($log->total_protein_g, 1) }}g</td>
                                <td>{{ $log->water_ml }}ml</td>
                                <td>{{ $log->exercise_minutes }}分</td>
                                <td>{{ $log->weight_kg ? number_format($log->weight_kg, 1) . 'kg' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Recent meals --}}
        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">🍱 最近 7 餐</div>
            @if ($data['recent_meals']->isEmpty())
                <div class="text-xs text-gray-500 dark:text-gray-400">尚無餐次紀錄</div>
            @else
                <ul class="space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($data['recent_meals'] as $meal)
                        <li class="flex justify-between border-b border-gray-100 py-1 dark:border-gray-800">
                            <span>{{ $meal->created_at?->format('m/d H:i') ?? '—' }} · {{ $meal->food_name ?? '（未命名）' }} <span class="text-gray-400">[{{ $meal->meal_type }}]</span></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $meal->calories ?? 0 }} kcal · {{ number_format($meal->protein_g ?? 0, 1) }}g 蛋白</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</x-filament-panels::page>
