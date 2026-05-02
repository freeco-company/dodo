# SPEC：每週朵朵 AI 報告

> 📅 起草：2026-05-02
> 🎯 目標：把一週的飲食 / 步數 / 斷食 / 體重 / 睡眠 數據濃縮成「朵朵口吻」報告，可分享圖卡
> 📊 對標：Spotify Wrapped / Strava 週報 / Yazio 週報
> 💰 商業角色：**續訂 + 有機獲客（Tier 2 優先序 #4）**
> 🔗 關聯：SPEC-photo-ai-calorie-polish、SPEC-fasting-timer、SPEC-healthkit-integration

---

## 1. 為什麼這是優先序 #4

| 維度 | 影響 |
|---|---|
| **續訂** | 看到「這週吃了什麼、走了多少」的視覺化回饋 = 感覺到 NT$290 的價值 |
| **有機獲客** | 「分享圖卡」自帶品牌曝光（Spotify Wrapped 模式）= CAC = 0 |
| **engagement** | 每週日推播「妳的本週報告出爐了 ✨」= 高開信率 |
| **可搭配既有功能** | 拍照 / 斷食 / HK 三個鉤子的 datapoint 全在這集中變現 |
| **訂閱差異化** | Free 看簡版（文字），Paid 看圖卡 + 動態 AI 點評 |

---

## 2. 報告內容（一張圖卡 + 內頁）

### 2.1 主圖卡（可分享）

```
┌─────────────────────────────────┐
│   朵朵的本週小報告 ✨              │
│   2026/04/26 - 05/02            │
│                                  │
│   🍱 吃了 21 餐 (~13,400 kcal)   │
│   🚶 走了 38,120 步              │
│   ⏱️  斷食達標 5 / 7 天          │
│   ⚖️  體重 -0.4kg                │
│                                  │
│   ─────────────────────         │
│   朵朵：「妳這週很穩定 🌱        │
│         斷食節奏抓得很好。       │
│         週末走少了一點，         │
│         下週試試多走 2,000 步？」 │
│   ─────────────────────         │
│                                  │
│   🐧 [寵物 avatar]                │
│   #潘朵拉飲食 #朵朵週報            │
└─────────────────────────────────┘
```

**設計重點**：
- 寵物 + 朵朵雙 logo（強化品牌）
- 數字大字、視覺輕量
- 朵朵點評 50-80 字，positive 為主、提一個小建議
- 底部 hashtag + App icon（分享出去自帶獲客）

### 2.2 內頁（App 內看，不分享）

| 區塊 | 內容 |
|---|---|
| 飲食總結 | 7 天 macro 平均 + 最常吃食物 top 3 + 拍照次數 |
| 運動總結 | 步數 trend 圖 + 達標日數 + 活動熱量總和 |
| 斷食總結 | 達標日數 + 最長一次 + 平均長度 |
| 體重 trend | 7 天連線圖 |
| 睡眠（Paid） | 平均時數 + 最佳 / 最差 |
| 朵朵深度點評 | 200-300 字，依數據動態生成 |
| 下週試試看 | 1-3 個具體小目標（朵朵建議） |

---

## 3. 觸發 + 通路

| 觸發 | 通路 |
|---|---|
| 每週日 20:00 推播「妳的本週報告 ✨」 | 推播 → tap → 報告頁 |
| App 內「我的」tab 永久入口「📊 我的週報」 | tap 進歷史列表 |
| 達特定里程碑（連 4 週達標、體重 -2kg） | 主動推播 + 圖卡彈出 |
| 用戶主動分享 | iOS UIActivity / Android share intent |

---

## 4. 訂閱 Gating

| 功能 | Free | Paid |
|---|---|---|
| 看當週簡版（文字） | ✅ | ✅ |
| 圖卡（可分享） | ❌ | ✅ |
| 朵朵動態 AI 點評（200-300 字） | ❌（固定模板） | ✅ |
| 歷史報告（過去 4 週+） | 4 週 | 無限 |
| 月報 | ❌ | 年付 / VIP |
| 客製寵物入鏡圖卡 | ❌ | ✅ |

---

## 5. 技術變更

### 5.1 Backend

| 項目 | 改動 |
|---|---|
| 新 service `WeeklyReportService` | aggregate 飲食/步數/斷食/體重/睡眠 + AI 點評 |
| 新 table `weekly_reports` | id, user_uuid, week_start, week_end, payload_json, generated_at, shared_count |
| 新 endpoints | `GET /api/reports/weekly/current` / `GET /api/reports/weekly/{week}` / `POST /api/reports/weekly/{id}/shared`（埋分享事件） |
| 排程 | 每週日 19:00 跑 `reports:generate-weekly` 預生成（避免 user open 時等 AI） |
| Push template | `weekly_report_ready` |
| Achievement | 「連 4 週看週報」「分享 1 次週報」 |

### 5.2 ai-service

| 項目 | 改動 |
|---|---|
| 新 endpoint `POST /v1/reports/weekly-narrative` | input：aggregated metrics；output：朵朵點評文（依 tier 模板 / 動態） |
| Prompt | 朵朵 voice + 健康行為教練 + 食安合規 sanitizer pre-check |
| Cost guard | per-user 一週一次 cap（不能濫用） |

### 5.3 Frontend

| 項目 | 改動 |
|---|---|
| 「我的」tab 加「📊 週報」 | list view |
| 報告頁 | 圖卡（Paid）+ 內頁分區 |
| 圖卡 export | `html-to-image` lib，輸出 1080×1920 PNG |
| 分享 sheet | Capacitor share plugin |
| 歷史列表 | 過去 N 週縮圖 grid |

---

## 6. 驗收條件

### Backend
- [ ] 排程跑完所有 active user 預生成
- [ ] 數據聚合正確（含 tier-gated 欄位）
- [ ] AI 點評 fail-soft（Anthropic 掛 → fallback 模板）
- [ ] 分享計數正確
- [ ] Pest 全綠 + phpstan clean

### Frontend
- [ ] 圖卡 render < 2s
- [ ] 分享 PNG 1080×1920 不糊
- [ ] 推播 deep-link 進當週報告
- [ ] e2e smoke：mock 一週數據 → 進報告頁 → 分享

### 商業
- [ ] 推播開信率 > 40%
- [ ] 分享率 > 5% Paid 用戶
- [ ] 圖卡曝光帶回新下載量（UTM tracking）

---

## 7. 不做（Out of Scope）

- ❌ 月報 / 年報（年付 / VIP 後續 spec）
- ❌ 同儕比較（toxicity）
- ❌ 即時 AI（一週一次預生成就夠）
- ❌ 自選週起始日（一律週日 = 對齊全球曆法）

---

## 8. 預估工時

| 區塊 | 工時 |
|---|---|
| Backend service + endpoints + 排程 + tests | 3 天 |
| ai-service narrative endpoint + prompt | 2 天 |
| Frontend 圖卡 + 內頁 + 分享 + list | 4 天 |
| e2e + 推播配置 | 1 天 |
| **合計** | **10 天** |

---

## 9. 風險

| 風險 | 緩解 |
|---|---|
| AI 點評 hallucinate 不準 | sanitizer pre + few-shot + temperature 低 + fallback 模板 |
| 分享出去格式跑掉 | 固定 1080×1920 + 各平台預覽測試 |
| 推播 timing 國際時區 | 用 user timezone（HK/HC sync 已知） |
| 數據空（新用戶） | 報告頁 graceful：「累積 7 天再來看」+ checklist |
