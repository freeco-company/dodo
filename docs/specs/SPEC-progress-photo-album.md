# SPEC：進度照相簿（Body Progress Photos）

> 📅 起草：2026-05-02
> 🎯 目標：讓用戶定期記錄自己的身材進度照，朵朵點評對比鼓勵
> 📊 對標：Ai Body / Body Plus / MacroFactor 進度照功能
> 💰 商業角色：**VIP tier 差異化、情緒鉤子最強（Tier 2 優先序 #5）**
> 🔗 關聯：SPEC-photo-ai-calorie-polish、SPEC-weekly-ai-report

---

## 1. 為什麼是優先序 #5

| 維度 | 影響 |
|---|---|
| **情緒鉤子** | Before/After 對比是減脂族最強情緒驅動，續訂率拉高 |
| **VIP 差異化** | 純 Paid 功能，提高 ARPU（NT$2,400 年付的核心理由之一） |
| **與週報協同** | 進度照入鏡週報圖卡（VIP 限定）= 高分享動機 |
| **體重 trend 補完** | 數字 + 視覺，比純體重圖表更有感 |
| **隱私敏感度高** | 必須做對 — 一旦外流 = App 死亡 |

---

## 2. 核心 UX

### 2.1 拍照流程

```
[「我的」→ 進度照] tap
   ↓
首次：[onboarding 三步教學]
  1. 「找一個固定地點 + 燈光 + 角度」（朵朵 tip）
  2. 「建議內衣 / 緊身衣，看出體態變化」
  3. 「拍前面 / 側面 / 背面 各一張」
   
[拍照 sheet]
  - 三格 placeholder（前 / 側 / 背）
  - 每格 tap → 開相機 + 半透明 ghost overlay（上次拍的位置對齊）
  - 拍完 → 即時 face blur（隱私保護）
   ↓
[標籤]
  - 體重（自動帶入最近一筆）
  - 心情（emoji）
  - 備註（選填）
   ↓
[儲存到加密相簿]
```

### 2.2 對比模式

```
[相簿 list view]
  按月份 grid，每張縮圖含日期 + 體重
   
[時間軸 view]
  ←─────────●─────●─────●─────●─────→
  1/1      2/1   3/1   4/1   5/1
  60kg     58kg  57kg  56.5  56kg
   
[對比 sheet]
  選 2 張 → 並排對比 → 朵朵點評（VIP）
  「妳從 1/1 到 5/1 瘦了 4kg ✨ 線條更明顯」
```

### 2.3 安全感設計（隱私）

| 項目 | 實作 |
|---|---|
| 進入相簿前 | App lock（Face ID / 密碼 / 生物辨識） |
| 圖片儲存 | 端到端加密（device key derived），不上傳原圖 |
| 雲端備份 | 只 metadata（體重 / 日期），圖片留 device |
| 自動 face blur | 拍完即時偵測 + 模糊（可手動調整） |
| 截圖檢測 | iOS 截圖 listener → 朵朵提醒「截圖留意隱私 🌱」 |
| 卸載 App | 圖片同步刪除（不留在 device） |
| 帳號刪除 | 全清 |

---

## 3. 訂閱 Gating

| 功能 | Free | Monthly | Yearly | VIP |
|---|---|---|---|---|
| 拍照 + 儲存 | ❌ | ❌ | ✅ | ✅ |
| 三角度（前 / 側 / 背） | - | - | ✅ | ✅ |
| 對比模式 | - | - | ✅ | ✅ |
| 朵朵動態點評 | - | - | 模板 | 動態 AI |
| 入鏡週報圖卡 | - | - | ❌ | ✅ |
| Ghost overlay 對齊 | - | - | ✅ | ✅ |
| 自動 face blur | - | - | ✅ | ✅ |
| 截圖檢測提醒 | - | - | ✅ | ✅ |

→ **Yearly tier 起才解鎖**（不是 Monthly）— 這是 NT$2,400 年付主賣點之一。

---

## 4. 技術變更

### 4.1 端側（device-only）

