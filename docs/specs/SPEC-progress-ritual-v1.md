# SPEC：進度照 + Outfit Ritual Moment v1

> 📅 起草：2026-05-03
> 🎯 目標：把既有「進度照單張看 / 兩張並排對比」+「outfit 解 sparkle 動效」延伸成完整 ritual moment（before/after slider + 月度 collage + fullscreen 解鎖儀式 + 分享卡）
> 📊 對標：Noom（before/after slider）/ Simple（GPT 月度 collage）/ Happy Scale（timeline 圖卡）/ BetterMe（解鎖 fullscreen 動畫）
> 💰 商業角色：**情緒鉤子 + VIP tier 差異化 + 有機獲客（分享出去帶下載）**
> 🔗 關聯：[SPEC-progress-photo-album.md](SPEC-progress-photo-album.md)（v1 baseline，已 ship `progress_snapshots` table + ProgressSnapshotController）
> 🔗 關聯：[SPEC-seasonal-outfit-cards.md](SPEC-seasonal-outfit-cards.md)（outfit unlock ritual 在這延伸）
> 🔗 關聯：[SPEC-weekly-ai-report.md](SPEC-weekly-ai-report.md)（圖卡 export 模式可重用）

---

## 1. 為什麼 v1 album 不夠

進度照 v1 解了「儲存 + 隱私 + 對比」基礎，但漏掉真正的情緒鉤子：

| 目前 v1（已 ship） | 用戶感受 | 競品做了什麼 |
|---|---|---|
| 兩張並排對比 | 平淡，看不出變化 | Noom：拖曳 slider 揭露差異，「看見」變化 |
| 朵朵單句點評 | 一句話，無記憶點 | Simple：月度 collage 4-9 張 + 統計 + 朵朵手寫信，可分享 |
| Outfit 解鎖 sparkle 動效 | 不痛不癢 | BetterMe：fullscreen unlock + 朵朵說話 + 限時優惠 |
| 30 天連勝 → +XP | 數字漲一下 | Strava：streak 里程碑 fullscreen celebration |
| 沒分享機制 | 0 organic 獲客 | Spotify Wrapped / Simple 分享卡 = 自帶曝光 |

**核心缺口**：把資料「儀式化」成可分享、可記憶、可炫耀（但不 body-shame）的時刻。

---

## 2. Ritual Moments（v1 上線 5 個）

| # | Ritual key | 觸發 | 使用 tier | 視覺強度 |
|---|---|---|---|---|
| 1 | `progress_photo_slider` | 用戶在進度照相簿 select 2 張 → 進對比模式 | Monthly+ | 全螢幕 slider |
| 2 | `monthly_progress_collage` | 每月 1 號 backend job 自動生成（過去 30 天 ≥ 4 張進度照才生） | Yearly+ | 一張可分享圖卡 |
| 3 | `outfit_unlock_fullscreen` | 解到 rare/legendary outfit（特別是季節限定） | All（內容看 tier） | fullscreen 動畫 + 朵朵說話 |
| 4 | `streak_milestone_celebration` | 任意 streak 30/60/100/365 天達成 | All | fullscreen + 朵朵手寫信 + 解 outfit |
| 5 | `season_outfit_reveal` | 新季節 outfit 釋出當天 user 第一次開 App | All（試穿看 tier） | fullscreen reveal + 倒數 chip |

→ 5 個 ritual 共用同一套 fullscreen container + animation primitive，frontend 只實作 1 套基礎 component，內容 driven by ritual_key。

---

## 3. Ritual #1：Before/After Slider

### 3.1 UX

```
[相簿 list view]
  ↓ select 2 張（隔 ≥ 7 天）
[進「對比模式」]
  ↓
全螢幕（black bg）：
┌─────────────────────────────┐
│                             │
│    [前 2026/01/10]   [後 2026/05/03] │
│                             │
│         ┌──────────────┐    │
│         │   照片左半    │   │ ← 滑桿位置
│         │              │   │
│         │   ╳ 照片右半 │   │
│         └──────────────┘    │
│              ▲              │
│         [拖曳 slider]        │
│                             │
│     1/10 → 5/3  (114 天)    │
│      57.2kg → 53.8kg        │
│      ─3.4kg                 │
│                             │
│     朵朵：「妳堅持了 114 天 ✨」 │
│                             │
│     [💾 儲存圖卡] [↗ 分享]    │
└─────────────────────────────┘
```

