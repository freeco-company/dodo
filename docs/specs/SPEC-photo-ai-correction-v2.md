# SPEC：拍照 AI 修正體驗 v2（Photo AI Correction Deep）

> 📅 起草：2026-05-03
> 🎯 目標：把 v1「不是這個 → top-3 候選」初階體驗，升級成 Cal AI / SnapCalorie 級的「滑桿調份量 + 點擊換食材 + AI 二次推論 + 學習回饋」修正流程
> 📊 對標：Cal AI（份量滑桿）/ SnapCalorie（per-ingredient swap）/ MyFitnessPal Meal Scan（confidence display）
> 💰 商業角色：**直接拉 D30 留存 + 修正完成率（拒絕掉的 estimate = 流失點）**
> 🔗 關聯：[SPEC-photo-ai-calorie-polish.md](SPEC-photo-ai-calorie-polish.md)（v1 baseline，已 ship）、ADR-002 §3
> 📊 baseline：v1 已 ship `add_photo_ai_fields_to_meals_and_users` migration + `AiMealController::scan` + `EntitlementsService::consumePhotoAiQuota`

---

## 1. 為什麼 v1 不夠

v1「不是這個 → top-3 候選 → 手打 fallback」解了「整盤辨識錯」，但漏掉真正高頻的修正場景：

| 真實場景（觀察 Cal AI 用戶 review） | v1 體驗 | 現實摩擦 |
|---|---|---|
| 食物對但份量錯（AI 估 1 碗，實際 1.5 碗） | 整個重來 | 90% 用戶懶得改，記錯卡路里 → 數據垃圾 → 朵朵 insight 失準 |
| 一盤 3 道菜，AI 漏 1 道 | 整盤打回從頭 | 漏記 → daily total 偏低 → 朵朵建議錯 |
| 一盤 3 道菜，AI 認錯其中 1 道（白飯認成糙米） | 整盤打回 | 牽動其他正確的 estimate |
| 用戶常吃的食物，AI 每次都算高 / 低 | 沒學習機制 | 系統永遠不準 |
| 信心 0.6 vs 0.95 對用戶呈現一樣 | 無 confidence cue | 用戶不知道哪些該檢查 |

**核心缺口 = 「per-item 局部修正」+「AI 二次學習」**。

---

## 2. 範圍（vs v1）

| 範圍 | v1（已 ship） | v2（本 SPEC） |
|---|---|---|
| 拍照 → 辨識 → macro ring 主流程 | ✅ | （沿用） |
| 整盤「不是這個」打回 top-3 | ✅ | （沿用，作為 fallback） |
| ai-service 單次 vision recognize | ✅ | + 多 dish 細粒度結構 |
| **per-dish 份量滑桿（0.25-3.0×）** | ❌ | ✅ |
| **per-dish 點擊換食材（候選 list）** | ❌ | ✅ |
| **整盤手動加新 dish（AI 漏的）** | ❌ | ✅ |
| **AI 二次推論**（給 user 修正後 re-estimate macro） | ❌ | ✅ |
| **confidence chip**（每 dish 顯示 high/med/low） | ❌ | ✅ |
| **修正歷史學習回饋**（per-user food calibration） | ❌ | ✅ |
| **修正完成 metric 追蹤** | ❌ | ✅ |

---

## 3. UX Flow（target）

### 3.1 結果頁（multi-dish 結構）

```
┌──────────────────────────────────────┐
│  🍱 妳的午餐 · 2026/05/03 12:34       │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│  總計 720 kcal  ●碳 80g ●蛋 35g ●脂 28g│
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│                                       │
│  📋 4 道菜                             │
│  ┌──────────────────────────────────┐ │
│  │ ✓ 白飯 1 碗   320 kcal    🟢 高  │ │
│  │ ✓ 雞腿  1 隻  280 kcal    🟢 高  │ │
│  │ ✓ 高麗菜      40 kcal    🟡 中  │ │← tap 展開
│  │ ✓ 滷蛋  1 顆  80 kcal    🟢 高  │ │
│  └──────────────────────────────────┘ │
│  [+ 漏了什麼？手動加]                   │
│                                       │
│  朵朵：「碳水偏多了一點 🌱 飯減半也夠」 │
│                                       │
│  [✓ 確認記錄]    [← 重拍]              │
└──────────────────────────────────────┘
```