| 項目 | 實作 |
|---|---|
| 圖片加密儲存 | iOS Keychain key + AES-256；Android EncryptedFile |
| Face blur | iOS Vision framework 偵測臉 + Core Image 模糊；Android ML Kit |
| Ghost overlay | 上一張 50% opacity 疊在相機 preview 上 |
| App lock | iOS LocalAuthentication；Android BiometricPrompt |
| 截圖 listener | iOS `UIApplication.userDidTakeScreenshotNotification`；Android FLAG_SECURE 警告 |

### 4.2 Backend

| 項目 | 改動 |
|---|---|
| 新 table `progress_snapshots` | id, user_uuid, taken_at, weight_g, mood, notes, **photo_ref（device-local-id，非 URL）** |
| **不存圖片本體** | 只存 metadata；圖片留 device 端 |
| 新 endpoints | `POST /api/progress/snapshot`（only metadata）/ `GET /api/progress/timeline` |
| Account deletion | 連動 user 端 broadcast「請清理 progress photos」+ server 刪 metadata |

### 4.3 ai-service

| 項目 | 改動 |
|---|---|
| **不接收照片本體** | 朵朵點評只看 metadata（體重 trend）+ 日期 |
| 新 endpoint `POST /v1/reports/progress-comment` | input：時間軸 metadata；output：朵朵點評文（VIP）|

→ **嚴格紅線：朵朵 AI 永遠不看身材照片本身**。避免：
- AI hallucinate 不當點評（「妳變胖了」之類）
- 圖片離開 device = 隱私風險爆炸
- 訓練資料外洩風險

### 4.4 Frontend

| 項目 | 改動 |
|---|---|
| 「我的」tab 加「📷 進度照」 | tier-gated entry |
| 三角度拍照 sheet | ghost overlay + face blur preview |
| 相簿 grid + timeline | 縮圖加密 / 解密 |
| 對比 sheet | 選 2 張並排 + 朵朵點評 |
| App lock 設定 | 開關 + 生物辨識選項 |
| 入鏡週報整合（VIP） | 用戶選一張入鏡 |

---

## 5. 驗收條件

### 端側（最關鍵）
- [ ] 圖片端到端加密 verify（拔網路仍可看；改密碼後解不開）
- [ ] face blur 自動 + 可手動覆蓋
- [ ] 截圖時 iOS 跳警告
- [ ] App uninstall → 圖片立即清
- [ ] App lock 解鎖前不顯示縮圖
- [ ] 三角度 ghost overlay 對齊正確

### Backend
- [ ] metadata 寫入正確
- [ ] AccountDeletion 連動清 metadata
- [ ] 跨 tenant 拒絕（紅線）
- [ ] Pest 全綠 + phpstan clean

### 商業
- [ ] Yearly tier 升級率追蹤（進度照 paywall trigger conversion）
- [ ] 入鏡週報的 VIP user 留存 > 普通 VIP

---

## 6. 不做（Out of Scope）

- ❌ AI 體型分析（風險高、責任大、易給負面評價）
- ❌ BMI / 體脂預估（醫療等級）
- ❌ 社群分享（截圖外流就完蛋，除非用戶主動 export）
- ❌ 雲端同步圖片（永遠 device-only）

---

## 7. 預估工時

| 區塊 | 工時 |
|---|---|
| iOS 加密 + face blur + ghost + lock | 5 天 |
| Android 加密 + face blur + ghost + lock | 5 天 |
| Backend metadata + endpoints | 2 天 |
| ai-service narrative endpoint | 1 天 |
| Frontend UI + 對比 + tier gating | 3 天 |
| 隱私審查 + e2e | 2 天 |
| **合計** | **18 天**（iOS / Android 平行可省 3-4） |

---

## 8. 風險

| 風險 | 緩解 |
|---|---|
| 圖片外流 = App 死亡 | 端到端加密 + device-only + 截圖警告 + face blur |
| AI 點評不當（「妳變胖了」） | AI 不看圖、只看體重 trend；prompt 嚴格 positive |
| App Store review 拒（隱私） | 隱私權政策詳細 + 不上傳圖片 |
| 用戶忘記密碼 → 圖片 lock 死 | 提示備份體重 metadata；圖片明確「device-only 無雲端」 |
| Yearly tier 升級率不如預期 | A/B 測：進度照入 Monthly tier 看 ARPU 影響 |
