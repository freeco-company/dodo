# SPEC：斷食 timer（Intermittent Fasting Timer）

> 📅 起草：2026-05-02
> 🎯 目標：把「間歇性斷食」做成 meal 的留存 + 訂閱雙引擎鉤子
> 📊 對標：Zero（被 BodyGroup 收購的斷食 App 龍頭）/ Yazio / Fastic
> 💰 商業角色：**留存 + 訂閱 paywall sweet spot（Tier 1 優先序 #2）**
> 🔗 關聯：[meal CLAUDE.md「6 鉤子優先序」](../../CLAUDE.md)、SPEC-photo-ai-calorie-polish

---

## 1. 為什麼是優先序 #2

| 維度 | 影響 |
|---|---|
| **留存** | 「現在能吃了嗎」是每天打開 App 的硬理由（被動 engagement，不需主動操作） |
| **訂閱** | 進階模式（自選比例、階段提示、歷史統計）= 自然 paywall sweet spot |
| **差異化** | 朵朵 NPC + 推播語氣（「再 30 分鐘可以吃了 🌟」）競品做不出來 |
| **與既有鉤子協同** | 連勝（streak）+ shield + outfit 直接套用，不重蓋系統 |
| **TA 契合** | 25-40 歲台灣女性減脂 = 斷食目標族群 100% 重疊 |
| **開發成本** | 純後端時間記錄 + 前端 UI + 推播；無 AI 成本 |

---

## 2. 模式支援（v1）

| 模式 | 斷食 / 進食 比例 | tier |
|---|---|---|
| **16:8** | 16h / 8h（最大宗） | Free |
| **14:10** | 14h / 10h（新手友善） | Free |
| **18:6** | 18h / 6h | Paid |
| **OMAD（20:4）** | 20h / 4h | Paid |
| **5:2**（每週 5 天正常 + 2 天輕食） | 不同邏輯（per-day） | Paid |
| **自定義** | 任意比例 13-22h | Paid |

---

## 3. UX Flow

### 3.1 啟動 / 首次設定

```
[底部斷食 tab] tap
   ↓
[首次設定]
  「想試試間歇性斷食嗎？
   挑一個適合的開始 🌱」
  
  [16:8（推薦新手）]
  [14:10（更輕鬆）]
  [先看看再說]  ← 不強迫
```

**首次完成設定 → 朵朵 onboarding 一句話 + 1 個試做任務（quest 整合）**。

### 3.2 主畫面（最常看到的畫面）

```
┌─────────────────────────────┐
│         斷食 16:8             │  ← 模式 chip 可 tap 切換
│                              │
│           ╭──╮               │
│          ╱    ╲              │
│         │ 12h  │             │  ← 大字 elapsed
│         │ 24m  │             │
│         │      │             │
│          ╲    ╱              │
│           ╰──╯               │  ← 圓形進度（療癒色 #C9D3BE 漸層）
│                              │
│  ●●●●●●○○                    │  ← 階段燈（脂肪燃燒 / 自噬 etc）
│                              │
│  最後一口：昨晚 20:30          │
│  可以吃了：今天 12:30 (剩 3h36m)│
│                              │
│  [📝 開始進食]                 │
└─────────────────────────────┘
```

### 3.3 階段燈（科學分段，朵朵口吻）

| 進度 | 狀態 | 朵朵提示（推播 + UI） |
|---|---|---|
| 0-4h | 進食消化 | （無，不打擾） |
| 4-8h | 進入空腹 | 「身體開始平靜下來 🌱」 |
| 8-12h | 肝醣轉換 | 「能量切換中，撐住 ✨」 |
| 12-16h | 脂肪燃燒 | 「進入脂肪燃燒區 🔥」 |
| 16-20h | 自噬作用 | 「細胞清潔模式 🌟」（限 18:6 / OMAD） |
| 20h+ | 深度斷食 | 「妳真的很有毅力 💪 記得補水喔」 |

**注意**：階段燈只用「平易科學描述」，不寫療效宣稱（「燃燒脂肪」OK，「治療糖尿病」NG，合規 sanitizer 過濾）。

### 3.4 結束斷食（進食期啟動）

```
[開始進食] tap
   ↓
朵朵：「準備好好享用一餐 🍱
       想先記錄今天打算吃什麼嗎？」
   
   [拍照記錄]  ← 直接接 SPEC-01 拍照 AI
   [稍後再說]
```

→ 與拍照鉤子**雙向綁定**：斷食結束 → 自然導向拍照 → 拍完回斷食畫面顯示 8h 倒數開始。

---

## 4. 推播設計（朵朵 voice，超低打擾）

| 觸發 | 文案 | tier |
|---|---|---|
| 斷食 12h | 「12 小時了 🌱 撐得很好」 | Free（一次/日） |
| 斷食 16h（達標） | 「達成 16 小時 ✨ 妳今天很棒」 | Free |
| 進食期前 30min | 「再 30 分鐘可以吃了 🌟 想想要吃什麼？」 | Paid |
| 進食期結束前 30min | 「進食期還剩 30 分鐘，記得收尾」 | Paid |
| 連續 7 天達標 | 「連續 7 天 16:8 達成 🏆 解鎖新成就」 | Free |
| 連勝中斷風險 | 「今天還沒開始斷食喔 🌱 要試試嗎？」 | Free（只在當天 22:00 後一次） |