**confidence chip 視覺**：
- 🟢 高 (≥ 0.85)：白底、不顯眼
- 🟡 中 (0.65-0.85)：淡黃底、暗示「可能要檢查」
- 🔴 低 (< 0.65)：淡紅底 + 「建議確認」hint
- 沒 chip 則 = 用戶手動加（已是事實，不需 confidence）

### 3.2 Per-dish 修正 sheet（tap 任一菜展開）

```
┌──────────────────────────────────────┐
│  ← 修正：白飯 1 碗                     │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│                                       │
│  🍚 食材                               │
│  ┌──────────────────────────────────┐ │
│  │ 白飯  ⌄                          │ │← tap → 候選清單
│  └──────────────────────────────────┘ │
│  候選：白飯 / 糙米 / 五穀飯 / 紫米     │
│  [找不到 → 手打名稱]                   │
│                                       │
│  📏 份量                               │
│  0.5×  ●━━━━━━━━━●━━━━━━━━━ 1.5×     │← 滑桿
│              1.0×（1 碗 ≈ 200g）       │
│                                       │
│  📊 預估                               │
│  320 kcal  ●碳 70g  ●蛋 6g  ●脂 0.5g  │← 滑桿動 → 即時更新
│                                       │
│  [⟲ 用 AI 重新估]    [✓ 套用]          │
└──────────────────────────────────────┘
```

**滑桿規格**：
- 範圍 0.25× ~ 3.0×（25%-300%）
- 預設 stop 在 0.5 / 0.75 / 1.0 / 1.5 / 2.0 / 3.0（snap）
- 動 → macro 即時線性 recalculate（local，0 latency）

**換食材**：
- 候選 list 來自 ai-service 二次推論（top-5 alternatives，confidence ranked）
- 「找不到 → 手打」：開 input + nutrition lookup（食物資料庫 fuzzy match）

**「⟲ 用 AI 重新估」**（重要）：
- 觸發條件：用戶換了食材 / 改了份量超過 ±50%
- 行為：**送修正後的 (food_name, portion_multiplier, image_url) 給 ai-service `/v1/vision/refine`**，AI 用原圖 + user hint 重新估 macro（不是 client 線性算）
- 為什麼：白飯 1.5 碗 vs 糙米 1.5 碗 macro 不是線性（蛋白質 / 纖維差很多），純 client 線性計會錯
- Free quota：refine 算 1 次拍照（即不另外扣 quota，refine 不無限）
- Paid：unlimited refine

### 3.3 「漏了什麼？手動加」

```
[+ 漏了什麼？手動加]
   ↓
┌──────────────────────────────────────┐
│  + 加 1 道菜                           │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ │
│  食材名稱：[___________]               │
│  份量：1×  滑桿                        │
│  分類：[主食 / 主菜 / 配菜 / 湯品 / 點心]│
│                                       │
│  💡 用 AI 估 macro                     │
│  「妳常吃的菜？」→ 從食物相簿挑（1-tap） │
│                                       │
│  [✓ 加入]                              │
└──────────────────────────────────────┘
```

→ 加完回主結果頁，新 dish append + 總計 recalculate + 朵朵點評重生（一次 AI call）

---

## 4. Backend 變更

### 4.1 資料模型

**新 migration `2026_05_04_120000_create_meal_dishes_and_corrections_tables.php`**

```php
Schema::create('meal_dishes', function (Blueprint $t) {
    $t->id();
    $t->foreignId('meal_id')->constrained()->cascadeOnDelete();
    $t->string('food_name');                    // 「白飯」
    $t->string('food_key')->nullable()->index(); // 正規化 key 「rice_white」(food DB lookup)
    $t->decimal('portion_multiplier', 4, 2)->default(1.00); // 0.25-3.00
    $t->string('portion_unit')->nullable();     // 「碗」「隻」「片」
    $t->integer('kcal');
    $t->decimal('carb_g', 6, 2);
    $t->decimal('protein_g', 6, 2);
    $t->decimal('fat_g', 6, 2);
    $t->decimal('confidence', 3, 2)->nullable(); // 0.00-1.00; null = 手動加
    $t->enum('source', ['ai_initial', 'ai_refined', 'user_swapped', 'user_manual']);
    $t->json('candidates_json')->nullable();     // top-5 alternatives from ai-service
    $t->integer('display_order')->default(0);
    $t->timestamps();

    $t->index(['meal_id', 'display_order']);
});

Schema::create('food_corrections', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->foreignId('meal_dish_id')->nullable()->constrained()->nullOnDelete();
    $t->enum('correction_type', ['food_swap', 'portion_change', 'add_missing', 'remove']);
    $t->string('original_food_key')->nullable();
    $t->string('corrected_food_key')->nullable();
    $t->decimal('original_portion', 4, 2)->nullable();
    $t->decimal('corrected_portion', 4, 2)->nullable();
    $t->decimal('original_confidence', 3, 2)->nullable();
    $t->json('context_json')->nullable();        // image_url / meal_type / time_of_day
    $t->timestamps();

    $t->index(['user_id', 'created_at']);
    $t->index(['user_id', 'corrected_food_key']); // calibration lookup
});
```

