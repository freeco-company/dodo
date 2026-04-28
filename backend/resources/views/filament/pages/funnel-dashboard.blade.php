<x-filament-panels::page>
    {{-- ASCII funnel header (ADR-003 §1.1 試算對齊) --}}
    <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h2 class="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">
            Lifecycle 漏斗示意
        </h2>
        <pre class="overflow-x-auto whitespace-pre font-mono text-xs leading-snug text-gray-700 dark:text-gray-300">
 ┌────────────────────────────┐  Visitor       訪客（含未登入流量）
 │  ███████████████████████   │
 └─────────────┬──────────────┘
   ┌──────────────────────┐    Registered   完成註冊
   │  ████████████████    │
   └──────────┬───────────┘
     ┌────────────────┐        Engaged      30 日內活躍
     │  ████████████  │
     └───────┬────────┘
       ┌──────────┐            Loyalist     深度使用 / 訂閱
       │  ██████  │
       └────┬─────┘
         ┌─────┐                Applicant   發起加盟諮詢
         │ ███ │
         └──┬──┘
           ┌─┐                  Franchisee  正式加盟（首單 $6,600+）
           │█│
           └─┘
        </pre>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            資料來源：py-service <code>GET /api/v1/funnel/metrics</code>（HMAC X-Internal-Secret 內部端點）；
            base_url 未設定時顯示 stub fixture。
        </p>
    </div>

    {{-- 最近 30 天 transition log（v1 stub）。Header widget 由 Filament 自動 render 在上方。 --}}
    <div class="mt-6 rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
        <div class="font-medium text-gray-900 dark:text-gray-100">最近 30 天 stage transition</div>
        <p class="mt-1">
            TODO（v2）：接 py-service <code>/funnel/transitions?window=30d</code>，依日期顯示 visitor→registered、loyalist→applicant 等轉換次數。
            目前 py-service 尚未提供此端點，本區塊為 placeholder。
        </p>
    </div>
</x-filament-panels::page>