**slider 互動**：
- 預設位置 50%，左半 = 前 / 右半 = 後
- 拖曳 → 動態 reveal；整張左滑 → 全顯示「後」
- 上下兩個小 thumbnail label（日期 + 體重）固定不動
- 點任一 thumbnail → 換另一張對比

**朵朵語氣**（**合規嚴審**）：
- ✅ 「妳堅持了 X 天」「身形有變化」「維持習慣」「動了起來」
- ❌ 「妳變瘦了 X kg」「減脂成功」「燃脂效果」「瘦身有感」

**儲存圖卡 / 分享**：
- 圖卡 = 雙照 + 期間 + 中性數字 + 朵朵語錄 + 寵物 avatar + #潘朵拉飲食 hashtag
- **face blur** 永遠保留（即使原圖用戶手動關了，分享圖卡強制 blur）
- 分享走 Capacitor share plugin（IG / LINE / Telegram / Pinterest）

### 3.2 Tier gating

| Tier | 對比模式 | Slider | 圖卡儲存 | 分享 |
|---|---|---|---|---|
| Free | ❌ | ❌ | ❌ | ❌ |
| Monthly | ✅ 並排對比（v1 既有） | ✅ NEW | ✅ NEW（無浮水印） | ✅ NEW |
| Yearly | + 月度 collage（ritual #2） | ✅ | ✅ | ✅ |
| VIP | + 季度回顧長 collage | ✅ | ✅ | ✅ |

→ Slider 是 Monthly 升級主賣點之一（NT$290/mo 看見變化）。

---

## 4. Ritual #2：月度 Collage 自動生成

### 4.1 Job 規格

**新 schedule `progress:generate-monthly-collage`** 每月 1 號 03:00 Asia/Taipei：

1. for each Yearly+ user:
2.   過去 30 天 progress_snapshots ≥ 4 張？否 → skip
3.   挑 4-9 張代表性照片（演算法：均勻分布在 30 天 + 體重變化最大的點）
4.   pull 30 天統計：飲食達標日 / 步數總和 / 斷食達標日 / 體重變化（中性數字）/ 連勝最長
5.   ai-service narrative kind=`monthly_collage_letter`（朵朵手寫信 80-150 字，paid tier）
6.   render 圖卡 PNG 1080×1920（backend `intervention/image` or frontend html-to-image，先選 backend 確保品質一致）
7.   寫入 `monthly_collages` 表
8.   push 通知用戶「妳的 4 月回顧出爐 ✨」（deep-link 進 collage view）

### 4.2 Collage 圖卡視覺

```
┌──────────────────────────────────┐
│   🌱 朵朵的 4 月回顧               │
│   2026/04/01 - 2026/04/30        │
│                                  │
│   ┌────┐ ┌────┐ ┌────┐ ┌────┐   │
│   │1/10│ │1/15│ │2/3 │ │2/28│   │ ← 4-9 張縮圖（face-blurred）
│   └────┘ └────┘ └────┘ └────┘   │
│                                  │
│   📊 這個月妳：                    │
│   🍱 飲食達標 23 / 30 天          │
│   🚶 走了 245,000 步              │
│   ⏱️ 斷食達標 18 / 30 天          │
│   🌟 連勝最長 14 天               │
│                                  │
│   ─────────────────────         │
│   朵朵：「這個月妳很穩 🌱        │
│         11 公里的累積，          │
│         身形有變化是自然的事。   │
│         5 月繼續走下去吧 ✨」    │
│   ─────────────────────         │
│                                  │
│   🐧 [寵物 avatar]               │
│   #潘朵拉飲食 #朵朵月報           │
└──────────────────────────────────┘
```

### 4.3 Tier gating