→ Meals 維持既有 `add_photo_ai_fields_to_meals_and_users` 上的 `kcal/carb_g/protein_g/fat_g`（總計），但語意改為「dishes 的 sum，由 model accessor 自動算或 sync」。

### 4.2 Service：`MealCorrectionService`

```php
class MealCorrectionService
{
    public function applyDishCorrection(MealDish $dish, array $payload): MealDish;
    // payload: [food_key?, portion_multiplier?, kcal?, carb_g?, ...]
    // logs FoodCorrection record + recalc meal totals + (if swap or major portion change) → call ai-service refine

    public function addManualDish(Meal $meal, array $payload): MealDish;
    // user manually added a missed dish; logs correction_type=add_missing

    public function removeDish(MealDish $dish): void;
    // logs correction_type=remove

    public function refineDishViaAi(MealDish $dish, ?string $userHint = null): MealDish;
    // calls ai-service POST /v1/vision/refine; updates dish + logs source=ai_refined

    public function userCalibrationFor(User $user, string $foodKey): ?array;
    // 學習回饋：return ['portion_bias' => -0.15, 'sample_count' => 8]
    // 用於下次 AI initial estimate 時 prompt hint「user 通常 portion 比 AI 估的少 15%」
}
```

### 4.3 Endpoints

| Method | Path | 說明 |
|---|---|---|
| POST | `/api/meals/{meal}/dishes` | 手動加新 dish (correction_type=add_missing) |
| PATCH | `/api/meals/{meal}/dishes/{dish}` | 更新 food_key / portion / macros |
| DELETE | `/api/meals/{meal}/dishes/{dish}` | 移除 dish |
| POST | `/api/meals/{meal}/dishes/{dish}/refine` | 觸發 AI 二次推論 |
| GET | `/api/meals/{meal}/dishes/{dish}/candidates` | 取得換食材候選 list（從 dish.candidates_json） |
| GET | `/api/foods/search?q=...` | 模糊搜尋食物資料庫（手打 fallback） |

→ 既有 `POST /api/meals/scan` 改 response：除既有 fields，新增 `dishes: [...]` 陣列 + 每 dish 的 confidence。

### 4.4 ai-service 對接（HTTP proxy 給 Python）

| ai-service endpoint | Laravel 對接 |
|---|---|
| 既有 `POST /v1/vision/recognize` | response 加 `dishes: [{food_key, food_name, portion, kcal, carb_g, protein_g, fat_g, confidence, candidates: [top-5]}]` |
| **新 `POST /v1/vision/refine`** | input: `image_url, original_dishes[], user_hint{dish_id, new_food_key, new_portion}` / output: 同 recognize structure |
| 既有 user calibration（**meal 帶過去**） | recognize / refine 時帶 `user_calibration: {rice_white: -0.15, chicken_thigh: +0.10, ...}` 給 prompt |

---

## 5. ai-service 變更（Python）

### 5.1 新 `POST /v1/vision/refine`

```python
class RefineRequest(BaseModel):
    image_url: str  # 同 recognize 的 image
    original_dishes: list[DishEstimate]
    user_hint: UserHint  # which dish, what new food/portion

class UserHint(BaseModel):
    dish_index: int
    new_food_key: str | None  # food_swap
    new_portion: float | None  # portion_change
    new_food_name: str | None  # 自由打的（找不到 food_key 時）

# Prompt strategy:
# - Take original image + original dishes as context
# - User says「這道菜其實是 X，份量是 Y」
# - AI 用視覺 + hint 重新估 macro（鎖定該 dish，不動其他 dish 除非用戶 hint）
# - Return refined dish + 同樣 confidence
```

