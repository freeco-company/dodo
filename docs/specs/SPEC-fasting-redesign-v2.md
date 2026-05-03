# SPEC：間歇性斷食 v2 — 認真設計

> 📅 起草：2026-05-03（v1 已上線但 UX 太陽春，user 要求 redesign）
> 🎯 目標：把斷食做成減脂用戶真的會每天用、會付費、會分享的核心鉤子
> 📊 對標：Zero（已被 BodyGroup 收購、業界標竿）、Yazio Fasting、Fastic、Simple
> 🔗 關聯：SPEC-fasting-timer.md（v1，已 deployed），SPEC-photo-ai-calorie-polish, SPEC-weekly-ai-report

---

## 1. v1 vs 對標差距

| 維度 | 朵朵 v1 | Zero / Yazio | 差距 |
|---|---|---|---|
| 進入入口 | 只在 Me tab 列表第 4 條 | 獨立底部 tab + 桌面 widget | 入口太隱密、發現率低 |
| 進度視覺 | 單一綠色 ring + 「0h 0m」 | 雙環（斷食窗 + 進食窗）+ 階段彩色弧 | 看不出「現在到哪」 |
| 階段教育 | 6 個 emoji + 一行字 | 每階段獨立卡片 + 「進入時」推播 + 知識庫 | 朵朵應該講為什麼，不是只標籤 |
| 開始/結束 | 開始時間 = now | 可選「上一餐結束時間」回溯 + 提早結束 confirm | 用戶忘記開始就掛了 |
| 進食窗 | 只算斷食 | 雙計時：吃完餐後倒數到下一次斷食開始 | 半個體驗缺失 |
| 歷史視覺 | 文字 list | 7/30/90 天柱狀圖 + 達標率% + 連勝 | 沒有成就感曲線 |
| 朵朵互動 | 模板 toast | 開始/中段/結束/破連勝 4 個動態語氣 | 用戶感受不到陪伴 |
| 推播 | 完成時一條 | 12h/16h/最後 30min/破連勝風險/隔週回顧 | 我們已有 6 條 templates 但沒 fire |
| 社交分享 | 無 | Apple Health / Strava-style 分享圖 | 留到 v3 |
| 圈內人鉤子 | 無 | Streak shield、節慶倍率、社群挑戰 | 留到 v3 |

---

## 2. v2 重新設計（this spec）

### 2.1 入口層級提升

**之前**：Me tab → 第 4 條列表
**之後**：
- ⭐ **底部 tab 第 5 格**「斷食」（取代現有 Cards tab，Cards 移進 Me）
- ⭐ **Home tab 浮出 widget**：斷食中時，置頂顯示「斷食中 12h 24m · 剩 3h 36m 可吃」+ 大按鈕「結束斷食」
- 兼容舊路徑：Me tab 的「間歇性斷食」row 保留

### 2.2 主畫面 redesign

```
┌─────────────────────────────┐
│  斷食模式  ▼  16:8           │  ← 模式 chip 可 tap 切換（不是被動文字）
│                              │
│      ╭──────────╮             │
│     ╱   斷食中   ╲            │  ← 雙環：外層斷食進度 / 內層階段顏色
│    ╱  ╭──────╮   ╲           │
│   │  │   12h    │  │          │  ← 大字 elapsed
│   │  │   24m    │  │          │
│   │  │ ───────  │  │          │
│   │  │  60% ✦   │  │          │  ← 達標率（不是「目標 16h」舊式）
│    ╲  ╰──────╯   ╱           │
│     ╲           ╱             │
│      ╰─────────╯              │
│                              │
│   🔥 脂肪燃燒中                 │  ← 階段彩色 chip（非 emoji 串）
│   ──────────────              │
│                              │
│   下個階段：自噬作用            │  ← 朵朵 voice
│   還有 3h 36m，繼續加油 ✨      │
│                              │
│   [開始時間：昨晚 20:30]       │  ← 可 tap 改時間（修正「忘記開始」）
│                              │
│   [📝 結束斷食]                │
└─────────────────────────────┘
```

### 2.3 階段視覺化（重點升級）

不再用 emoji 列。改用 **6 段彩色弧** 在外環，當前階段填色、過去階段半透明、未來階段灰色：

| 階段 | 時長 | 顏色 | 朵朵描述 |
|---|---|---|---|
| 🍱 進食消化 | 0-4h | #F5B5C5 粉 | 「身體在處理上一餐 🌷」 |
| 🌱 進入空腹 | 4-8h | #C9D3BE 淺綠 | 「身體開始休息 🌱」 |
| ✨ 肝醣轉換 | 8-12h | #7FB069 綠 | 「能量切換中，撐住 ✨」 |
| 🔥 脂肪燃燒 | 12-16h | #E89F7A 橘 | 「進入脂肪燃燒區 🔥」 |
| 🌟 自噬作用 | 16-20h | #C870A0 紫 | 「細胞清潔模式 🌟」（限 18:6+） |
| 💪 深度斷食 | 20h+ | #7A2810 深紅 | 「妳真的很有毅力 💪 補水」 |

每個階段第一次進入時 → 推播 + 朵朵 chat 自動發訊息（用既有 PushDispatcher）。

### 2.4 進食窗計時（缺失功能）

