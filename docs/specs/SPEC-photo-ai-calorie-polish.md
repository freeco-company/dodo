# SPEC：拍照 AI 估熱量打磨（Photo AI Calorie Polish）

> 📅 起草：2026-05-02
> 🎯 目標：把現有 ai-service 的 vision 食物辨識，從「能用」打磨成「下載理由」級鉤子
> 📊 對標：Cal AI / SnapCalorie / Stupid Simple AI Calorie Tracker（2024-2026 TikTok viral 級）
> 💰 商業角色：**獲客 + 訂閱主鉤子（Tier 1 優先）**
> 🔗 關聯：[meal CLAUDE.md「6 鉤子優先序」](../../CLAUDE.md)、ADR-002 Phase B

---

## 1. 為什麼這是優先序 #1

| 維度 | 現況 | 目標 |
|---|---|---|
| 獲客 | 用戶從 App Store 下載理由不明確 | 「拍一下就秒算熱量」= 一句話下載理由（同 Cal AI） |
| 留存 | 食物記錄需手打 / 搜尋資料庫 → 摩擦高 | 拍照 3 秒完事，每日多次回 App |
| 訂閱 | 食物記錄 = 免費功能，沒進 paywall | 拍照 AI 量超過免費 quota → 自然 paywall trigger |
| 成本 | AI 成本 < NT$80/活躍 guard rail | 維持，靠 quota tier + 圖片 downscale + cache |

**戰略邏輯**：用戶一旦把「拍照記飲食」內化成習慣（每天 3-5 次），App 就有了 daily active hook。Cal AI 在沒有遊戲化、沒有 NPC、沒有寵物的情況下，純靠拍照鉤子做到 TikTok viral + 訂閱率 8%+。meal 已有 outfit / quest / 朵朵 NPC，補上拍照鉤子等於把獲客 funnel 補完。

---

## 2. 現況盤點（2026-05-02）

### 2.1 已有

- **ai-service**：FastAPI + Anthropic Claude vision (`claude-3-5-sonnet-latest`)
  - `POST /v1/vision/recognize` — multipart 圖片上傳
  - `POST /v1/vision/recognize-text` — 純文字 fallback
  - confidence threshold 0.85
- **backend AiCostGuardService** — 月成本 cap 控管
- **frontend** — 既有飲食記錄畫面（待 audit 對接點）
- **Entitlements** — tier 已 gate 部分功能

### 2.2 Gap（vs Cal AI 級體驗）

| Gap | 影響 | 修法方向 |
|---|---|---|
| **拍照 → 結果延遲** 不明確（無 perceived speed 優化） | 用戶覺得慢就不再用 | shimmer / progressive disclosure（先出輪廓再出熱量） |
| **辨識結果 UI** 沒驗 | 用戶看不懂吃了多少 | 大字熱量 + macro ring（碳水/蛋白/脂肪三色環） |
| **多份量辨識** 未確認支援度 | 一張圖多道菜會錯 | prompt engineering + 後製拆分 |
| **「不是這個」修正流程** 未明 | 辨錯就放棄 | tap 修正 → 候選清單 → fallback 手打 |
| **歷史相簿** | 看不到「上次吃這個」 | 食物相簿 + 重複記錄 1-tap |
| **免費 quota 設計** 不清楚 | 燒錢 / 壓抑體驗 | 3 次/天 免費、付費無限（spec §5） |
| **離線 fallback** 沒有 | 沒網路 = 無感 | 圖片快取 + 重連自動上傳 |
| **朵朵點評** 與拍照分離 | 失去 NPC 差異化 | 拍完出朵朵一句點評（「妳今天碳水偏高了 🌱」） |

---

## 3. UX Flow（目標）