**為什麼不是 client 線性算？** 用戶換食材 macro 結構完全變；用戶改份量超過 ±50% 視覺感受可能不對（眼錯 vs AI 對），讓 AI 二次看圖+hint 校正。

### 5.2 Calibration prompt injection

recognize / refine 都帶 user_calibration（top-10 常 correct 食物的 bias）：

```
SYSTEM: ...你是食物估算助手...
USER calibration hints (基於妳過去修正):
- 白飯：portion 通常實際比估的少 15%
- 雞腿：估的偏輕，實際多 10%
- 高麗菜：常被認成大白菜
請估算時參考這些 hint。
```

→ 每次 recognize/refine 都拉 user_calibration（cache 15min）。樣本數 < 3 不下 hint。

### 5.3 stub mode（測試 + AI 掛掉）

`/v1/vision/refine` 在 `STUB_MODE=true` 時：套 user_hint 直接回（portion_multiplier × 原 dish kcal/macros），不呼叫 Anthropic。

---

## 6. Frontend 變更

| 元件 | 規格 |
|---|---|
| `MealResultMultiDish.vue` | 新版結果頁（取代既有 single-result）；render dishes list + 總計 row + 朵朵 chip |
| `DishCorrectionSheet.vue` | bottom sheet；slider + food candidate list + manual override + refine button |
| `PortionSlider.vue` | 0.25-3.0× snap slider；@change 即時 emit recalculated macro（local linear） |
| `FoodCandidatePicker.vue` | tap food name → 展開 top-5 candidates + 「找不到 → 手打」 |
| `AddDishSheet.vue` | manual add；含食物 fuzzy search + macro AI 估 button |
| `ConfidenceChip.vue` | 🟢 / 🟡 / 🔴 三色 + i18n label |
| 朵朵點評 | 修正後若總 kcal 變化 > 20% → 重新拉一句點評（一次 AI call） |

**離線 fallback**：滑桿純 local，無需網路；refine button 在離線時 disabled + 提示「連線後可用 AI 重新估」。

---

## 7. 訂閱 Gating

| 功能 | Free | Paid |
|---|---|---|
| 整盤辨識（既有 quota 3/day） | ✅ | ✅ unlimited |
| Per-dish 滑桿份量調整 | ✅ unlimited | ✅ |
| Per-dish 換食材（從候選） | ✅ unlimited | ✅ |
| 手動加 dish | ✅ unlimited | ✅ |
| **AI refine（二次推論）** | 包含在 3/day quota（refine 不額外扣，但若用完 quota 後新拍照沒額度，refine 也跟著沒） | ✅ unlimited |
| **學習回饋（calibration）** | ❌ 不啟用（成本控） | ✅ 啟用 |
| Confidence chip 顯示 | ✅ | ✅ |

→ 修正體驗本身**不卡 paywall**（核心信任機制不能 gate）；學習回饋是 paid 差異化（長期準確度提升）。

---

## 8. 食安法合規

修正流程的 user input + AI output 都過 `LegalContentSanitizer`（集團硬規則）：

| 點 | 檢查 |
|---|---|
| 食物名手打 input | sanitize（用戶可能寫「減肥便當」「燃脂奶昔」← 阻擋寫入） |
| AI 朵朵點評 | sanitize（保險起見即使 prompt 鎖了） |
| 食物候選 list（ai-service 回） | sanitize（候選名稱不能含「療效 / 排毒」等） |

違規詞清單：CLAUDE.md §7 + `packages/pandora-shared/Compliance/LegalContentSanitizer`。

CI guard：新增 `tests/Feature/Compliance/MealCorrectionContentGuardTest.php`，把 dish names / 朵朵 outputs 跑過 sanitizer 全 pass。

---

## 9. 驗收條件

### Backend
- [ ] migration 跑通 + rollback 安全
- [ ] `MealCorrectionService::applyDishCorrection` happy + edge（dish 不屬於 user → 403）
- [ ] `refineDishViaAi` ai-service 掛 → 返 fallback dish + log warning，不 raise 5xx
- [ ] 6 個 endpoint feature test happy + 401 + cross-tenant
- [ ] meal totals 自動 recalc（dishes update → meal kcal/carb/protein/fat sync）
- [ ] FoodCorrection log 寫入正確（4 種 correction_type）
- [ ] `userCalibrationFor` 樣本 ≥ 3 才返 bias
- [ ] Pest 全綠 + phpstan clean + ComplianceContentGuardTest pass

