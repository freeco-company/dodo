# SPEC：HealthKit / Health Connect 整合

> 📅 起草：2026-05-02
> 🎯 目標：把步數 / 體重 / 睡眠等行為數據自動接進 meal，不靠用戶手動輸入
> 📊 對標：Yazio / Lifesum / Lose It！的 system-level 整合
> 💰 商業角色：**meal「健康行為 OS」定位的基礎建設（Tier 1 優先序 #3）**
> 🔗 關聯：[meal CLAUDE.md「健康行為 OS」](../../CLAUDE.md)、SPEC-photo-ai-calorie-polish、SPEC-fasting-timer

---

## 1. 為什麼是優先序 #3

| 維度 | 影響 |
|---|---|
| **戰略** | meal 從「飲食」擴大為「健康行為 OS」的基礎；沒有步數整合 = 還是飲食 App |
| **體驗** | 步數 / 體重 / 睡眠手動輸入 = 摩擦極高 = 沒人記；自動同步 = 資料才會持續累積 |
| **數據** | 朵朵 cross-insight 的燃料（「妳今天吃 1800 kcal 但只走 3000 步」需要兩端數據） |
| **訂閱** | 進階 cross-insight + 週報深度 = 自然 paywall |
| **獲客** | App Store「Apple Health 整合」是搜尋關鍵字，自然流量加分 |

**關鍵洞察**：用戶心智裡「健康 App」的 baseline = 接 Apple Health。沒接 = 半成品。

---

## 2. 數據型別（v1）

| 型別 | 來源 | 用途 | tier |
|---|---|---|---|
| **步數**（每日） | HealthKit / Health Connect | 主畫面 + 朵朵 cross-insight + quest | Free |
| **活動熱量燃燒** | HK / HC | 與飲食記錄交叉 | Free |
| **體重** | HK / HC（user 寫入也讀） | 進度追蹤 + 圖表 | Free |
| **睡眠時數** | HK / HC | 朵朵點評 + 週報 | Paid |
| **心率（靜息）** | HK / HC | 週報深度（不主動顯示） | Paid |
| **運動 sessions** | HK / HC | quest「今天有動」自動觸發 | Free |

**寫入回 HK / HC**：
- 飲食記錄熱量 → `Dietary Energy`（HealthKit `HKQuantityTypeIdentifierDietaryEnergyConsumed`）
- 拍照辨識 macro → `Carbohydrates / Protein / Fat`
- 斷食 sessions → 不寫回（HK 沒對應 type）

**永遠不要求**：
- ❌ ECG / 血氧 / 血糖（醫療等級，責任太重）
- ❌ 經期（meal 不是月曆 App，這是潘朵拉月曆的事）

---

## 3. UX Flow

### 3.1 首次連接（onboarding 第 N 步）

```
[onboarding flow]
   ↓
[健康資料連接]
  「想讓朵朵了解妳更多嗎？
   連接 Apple 健康 / Google 健康，
   步數、體重會自動同步 🌱」
  
  [連接 Apple 健康]
  [稍後再說]  ← 不強迫，可在「我的 → 設定」之後接
```

→ 點擊「連接」→ 跳出系統權限對話框（細粒度，每個 type 可獨立同意）。
→ 拒絕全部 → App 仍可用，回主畫面，「我的」深層仍可隨時連回。
→ 同意部分 → 即時 import 過去 30 天數據（背景 task）+ 訂閱即時更新。

### 3.2 主畫面整合

```
┌─────────────────────────────┐
│  今天                          │
│                              │
│  🍱 已記 3 餐  約 1620 kcal    │
│  🚶 5,234 步                   │  ← HK 自動同步
│  ⚖️ 53.2kg（昨天）             │
│  😴 7h 12m（昨晚）              │  ← Paid 才顯示
│                              │
│  朵朵：「今天吃得剛好 ✨        │  ← 自動 cross-insight
│         走路也達標 6000 步了」  │
└─────────────────────────────┘
```

### 3.3 設定頁（細粒度控制）

```
[我的 → 健康資料]
  ☑ 步數
  ☑ 體重
  ☑ 活動熱量
  ☐ 睡眠（升級解鎖）
  ☐ 心率（升級解鎖）
  
  [斷開連接]  ← 隨時可關
```

---

## 4. 平台技術實作

### 4.1 iOS — HealthKit

| 項目 | 說明 |
|---|---|
| Capacitor plugin | `@perfood/capacitor-healthkit` 或自寫 plugin（已有開源 ref） |
| Info.plist | `NSHealthShareUsageDescription` + `NSHealthUpdateUsageDescription` 中文文案 |
| 權限請求 | 細粒度，每個 type 獨立 |
| 背景同步 | `HKObserverQuery` + `enableBackgroundDelivery`（步數 / 體重） |
| 寫入 | 飲食記錄 → `HKQuantitySample`（DietaryEnergyConsumed） |
| App Store review note | 寫清楚「為何需要 HealthKit、不會用於行銷」 |

### 4.2 Android — Health Connect

| 項目 | 說明 |
|---|---|
| Capacitor plugin | 自寫（Health Connect 較新，現成 plugin 少） |
| AndroidManifest | Health Connect permissions + intent filter |
| 最低版本 | Android 14+（Health Connect baseline）；舊版 fallback Google Fit |
| 背景同步 | WorkManager + Health Connect Observer |
| 寫入 | 同上，類別對應 |

