# SPEC：季節限定 Outfit + Cards 收藏完整度 Paywall

> 📅 起草：2026-05-02
> 🎯 目標：把既有的 outfit / cards 系統挖深，引入 FOMO + 收藏動機 → 訂閱續命
> 📊 對標：Genshin Impact 季節活動 / Pokémon Go 限定卡 / Notion 主題包
> 💰 商業角色：**既有功能變現深挖（Tier 2 優先序 #6）**
> 🔗 關聯：既有 OutfitMirror、CardService、AchievementService

---

## 1. 為什麼是優先序 #6

| 維度 | 影響 |
|---|---|
| **零新功能成本** | outfit / cards 系統已有，只是內容 + paywall trigger 設計 |
| **FOMO 變現** | 「期間限定」是訂閱續命的最便宜手段 |
| **集滿動機** | 收藏完整度 X% 顯示 = 自然 paywall trigger |
| **與既有節氣 / 推播協同** | 24 節氣彩蛋系統可直接接 |
| **不打擾紅線** | 純美術 / 內容變更，不增加加盟入口 |

---

## 2. 季節 Outfit 機制

### 2.1 釋出節奏

| 季 | 主題 | 釋出 | 限定期 |
|---|---|---|---|
| **春**（立春-穀雨） | 櫻花 / 春芽 | 2/4 | 60 天 |
| **夏**（立夏-大暑） | 海洋 / 夏夜 | 5/5 | 60 天 |
| **秋**（立秋-霜降） | 楓葉 / 月圓 | 8/7 | 60 天 |
| **冬**（立冬-大寒） | 雪夜 / 暖爐 | 11/7 | 60 天 |
| **節日特別款** | 中秋 / 聖誕 / 新年 / 七夕 | 各節氣前 1 週 | 14 天 |

→ 4 季 × 1 套 + 4 節日 × 1 套 = **每年 8 套限定 outfit**。

### 2.2 取得方式

| 方式 | 對象 | 說明 |
|---|---|---|
| **Paid 訂閱中自動解鎖** | Monthly+ | 訂閱期間內全套限定 outfit 直接領 |
| **Free 用戶可看不可穿** | All | 預覽鎖頭 icon → tap → paywall sheet |
| **VIP 早鳥** | VIP | 限定期前 1 週搶先穿 |
| **節氣彩蛋** | All | 完成節氣 quest → 解 1 件配件（不全套） |

→ 退訂後**保留歷史限定 outfit**（已解鎖 = 永久持有，避免「退訂 = 失去」恐慌；只是當期新限定不再解）。

### 2.3 朵朵 NPC 互動

| 觸發 | 朵朵語氣 |
|---|---|
| 限定 outfit 即將下架 7 天 | 「春櫻系列剩 7 天 🌸 還沒試試嗎？」 |
| 用戶試穿限定 outfit | 「這套配妳的寵物超好看 ✨」 |
| Free 用戶 tap 鎖頭 | 「想試試看嗎？升級就能整套穿」 |

---

## 3. Cards 收藏完整度 Paywall

### 3.1 收藏結構

| 卡牌類別 | 數量 | 取得 | tier |
|---|---|---|---|
| 食物百科 | ~200 | 拍照辨識 unique 食物自動解 | Free |
| 健康知識卡 | ~80 | 連勝 / quest 解 | Free |
| 季節限定卡 | ~32（4 季 × 8） | 季節活動 quest | Paid |
| 節日特別卡 | ~16（4 節 × 4） | 節氣彩蛋 | Paid |
| FP 產品搭配卡 | ~24 | 母艦消費後 unlock（保留現有設計） | 母艦消費 |
| 朵朵故事卡（潘朵拉世界觀） | ~12 | Quest chain 完成 | VIP |

### 3.2 完整度顯示

```
[Cards tab]
  ┌────────────────────────────┐
  │  我的收藏  142 / 364 (39%) │
  │  ████████░░░░░░░░░░░       │
  │                            │
  │  📚 食物百科  187/200 (93%) │
  │  💚 健康知識  45/80  (56%)  │
  │  🌸 春櫻限定  12/32  (37%)  │← Paid（鎖頭）
  │  🎁 節日特別  3/16   (18%)  │← Paid（鎖頭）
  │  ⭐ FP 搭配  0/24   (0%)   │← 母艦消費後
  │  📖 朵朵故事  0/12   (0%)   │← VIP
  └────────────────────────────┘
```

→ Free 用戶看到「春櫻 12/32 (37%) 鎖」→ 自然好奇 → tap → paywall sheet「升級看看缺的卡」

### 3.3 Paywall trigger 點