```
[底部 + 按鈕]
   ↓ tap
[全螢幕相機 / 相簿]  ← 不要選「相機 vs 相簿」二級彈窗，預設相機
   ↓ shutter
[即時 shimmer 0.3s 假進度（給安心感）]
   ↓ upload (compressed)
[結果頁 0.8-2s 內]
   ┌─────────────────────────────┐
   │  🍱 雞腿便當                  │  ← 大字食物名（confidence > 0.85）
   │  約 720 kcal                 │  ← 大字熱量
   │                              │
   │   ●━━ 碳水 80g (45%)          │  ← Macro ring
   │   ●━━ 蛋白 35g (20%)          │
   │   ●━━ 脂肪 28g (35%)          │
   │                              │
   │  朵朵：「分量充足 ✨ 記得多走走」 │  ← NPC 點評（共用 chat client）
   │                              │
   │  [✏️ 不是這個] [✓ 確認記錄]    │
   └─────────────────────────────┘

「不是這個」→ 候選 top-3 清單 → fallback 手打名稱 + 估算
「確認」→ 寫入 daily log + 朵朵 XP/quest 觸發
```

**關鍵互動細節**：
- shimmer 動畫不超過 0.3s（避免假慢感）
- 結果頁第一秒只出食物名 + 熱量，0.5s 後再 fade-in macro ring + 朵朵點評（progressive disclosure 降低資訊密度）
- 「確認」按鈕預設 highlighted，「不是這個」次要 — 90% 的人直接確認

---

## 4. 技術變更

### 4.1 ai-service（FastAPI）

| 項目 | 改動 |
|---|---|
| `POST /v1/vision/recognize` request | 加可選 `meal_context`（早午晚餐 / 點心）幫助 prompt |
| response schema | 加 `macro_grams: {carb, protein, fat}` + `dodo_comment: str`（朵朵點評，可空） |
| prompt | 加「list multi-dish if present, return per-dish kcal + macros」 |
| 圖片預處理 | 上傳前 resize 到 max 1024px 長邊 + JPEG 85 quality（控成本） |
| confidence < 0.85 fallback | 回 top-3 候選 + flag `requires_user_confirmation: true` |
| 朵朵點評 | 共用 chat client；prompt 用 `narrative-designer.md` 朵朵 voice，限 25 字內 |

### 4.2 Laravel backend

| 項目 | 改動 |
|---|---|
| `POST /api/meals/recognize` proxy | 透傳 user uuid + tier；對接 ai-service |
| `EntitlementsService` | 新增 `photo_ai_quota_daily`（free=3, paid=∞） |
| `AiCostGuardService` | 拍照 path 算成本（vision API 比 chat 貴 ~3×） |
| `MealLog` model | 加 `recognized_via: enum(photo, text, manual)` + `confidence` + `image_path` |
| 朵朵 XP | 拍照確認記錄 +5 XP（一次/日 cap） |

### 4.3 frontend

| 項目 | 改動 |
|---|---|
| 全螢幕相機 component | Capacitor `@capacitor/camera` plugin + crop |
| 結果頁 | progressive disclosure + macro ring SVG（套 design-svg package style） |
| 「不是這個」flow | candidate list + manual entry sheet |
| 食物相簿 | tab 內 grid view + 1-tap 重複記錄 |
| 離線快取 | IndexedDB queue + 回線重送 |

---

## 5. 訂閱 Gating 與成本控管

### 5.1 Quota tier

| Tier | 拍照 AI | 文字辨識 | 朵朵點評 |
|---|---|---|---|
| Free | 3 次/天 | 無限（成本低） | 拍照後固定模板（不 burn AI） |
| Monthly NT$290 | 無限 | 無限 | 動態 AI 點評 |
| Yearly / VIP | 無限 | 無限 | 動態 + 週/月報整合 |

**Free 超額時的 paywall 文案**（朵朵語氣，不打擾）：
> 「妳今天的拍照次數用完了 🌱
> 還是可以手打或用文字描述喔。
> 想無限拍照的話 → [升級看看]」

→ **不要強迫**，提供「手打 / 文字描述」當無痛 fallback。

### 5.2 成本守門（guard rail < NT$80/活躍/月）