| Tier | 月度 collage |
|---|---|
| Free | ❌ |
| Monthly | preview 一張小圖（朵朵預覽信） + 「升級年付看完整 collage」 |
| Yearly | ✅ 完整 + 分享 |
| VIP | + 季度回顧長 collage（每 3 月 = 一張更長 collage 含季度語錄） |

---

## 5. Ritual #3：Outfit Unlock Fullscreen

### 5.1 觸發

- 解到 rare outfit（季節限定 / 節日特別 / quest chain reward）→ 立即 fullscreen
- 解到 common outfit（日常 quest）→ 既有 sparkle 動效，不 fullscreen（避免疲勞）

### 5.2 視覺

```
[fullscreen 動畫 sequence]

0.0s: 黑屏 fade-in
0.3s: 中央 sparkle 粒子聚集
0.8s: outfit silhouette 從上方 drop down
1.2s: outfit 完整 reveal + 寵物穿上
1.5s: 朵朵頭像 slide-in from left
1.8s: 朵朵語氣文案 type-on:
      「春櫻系列 🌸
       妳堅持了 14 天才解鎖
       這套配妳的寵物超好看」
3.0s: [試穿] [稍後] buttons fade-in
```

→ 動畫用 `@vueuse/motion` + Lottie / SVG (design-svg package)。

### 5.3 朵朵語氣（rare outfit 專用）

| Outfit type | 朵朵範本 |
|---|---|
| 季節限定 | 「[季節] 系列 🌸 限定 60 天，要試試嗎？」 |
| 節日特別 | 「[節日] 來了 ✨ 這套只有今天」 |
| Quest chain | 「妳走完了 [quest 名] 🌱 這是給妳的禮物」 |
| Streak milestone | 「[X] 天連勝 🌟 這是專屬的」 |

---

## 6. Ritual #4：Streak Milestone Celebration

觸發：任意 streak（飲食 / 斷食 / 步數 / 體重打卡 / 拍照）達 30 / 60 / 100 / 365 天。

```
[fullscreen]

0.0s: 黑屏
0.5s: 中央大字 streak 數字 zoom-in（500% scale → 100%）
      "30"
1.0s: 副標 fade-in：「天連勝」
1.5s: 朵朵頭像 + 寵物 slide-in
2.0s: 朵朵手寫信 type-on（80-150 字 paid / 30 字 free）：
      「30 天耶 🌟
       妳真的做到了。
       不是每個人都能堅持這麼久，
       要記得對自己說一聲『辛苦了』」
3.5s: [解鎖獎勵 outfit] sparkle 飛入 + outfit unlock 連動 ritual #3
4.5s: [📸 紀念圖卡] [回到 App] buttons
```

紀念圖卡可分享（Yearly+ 才有 export，Monthly 看不下載）。

---

## 7. Ritual #5：Season Outfit Reveal

新季節 outfit 釋出當天，user 第一次開 App：
- 全螢幕 reveal sequence（同 ritual #3 但用 reveal-style 而非 unlock-style）
- 倒數 chip「限定 60 天」常駐 wardrobe 入口
- Free user 看 reveal + paywall sheet「升級就能整套穿」

---

## 8. Backend 變更

### 8.1 資料模型

**新 migration `2026_05_04_160000_create_progress_rituals_tables.php`**

```php
Schema::create('monthly_collages', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->date('month_start');                       // 2026-04-01
    $t->json('snapshot_ids');                      // [123, 145, 167, 189]
    $t->json('stats_payload');                     // 飲食/步數/斷食/體重 stats
    $t->text('narrative_letter');                  // 朵朵手寫信 80-150 字
    $t->string('image_path')->nullable();          // 1080x1920 PNG storage path
    $t->integer('shared_count')->default(0);
    $t->timestamps();

    $t->unique(['user_id', 'month_start']);
});

Schema::create('ritual_events', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('ritual_key', 64)->index();         // 'streak_30' / 'outfit_unlock_rare' / ...
    $t->string('idempotency_key')->unique();       // user:ritual:context
    $t->json('payload');                            // outfit_id / streak_count / ...
    $t->timestamp('triggered_at');
    $t->timestamp('seen_at')->nullable();
    $t->timestamp('shared_at')->nullable();
    $t->timestamps();

    $t->index(['user_id', 'seen_at']);
});

Schema::create('share_card_renders', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('source_type', 32);                 // 'monthly_collage' / 'streak_milestone' / 'photo_slider'
    $t->unsignedBigInteger('source_id');
    $t->string('image_path');
    $t->string('checksum', 64)->index();           // 同內容不重 render
    $t->timestamps();
});
```