### 4.3 Backend

| 項目 | 改動 |
|---|---|
| 新 table `health_metrics` | id, user_uuid, type, value, unit, recorded_at, source, raw_payload |
| 新 service `HealthMetricsService` | sync / latest / history / aggregate methods |
| 新 endpoints | `POST /api/health/sync`（batch upload from device） / `GET /api/health/today` / `GET /api/health/history` |
| 排程 | 不從 server 主動拉（PII 風險 + 沒授權），只接收 device 上傳 |
| Quest engine | 「今日 6000 步」「連 7 天動」等 quest 觸發 |
| Gamification publish | `meal.steps_goal_achieved` / `meal.weight_logged` |

### 4.4 Frontend

| 項目 | 改動 |
|---|---|
| Onboarding 新步驟 | 健康資料連接 + 教育文案 |
| 主畫面 widget | 步數 / 體重 / 睡眠 ring + 朵朵 cross-insight |
| 設定頁 | 細粒度開關 |
| 圖表 | 7 / 30 / 90 天 weight + steps trend |
| 拍照確認後 | 自動寫回 HK Dietary Energy |

---

## 5. 隱私與合規（重要）

| 紅線 | 緩解 |
|---|---|
| **PII 不存在 meal local DB** | 健康資料寫進 `health_metrics`（仍有風險）→ 加 retention policy（90 天 raw + 永久 aggregate）+ user 可刪除 |
| **不用於廣告 / 行銷** | 明文寫進隱私權政策 + App Store note |
| **Apple App Review 嚴審** | Info.plist usage description 寫清楚（中英文）；不要要求不用的 type |
| **Health Connect 嚴審** | Google Play data safety form + permissions justification |
| **GDPR / 個資法 right to delete** | AccountDeletionService 同時刪 health_metrics |
| **HK 寫回需用戶明確同意** | 第一次寫入前再次確認 |

---

## 6. 訂閱 Gating

| 功能 | Free | Paid |
|---|---|---|
| 步數 / 體重 / 活動熱量 同步 | ✅ | ✅ |
| 主畫面 widget | ✅ | ✅ |
| 7 天歷史 | ✅ | ✅ |
| 30+ 天歷史 / 圖表 | ❌ | ✅ |
| 睡眠 / 心率 同步 | ❌ | ✅ |
| 朵朵 cross-insight 動態點評 | 模板 | 動態 AI |
| 與飲食週報整合 | ❌ | ✅ |

---

## 7. 驗收條件

### Backend
- [ ] sync endpoint 接受 batch metrics + dedup
- [ ] history paginated + tier-gated
- [ ] AccountDeletion 連動刪 health_metrics
- [ ] retention 排程跑 90 天 raw 清除
- [ ] Pest 全綠 + phpstan clean

### iOS
- [ ] HealthKit 權限請求文案清楚
- [ ] 6 種 type 細粒度授權
- [ ] 背景同步啟動 + 收 HKObserverQuery 觸發
- [ ] 寫回 DietaryEnergy 正確
- [ ] App Store TestFlight pass review

### Android
- [ ] Health Connect 權限正確
- [ ] 14+ baseline + 舊版 graceful 提示
- [ ] 背景 WorkManager 同步

### 整體
- [ ] e2e smoke：onboarding → 模擬授權 → 假資料 sync → 主畫面顯示步數
- [ ] 隱私權政策更新 + 提交 App Store / Play Store review

---

## 8. 不做（Out of Scope）

- ❌ Apple Watch app（受眾窄、開發成本高，留到 v2）
- ❌ 心電圖 / 血氧 / 血糖（醫療等級）
- ❌ 經期 / 週期（潘朵拉月曆的事）
- ❌ Strava / Fitbit / Garmin 第三方整合（用戶通常已接 HK / HC，間接拿到資料）
- ❌ 即時心率即時顯示（電池殺手）

---

## 9. 預估工時

| 區塊 | 工時 |
|---|---|
| iOS HealthKit plugin + 權限 + 背景同步 + 寫回 | 5 天 |
| Android Health Connect plugin + 權限 + 背景 | 5 天 |
| Backend service + endpoints + retention | 3 天 |
| Frontend onboarding + widget + 設定頁 + 圖表 | 4 天 |
| 隱私文件 + App Store / Play Store review note | 1 天 |
| e2e + smoke + TestFlight 驗 | 2 天 |
| **合計** | **20 天**（iOS / Android 平行可省 3-4 天） |

---

## 10. 風險

| 風險 | 緩解 |
|---|---|
| App Store review 拒（HK usage 不清） | usage description 寫詳細 + 截圖示範用途 |
| Android Health Connect 普及度未達標 | Android 14+ baseline + 舊版「升級系統」提示 |
| 背景同步耗電 | 只訂 step / weight 高頻 type，sleep 用 daily snapshot |
| 用戶拒絕授權 = 半殘 | 仍可手動輸入 + onboarding 強調可選 |
| 隱私權政策落漆被罰 | 法務 review + 明文不用於行銷 + retention 機制 |

---

## 11. 後續鉤子（不在本 spec）

- **每週 AI 報告**（spec 04）— 健康資料是週報核心素材
- **進度照相簿**（spec 05）— 體重 + 進度照同畫面對照
- **Cards 健康知識卡**（既有功能擴充）— 用步數 / 睡眠數據 unlock 對應卡片