斷食結束後不該歸零。應該倒數「進食窗剩 X 小時」：

```
進食模式 16:8
進食窗 ████████░░  6h 12m / 8h
還能吃 1h 48m（最後一口時間 14:30）
```

到剩 30 分鐘 → 推播「最後 30 分鐘可以吃 ✨」（既有 fasting_pre_eat template）。
到 0 分鐘 → 自動進入下一個斷食 session（取代手動「開始斷食」）。

→ 真正的 16:8 循環體驗，而不是「斷食 → 結束 → 等用戶想起來再開始」。

### 2.5 開始時間回溯（用戶痛點）

「忘記按開始」是斷食 App #1 抱怨。Zero 的解法：
- 主畫面顯示開始時間，可 tap 修改
- 修改範圍：過去 24 小時內任意時刻
- 修改後立即重算 elapsed_minutes

我們之前的 backend 已經 support `started_at` 參數（FastingService::start payload），缺前端 UI。

### 2.6 提早結束 confirm

如果還沒達標就結束 → 跳 modal：
> 「還差 3h 24m 達標 16:8 ✨
>   還是要結束嗎？朵朵不會 judge 妳，但晚一點結束效果更好。」
> [再等等] [結束斷食]

避免誤觸。

### 2.7 歷史視覺化升級

```
最近 7 天
┌────┬────┬────┬────┬────┬────┬────┐
│ ▓▓ │ ▓  │ ▓▓ │ ░  │ ▓▓ │ ▓▓ │ ▓▓ │
│16h │14h │16h │ — │16h │18h │16h │
└────┴────┴────┴────┴────┴────┴────┘
達標 6/7 天 · 連勝 3 天 🔥
```

7 天柱狀圖（顏色按階段）+ 達標率 + 連勝。Free 看 7 天，Paid 看 30/90 天 + 月平均。

---

## 3. 商業層級（為什麼這值得做）

### 3.1 訂閱轉換鉤子

| 觸發點 | Free 看到 | Paid 解鎖 |
|---|---|---|
| 試 16:8 連勝 7 天 | 朵朵：「妳適合斷食 ✨ 想試 18:6 看看？」 | 18:6 / OMAD / 自定義 |
| 看歷史 > 7 天 | 「升級看完整 30 天 trend」 | 30/90 天圖表 |
| 自噬階段第一次到 16h | 「進入自噬區 🌟 升級看朵朵深度說明」 | 朵朵動態 AI 點評（非模板） |
| 連勝 14 天達標 | 「妳超穩 💪 升級看週報詳細」 | 週報整合 |

預估 Free → Paid 轉換 +0.8% （斷食用戶比一般用戶轉換率高 3x）。

### 3.2 留存

- **每天打開頻次提升**：「現在能吃了嗎」一天看 3-5 次
- **每週留存 +15%**：對標 Zero benchmark
- **31 天留存 +8%**：連勝機制 + 進食窗自動接續

---

## 4. 實作 phasing

### Phase A — Frontend redesign（5 天）
- 新雙環 SVG（Recharts 過重，用 hand-rolled SVG）
- 階段彩色弧 + 動態填色
- 開始時間 tap-to-edit modal
- 提早結束 confirm modal
- 進食窗計時邏輯
- 7 天柱狀圖
- Home tab 斷食 widget（active 時置頂）
- Bottom tab 加「斷食」

### Phase B — Backend extend（1 天）
- FastingService 加 `eatingWindow()` snapshot
- 第一次到達某階段時 publish 對應 push
- Optional: `markStartedAt(user, datetime)` 修改既有 session 的 start time

### Phase C — Push integration（已有 templates，1 天）
- PushDispatcher 已有 6 個 templates
- 加 stage-transition 觸發（在 FastingService 的 tick / cron 跑）
- Schedule 一個 `fasting:tick-push` artisan command 每 15 分鐘掃 active sessions 看是否需要發 stage push

### Phase D — ai-service 動態旁白（已有 endpoint，1 天）
- 把 fasting_completed kind 升級成 `fasting_stage_transition`
- 階段切換時呼叫 narrative endpoint（payload: 階段、累積時間、連勝）
- Paid 用戶得到動態 1-2 句朵朵說明

### Phase E — Polish + e2e（2 天）

**合計：10 天**（Phase A 是 4-5 天 frontend 重活）

---

## 5. 不在這 spec

- ❌ 自定義模式精細調整（13-22h 滑桿）— v3
- ❌ 5:2 模式（per-day）— v3
- ❌ 社交分享圖卡 — v3
- ❌ Apple Health 寫入 fasting period — v3（卡 HealthKit plugin）
- ❌ 餐前 reminder（吃前 1 小時提醒準備）— v3

---

## 6. 風險

| 風險 | 緩解 |
|---|---|
| 雙環 SVG 在低階手機卡頓 | 動畫 fps cap 30；CSS transform 而非 SVG 重繪 |
| 用戶以為 v2 是 v1 變難 | onboarding tour（一次） + 「保持簡單模式」selector |
| 階段教育被看作冗長 | 只在「第一次進入該階段」推播 + chat，不是常駐顯示 |
| 進食窗自動接續斷食 = 太被動 | 預設 ON，Settings 可關 |