| 觸發 | 強度 | 文案（朵朵 voice） |
|---|---|---|
| Free 用戶 tap 季節卡縮圖 | 弱 | 「這張卡需要訂閱才能看 🌸」+ 升級按鈕 |
| 食物百科達 50% | 中 | 「妳很會吃 ✨ 完整 200 張要不要試試？」 |
| 連續 7 天活躍 | 中 | 「妳已經堅持一週 🌟 解鎖完整收藏？」 |
| 朋友分享卡片 | 弱 | 「來看看完整收藏 ✨」 |

→ **絕對不做**：強制彈窗、無關 paywall（紅線 — 不打擾體驗）。

---

## 4. 技術變更

### 4.1 Backend

| 項目 | 改動 |
|---|---|
| `outfits` table 加 `release_at` / `expires_at` / `is_seasonal` | 限定期管理 |
| `cards` table 加 `category` / `tier_required` | 完整度計算 |
| 新 service `SeasonalContentService` | 排程釋出 / 過期 |
| 新 endpoint `GET /api/cards/completion` | 各類別完整度 |
| 排程 | 每日 00:00 檢查季節釋出 / 過期 |
| Push template | `seasonal_outfit_release` / `seasonal_outfit_expiring` |
| Achievement | 「春之收藏者」「節日狂熱」（集滿一季）等 |

### 4.2 內容生產

| 項目 | 工作量 |
|---|---|
| 4 季 outfit 設計（共 4 套 × N 件 = ~32 件） | 美術產線（design-svg package）|
| 4 節日 outfit | 4 套 × N 件 |
| 32 季節卡 + 16 節日卡 美術 + 文案 | content + design 產線 |
| 12 朵朵故事卡 + quest chain 設計 | narrative-designer agent |

→ **這 spec 主要是「機制 + 系統」，內容產線是另一條獨立工作流**。

### 4.3 Frontend

| 項目 | 改動 |
|---|---|
| Cards tab 完整度顯示 | progress bar + 各類別卡片 |
| 限定 outfit 倒數 chip | 在 wardrobe 顯示「剩 N 天」 |
| Paywall sheet（季節限定 / 完整度觸發） | 朵朵語氣文案 |
| 解鎖動畫 | 季節 outfit 解鎖時的 sparkle 動效（design-whimsy-injector） |

---

## 5. 訂閱衝擊預估

假設 5 萬 MAU、目前訂閱率 5%：
- 每季限定 outfit = 推一次升級轉換
- 8 套 / 年 = 8 次自然 paywall 觸發
- 預估每次轉換 0.3% Free → Paid → 年增 2.4% 訂閱率
- 從 5% → 7.4%（與「拍照 AI」「斷食 timer」協同推升）

→ ARR 增量試算：5 萬 × 2.4% × NT$320 × 12 = **NT$461 萬 / 年增量**（純內容變現）

---

## 6. 驗收條件

### Backend
- [ ] 季節釋出 / 過期排程正確
- [ ] 完整度 API 算對（含 tier-gated）
- [ ] 退訂後保留歷史限定（不收回）
- [ ] Pest 全綠 + phpstan clean

### Frontend
- [ ] 倒數 chip 即時更新
- [ ] Paywall trigger 點精準
- [ ] 解鎖動畫流暢
- [ ] e2e smoke

### 商業
- [ ] 限定 outfit 釋出當週訂閱率 > 平日 1.5×
- [ ] Cards 完整度 50% Free → Paid 轉換 > 3%

---

## 7. 不做（Out of Scope）

- ❌ 賣斷單套 outfit（破壞訂閱模式）
- ❌ 真錢購買虛擬幣（複雜 + App Store 抽成 30% 不划算）
- ❌ NFT / 加密貨幣（避免）
- ❌ Trade / 交易（toxicity 風險）

---

## 8. 預估工時

| 區塊 | 工時 |
|---|---|
| Backend service + 排程 + endpoints + tests | 4 天 |
| Frontend 完整度 UI + paywall + 倒數 + 動畫 | 4 天 |
| 內容產線（首季 outfit + cards） | 5 天（美術 / 文案，可平行） |
| e2e + 測試季節釋出排程 | 2 天 |
| **合計** | **15 天**（內容產線可不阻塞 backend / frontend） |

---

## 9. 風險

| 風險 | 緩解 |
|---|---|
| 退訂用戶 backlash「我的 outfit 不見了」 | 已解鎖永久保留；明文說明「只限定當期新內容」 |
| 季節釋出排程錯（過早 / 過晚） | 排程 + manual override 工具 |
| 內容產線追不上釋出節奏 | 提前 1 季備好內容；輕量改色版兜底 |
| FOMO 操作過度 → 反感 | 朵朵語氣 + 不強迫；倒數提醒一週只 1 次 |