### 8.2 Service

```
app/Services/Ritual/
├── RitualDispatcher.php           # entry: 觸發任一 ritual 寫 ritual_events + push
├── MonthlyCollageGenerator.php    # job 主邏輯
├── ShareCardRenderer.php          # 圖卡 PNG 產生（intervention/image）
├── PhotoSelector.php              # collage 選 4-9 張代表照演算法
└── NarrativeLetterClient.php     # call ai-service 朵朵手寫信
```

### 8.3 Schedule

| Cron | 行為 |
|---|---|
| `progress:generate-monthly-collage` 每月 1 號 03:00 | 為 Yearly+ user 生 collage + push |
| `ritual:cleanup-old-renders` 每週日 03:00 | 清 90 天前的 share_card_renders（圖檔 + DB） |

### 8.4 Endpoints

| Method | Path | 說明 |
|---|---|---|
| GET | `/api/rituals/unread` | 拉未看 ritual events（首頁 surface） |
| POST | `/api/rituals/{event}/seen` | mark seen |
| POST | `/api/rituals/{event}/share` | 產生 share card + 返 image_url |
| POST | `/api/progress/compare/share-card` | 給 slider compare 兩張，產生 share card |
| GET | `/api/collages` | 月度 collage 歷史（paginate） |
| GET | `/api/collages/{collage}` | collage detail（圖卡 url + narrative） |
| POST | `/api/collages/{collage}/share` | 增加 shared_count + 返 image_url |

### 8.5 Achievement 整合

`PublishAchievementAwardJob` 在 award 時若是 rare outfit / streak milestone，dispatch RitualDispatcher，自動寫 ritual_events + push。

---

## 9. ai-service 變更

### 9.1 新 NarrativeKind

```python
class NarrativeKind(str, Enum):
    ...
    MONTHLY_COLLAGE_LETTER = "monthly_collage_letter"
    STREAK_MILESTONE_LETTER = "streak_milestone_letter"
    PROGRESS_SLIDER_CAPTION = "progress_slider_caption"
```

### 9.2 Prompt（**合規嚴審**）

```
SYSTEM: 妳是朵朵 dodo...
- 對用戶用「妳」/「朋友」
- **絕對禁用**：減重 / 減脂 / 燃脂 / 瘦身 / 塑身 / 速瘦 / 暴瘦 / 變瘦 / 「妳變漂亮了」/ 「身材變好了」/ 「成功瘦了」
- **可用替代**：堅持 / 動了起來 / 維持 / 規律 / 變化 / 累積 / 步伐
- 不評論身材外觀（不寫「妳的腰看起來」「身形比例」）
- 不下醫療結論
- 80-150 字（collage / streak）/ ≤ 30 字（slider caption）
- 結尾鼓勵但不諂媚

FOR collage:
context: month, snapshot_count, stats (food_days, steps_total, fasting_days, weight_change_kg, longest_streak)
"weight_change_kg" 永遠以中性方式呈現（「身形有變化」「體重移動了 X kg」），不寫「瘦了」「胖了」

FOR streak_milestone:
context: streak_kind, streak_count, recent activity highlight
"妳真的做到了" 而非 "妳成功了"

FOR slider_caption:
context: photo_dates, days_between, weight_change_kg
≤ 30 字，「妳堅持了 X 天 ✨」式
```

### 9.3 stub mode

Free / stub 走 deterministic template（同上格式），確保即使 AI 掛掉也合規。

---

## 10. Frontend 變更

### 10.1 共用 ritual fullscreen container