**全推播都尊重 quiet hours**（22:00-08:00 只發 streak warning，其他 mute）。

---

## 5. 訂閱 Gating

| 功能 | Free | Paid (NT$290+) |
|---|---|---|
| 16:8 / 14:10 | ✅ | ✅ |
| 18:6 / OMAD / 5:2 / 自定義 | ❌ | ✅ |
| 階段燈進階提示（自噬以上） | ❌ | ✅ |
| 歷史統計（週/月/年） | 7 天 | 無限 |
| 朵朵動態點評（不只模板） | ❌ | ✅ |
| 推播 fine-grained 控制 | ❌ | ✅ |
| 與飲食記錄交叉分析 | ❌ | ✅ |

**Paywall trigger**（不打擾路線）：
- 用戶嘗試切到 18:6 → sheet「想升級看看更多模式嗎？」+ continue 按鈕（仍可用 16:8）
- 達 14 天 16:8 連勝 → 朵朵：「妳很適合斷食 ✨ 想試 18:6 看看？」+ paywall

---

## 6. 技術變更

### 6.1 Backend

| 項目 | 改動 |
|---|---|
| 新 table `fasting_sessions` | id, user_uuid, mode, started_at, ended_at, target_duration_minutes, completed, source_app |
| 新 service `FastingService` | start / end / current / history methods |
| 新 endpoints | `POST /api/fasting/start`, `POST /api/fasting/end`, `GET /api/fasting/current`, `GET /api/fasting/history` |
| Push template 加 `fasting.*` | 6 條（見 §4） |
| Quest engine 整合 | 「連續 7 天 16:8」「首次達 16h」等 quest |
| Achievement | 「斷食初心」「斷食 7 天」「斷食 30 天」（共用 ADR-009 publisher） |
| EntitlementsService | 新增 `fasting_advanced_modes`、`fasting_history_days` |
| Gamification publish | `meal.fasting_completed` event（XP +10） |
| Streak service | 與既有 daily streak 並列（不混算，避免覆蓋） |

### 6.2 Frontend

| 項目 | 改動 |
|---|---|
| 新 tab `fasting` | bottom nav 第 3 格（與飲食 / 步數 / 我的並列） |
| 圓形進度 SVG | 套 design-svg package 風格 |
| 階段燈 component | 8 段，icon + label |
| 推播 deep-link | tap 推播 → 開斷食畫面 |
| 與拍照鉤子串接 | 結束斷食 → 拍照 sheet（見 §3.4） |
| 模式切換 sheet | tier-gated UI（鎖頭 icon） |

### 6.3 ai-service

無新功能（朵朵動態點評共用既有 chat client，傳斷食 context 進 prompt）。

---

## 7. 驗收條件

### Backend
- [ ] start session（已有未結束 → 422）
- [ ] end session（無 active → 404）
- [ ] current 返當前狀態 + 進度 %
- [ ] history paginated + tier-gated（free 7 天）
- [ ] 進階模式 free tier → 402
- [ ] streak 連續達標累計正確
- [ ] gamification publish event
- [ ] Pest 全綠 + phpstan clean

### Frontend
- [ ] 圓形進度動畫流暢（60fps）
- [ ] 階段燈隨時間更新
- [ ] 推播 deep-link 進畫面
- [ ] tier-gated 模式正確擋
- [ ] e2e smoke：開始 → 等 mock 進度 → 結束 → 接拍照

### 商業
- [ ] 14 天試用後付費轉換率追蹤
- [ ] 推播訂閱率 > 60%（用戶不關掉斷食推播）

---

## 8. 不做（Out of Scope）

- ❌ 飲水 timer（另獨立功能或併入水分追蹤）
- ❌ 真人 coach（VIP tier 後續）
- ❌ 社群打卡 / leaderboard（toxicity）
- ❌ HRV / 壓力與斷食關聯（要 Watch）
- ❌ 醫療建議（斷食適合誰、禁忌）— 只放免責聲明 + 「諮詢醫療團隊」連結

---

## 9. 合規紅線

- ❌ 「斷食減重 X 公斤」「斷食治糖尿病」「斷食抗癌」等療效暗示
- ❌ 鼓勵超過 24h 的長斷食（風險高、責任大）
- ✅ 朵朵語氣鼓勵「適合自己的節奏」+ 補水提醒
- ✅ 設定頁顯眼放免責：「孕哺 / 慢性病 / 飲食障礙 請先諮詢醫師」
- 共用 sanitizer（packages/pandora-shared）過濾所有 user-facing 文案

---

## 10. 預估工時

| 區塊 | 工時 |
|---|---|
| Backend（service + endpoints + migrations + tests） | 3 天 |
| Frontend（圓形進度 + 階段燈 + tab + 推播 deep-link） | 4 天 |
| Quest / Achievement / Gamification publish | 1 天 |
| 推播文案 + 朵朵動態點評整合 | 1 天 |
| e2e + smoke | 1 天 |
| **合計** | **10 天** |

---

## 11. 風險

| 風險 | 緩解 |
|---|---|
| 用戶忘記「開始」/「結束」 | 自動偵測（拍照 = 進食、無拍照 8h+ 推播提醒） |
| 推播太多被關 | quiet hours + tier-gated + 用戶可全關 |
| 合規 / 醫療責任 | 免責 + sanitizer + 不鼓勵 24h+ |
| 14:10 變垃圾選項（門檻太低） | 14:10 也算 streak、但成就降階 |