### ai-service
- [ ] `/v1/vision/refine` happy + stub_mode + multi-dish only-refine-target
- [ ] calibration hint injection（樣本 ≥ 3）
- [ ] cost guard 對 refine 計費
- [ ] pytest 全綠

### Frontend
- [ ] 4 個新元件 unit / interaction test
- [ ] 滑桿動 → macro 即時更新（< 16ms frame）
- [ ] refine 離線 → button disabled + tooltip
- [ ] 「漏了什麼」add → 總計 recalculate
- [ ] e2e smoke：拍照 → 改 1 dish 份量 → refine → 確認
- [ ] e2e smoke：拍照 → 換 1 dish 食材 → refine → 確認
- [ ] e2e smoke：拍照 → +1 dish → 確認

### 量化指標（上線後追蹤）
- [ ] 修正完成率 > 70%（用戶 tap 「不對」後完成修正而非放棄）
- [ ] 平均修正時間 < 15s
- [ ] 修正後 macro 誤差（vs 真實）< 15%（抽樣 100 筆 vs 食物資料庫真值）
- [ ] D30 留存 +2pp（vs v1 baseline）

---

## 10. PR 切片建議

| PR | 範圍 | 依賴 |
|---|---|---|
| **#1 backend migration + model + service** | meal_dishes / food_corrections schema + MealCorrectionService 主邏輯 + Pest | 無 |
| **#2 backend endpoints + scan 改 response** | 6 endpoints + AiMealController::scan 結構改 dishes[] | #1 |
| **#3 ai-service refine + calibration** | `/v1/vision/refine` + calibration injection + pytest + stub_mode | #1（schema 對齊） |
| **#4 frontend correction UI** | 4 新元件 + result page rewrite + e2e | #2 + #3 |

平行可能：#1 結束後 #2 / #3 可平行；#4 等 #2 + #3。

---

## 11. 預估工時

| 區塊 | 工時 |
|---|---|
| #1 Backend schema + service + tests | 2 天 |
| #2 Backend endpoints + scan response | 1.5 天 |
| #3 ai-service refine + calibration + pytest | 2 天 |
| #4 Frontend 4 元件 + result page + e2e | 4 天 |
| **合計** | **9.5 天**（單人 backend / Python / frontend 串行；2 人可省 3 天） |

---

## 12. 不做（Out of Scope）

- ❌ Per-dish 拍照（目前一張圖辨識多 dish 已夠；per-dish 拍是另一條 UX 線）
- ❌ AI 看用戶歷史照片做風格學習（成本爆 + 隱私）
- ❌ 食物資料庫 CRUD（用 fuzzy search + AI fallback）
- ❌ Voice 修正（「改成 1.5 碗白飯」語音）— 留 v3
- ❌ Confidence 校準走 ML model retraining（calibration 用 prompt hint 已夠）

---

## 13. 風險

| 風險 | 緩解 |
|---|---|
| AI refine 次數爆 → 成本爆 | refine 共用 3/day quota（不額外扣但也不無限）；Paid 才 unlimited |
| 滑桿改 portion 後線性算 macro 不準 | ±50% 內線性（夠準）；超過 ±50% 強制 trigger refine button hint |
| 用戶亂手打食物名 → AI fuzzy search 失敗 → macro 全 0 | 強制至少選一個分類（主食/主菜/配菜...）+ 預設 50 kcal 兜底 |
| Calibration prompt injection 被 prompt-poison | calibration 只當 hint，prompt 結構固定，user input 不直接拼到 system prompt |
| Multi-dish 結果頁 UI 太複雜 | dishes < 3 時用 compact 視覺；≥ 3 時 collapse 細節，tap 展開 |

---

## 14. 後續鉤子（不在本 SPEC）

- **食物相簿 1-tap 重複記錄**（v1 mentioned 但沒做）
- **Voice 修正**（「改成 1.5 碗白飯」）
- **Per-meal 朵朵深度點評**（修正完整版整餐後 trigger 一次長 narrative）
- **跨餐 calibration**：calibration 不只看食物，看時段（早餐 vs 晚餐 portion 差）