`RitualFullscreen.vue`：
- 全螢幕 black overlay
- prop: `ritualKey`, `payload`
- slot 子元件 by ritualKey（PhotoSliderRitual / OutfitUnlockRitual / StreakRitual / SeasonRevealRitual / CollagePreviewRitual）
- 共用 enter/exit animation primitive
- ESC / tap outside 任意處 dismiss

### 10.2 PhotoSliderComponent

`BeforeAfterSlider.vue`：
- two `<img>` overlapped + clip-path linear-gradient driven by drag x
- 觸控 + 滑鼠 + 鍵盤 ←/→ 都支援
- @vueuse/gesture for drag

### 10.3 Share card export

優先 backend render（intervention/image PHP）保證跨平台一致；frontend html-to-image 當 Plan B（離線時）。

### 10.4 首頁 surface 機制

`HomeRitualBanner.vue`：開 App 後檢查 `/api/rituals/unread`，若有 unseen ritual → banner（不全螢幕，怕打擾）；用戶 tap → fullscreen。

---

## 11. 訂閱 Gating 總表

| Ritual | Free | Monthly | Yearly | VIP |
|---|---|---|---|---|
| Photo slider | ❌ | ✅ | ✅ | ✅ |
| Photo slider 圖卡儲存 | ❌ | ✅ | ✅ | ✅ |
| Photo slider 圖卡分享 | ❌ | ✅ | ✅ | ✅ |
| 月度 collage | ❌ | preview only | ✅ | ✅ + 季度長 collage |
| Outfit unlock fullscreen | ✅（看到動畫但解 outfit 內容看 tier） | ✅ | ✅ | ✅ |
| Streak milestone fullscreen | ✅ | ✅ | ✅ | ✅ |
| Streak milestone 紀念圖卡 export | ❌ | ❌ | ✅ | ✅ |
| Season reveal fullscreen | ✅ | ✅ | ✅ | + 早鳥 7 天 |
| 朵朵 dynamic AI letter | ❌ template | ❌ template | ✅ | ✅ |

---

## 12. 食安法合規（**極度嚴審**）

進度照 / 體重 / 體型相關文案是減重廣告高危區，**比 insight 更嚴**。

| 強制機制 | 範圍 |
|---|---|
| Sanitizer pre-check on AI prompt context | weight_change_kg 不直接傳 → 改傳 `behavior_consistency_score` 等中性指標 |
| Sanitizer post-check on AI output | 50+ 違規詞清單，命中 → 降級 template |
| Free template 全 narrative-designer + design-brand-guardian dual-review | 所有 collage / streak / slider template |
| Share card 文案 hard-coded sanitizer | 即使 user 自訂備註，分享卡 caption 強制過 sanitizer |
| Face blur 不可關（分享時） | 即使原圖未 blur，share_card_renderer 強制 blur |
| 不顯示 X kg 變化（分享卡） | 圖卡顯示「身形變化」「持續 X 天」而非 kg |

CI guard：
- `tests/Feature/Compliance/RitualContentGuardTest.php` — 5 種 ritual 的 free template 全 sanitize pass
- ai-service `test_progress_narrative_compliance.py` — 100 hostile prompt + post-sanitize 全 pass
- `tests/Feature/Ritual/ShareCardSanitizationTest.php` — render share card 後 OCR 文字（mock）過 sanitize

---

## 13. 驗收條件

### Backend
- [ ] migration / rollback
- [ ] MonthlyCollageGenerator: ≥ 4 張才生 / 圖檔產出 / shared_count 正確
- [ ] PhotoSelector 演算法 unit test（均勻分布 + 體重變化點）
- [ ] ShareCardRenderer 圖檔 1080×1920 + 文字 sanitize pass
- [ ] RitualDispatcher idempotent（同 outfit_id / streak_count 不重 fire）
- [ ] 7 endpoints feature test happy + 401 + cross-tenant
- [ ] 月度 schedule 在 Asia/Taipei timezone 正確跑
- [ ] Pest + phpstan + RitualContentGuardTest pass