| 機制 | 影響 |
|---|---|
| 圖片 resize 到 1024px | -40% token cost |
| Anthropic prompt cache（system + few-shot 部分） | -60% 重複成本 |
| 信心 ≥ 0.95 結果 cache 1 hour（同 user 重拍同食物） | -20% |
| Free 用戶 3 次/天 cap | 燒錢上限可預測 |
| 月成本 / DAU 推 Discord cost-watch 頻道（每日） | 異常即時抓 |

預估每月活躍成本：
- Free 用戶：3 次 × 30 天 × ~$0.01/次 = **$0.9 / 用戶 / 月** ≈ NT$28
- Paid 用戶：~10 次 × 30 天 × $0.01 = $3 / 月 ≈ NT$93（值得，因有 NT$290 收入）

混合 7% 付費下，加權月成本 ≈ NT$32 / 活躍 → **遠低於 NT$80 guard rail** ✅

---

## 6. 驗收條件

### Backend
- [ ] `POST /api/meals/recognize` happy path（authenticated, tier-gated）
- [ ] Free tier 第 4 次返 402 + paywall payload
- [ ] Paid tier 第 N 次仍 200
- [ ] confidence < 0.85 返 candidates + flag
- [ ] 圖片大於 5MB → 422
- [ ] 跨 tenant 寫入失敗（紅線）
- [ ] AiCostGuardService 累計拍照 cost 正確
- [ ] Pest 全綠 + phpstan clean

### ai-service
- [ ] vision recognize 返新 schema（macro_grams + dodo_comment）
- [ ] multi-dish 圖能拆分
- [ ] confidence threshold respected
- [ ] cost cap 觸發拒絕

### frontend
- [ ] 拍照 → 結果頁 < 2s（4G 環境）
- [ ] shimmer 不超過 0.3s
- [ ] macro ring 渲染正確
- [ ] 朵朵點評文案 < 25 字
- [ ] 「不是這個」候選 → 手打 fallback
- [ ] 離線拍照 queue + 回線重送
- [ ] e2e smoke：onboarding → 拍照 → 確認記錄 → daily log 出現

### 商業
- [ ] App Store 描述 + 截圖更新（拍照鉤子當主賣點）
- [ ] 月成本 / DAU Discord 推播設好

---

## 7. 不做（Out of Scope）

- ❌ 條碼掃描（MFP 強項，meal 不對標）
- ❌ 食物資料庫深度（用 AI 繞過）
- ❌ 社群分享拍照（toxicity 風險，留到週報才做）
- ❌ 影片辨識（成本不划算）
- ❌ 真人營養師審核拍照（VIP tier 才上，後續 spec）

---

## 8. 預估工時

| 區塊 | 工時 |
|---|---|
| ai-service prompt + schema + multi-dish | 2-3 天 |
| Laravel quota + cost guard + endpoint | 2 天 |
| frontend 相機 + 結果頁 + macro ring | 4-5 天 |
| 朵朵點評整合 + 食物相簿 + 離線 queue | 3 天 |
| e2e + smoke + 成本驗證 | 2 天 |
| **合計** | **13-15 天**（單人 frontend + backend 同時開） |

---

## 9. 風險

| 風險 | 緩解 |
|---|---|
| AI 成本爆 | quota cap + cache + downscale + Discord 監控 |
| 辨識準確率不到 85% | confidence threshold + 候選清單 + 手打 fallback |
| iOS 相機權限被拒 | first-run 教育 + 文字辨識 fallback 仍可用 |
| 競品 Cal AI 在台灣本地化更快 | meal 差異化靠朵朵 NPC + 養成系統，不純拚 AI 準確率 |

---

## 10. 後續鉤子（不在本 spec）

完成後自然接：
- **斷食 timer**（spec 02）— 拍照確認時間 = 進食期判定來源
- **每週 AI 報告**（spec 04）— 拍照數據 = 週報核心素材
- **進度照相簿**（spec 05）— 與食物相簿共用 UI 模式