### ai-service
- [ ] 3 NarrativeKind happy + stub_mode + sanitize post
- [ ] 100 hostile prompt 0 violation
- [ ] cost guard：collage ≈ 0.5 NT$ / call，per user 月上限 1 次

### Frontend
- [ ] BeforeAfterSlider 拖曳順 60fps + 觸控 / 滑鼠 / 鍵盤
- [ ] RitualFullscreen 5 變體渲染
- [ ] Share sheet 測 4 平台（IG / LINE / Telegram / Pinterest）
- [ ] Free user tap slider → paywall sheet
- [ ] e2e smoke：select 2 photos → slider → share
- [ ] e2e smoke：mock streak 30 → fullscreen + share

### 量化指標（上線後 8 週追蹤）
- [ ] Yearly tier 升級轉換率 +3pp（vs baseline，slider 是主驅力）
- [ ] 月度 collage 分享率 > 8%（VIP / Yearly）
- [ ] Streak milestone fullscreen 完成觀看率 > 80%（不立即 dismiss）
- [ ] 分享卡帶回新下載 UTM > 1% 月新 install
- [ ] 違規詞投訴 = 0 / 食安法檢舉 = 0

---

## 14. PR 切片

| PR | 範圍 | 依賴 |
|---|---|---|
| **#1 backend schema + RitualDispatcher + ShareCardRenderer + 5 endpoints** | migration + service + endpoints + Pest + ContentGuard | 無 |
| **#2 backend MonthlyCollageGenerator + schedule + push** | photo selector + collage job + push template | #1 |
| **#3 ai-service 3 NarrativeKind + sanitize + pytest** | prompt + stub + cost guard | #1（schema） |
| **#4 frontend BeforeAfterSlider + RitualFullscreen + 5 variants** | 共用 component + slider + fullscreen + e2e | #1 |
| **#5 frontend collage view + share + home banner** | collage list / detail / share + first-open ritual surface | #2 + #3 + #4 |

---

## 15. 預估工時

| 區塊 | 工時 |
|---|---|
| #1 backend schema + dispatcher + share card renderer | 3 天 |
| #2 monthly collage generator + photo selector + schedule | 2 天 |
| #3 ai-service narrative + sanitize | 2 天 |
| #4 frontend slider + fullscreen primitive + 5 variants + e2e | 5 天 |
| #5 frontend collage UI + share + home surface | 2 天 |
| **合計** | **14 天** |

---

## 16. 不做（Out of Scope）

- ❌ AI 看進度照本身做點評（v1 album 紅線：AI 不看圖；保留）
- ❌ Body composition 分析（醫療責任 + 違反食安法）
- ❌ 真人營養師看照片給建議（VIP 升級線後續）
- ❌ 進度照公開 timeline / community feed（toxicity + 隱私）
- ❌ Outfit / collage 賣斷單買（破壞訂閱模式）
- ❌ Year-end Wrapped（v2 留 12/31）

---

## 17. 風險

| 風險 | 緩解 |
|---|---|
| 進度照 / 分享卡帶體重數字外流 → 食安法違規 | share card 不顯 kg；只顯天數 / 中性數字 |
| AI 朵朵語氣 body-shame 用戶 | sanitize + free template + designer dual-review |
| Fullscreen ritual 太頻繁打擾 | RitualDispatcher idempotent + per-user 一週 ritual 上限 3 個（多的延後） |
| 月度 collage 圖檔積爆 storage | 90 天 cleanup + S3 lifecycle policy |
| Slider 拖曳卡頓 | clip-path 比 mask 快；測 60fps in 中階 Android device |
| 分享出去 Apple / Google review 找麻煩 | 分享卡不出現「減重 / 燃脂」字 + 隱私 face blur 強制 |

---

## 18. 後續鉤子（v2 / v3）

- Year-end Wrapped（年度大 collage，12/31 釋出）
- 季度回顧長 collage（VIP 已規劃）
- 進度照 timelapse 影片（30 張串成 5 秒短片）
- Voice ritual narration（朵朵口語化播報）
- Collage 共享給 partner / 朋友（雙人合照 collage）
