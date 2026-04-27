# 朵朵 Dodo Node→Laravel 遷移日誌

> 依據 [ADR-002](../ai-game/docs/adr/ADR-002-dodo-laravel-python.md) 的 10 週遷移計畫。
> 舊 Node + TS + Fastify 版本完整保留在 [../ai-game/](../ai-game/)，本目錄是並列的 Laravel 13 重寫版。
> 兩個目錄會並存到 Phase G 上架前後切換，不互相覆蓋。

---

## Phase A · Week 1 · Day 1（2026-04-28）✅

### 已完成 — Skeleton

- [x] `composer create-project laravel/laravel "^13.0"` — Laravel **13.6.0**
- [x] `composer require filament/filament:^5.0` — Filament **5.6.1**
- [x] `composer require laravel/sanctum` — Sanctum **4.3.1**
- [x] `php artisan filament:install --panels` — admin panel 已掛 `/admin`
- [x] Sanctum migration + config publish
- [x] 預設 `.env.example` 改為 MariaDB
- [x] 新建 MariaDB schema `dodo`（與母艦同 host / 同 root）
- [x] git init + first commit + push 到 [`freeco-company/dodo`](https://github.com/freeco-company/dodo)
- [x] Phase A tracking issue [#1](https://github.com/freeco-company/dodo/issues/1)

### 已完成 — Schema 移植（15 個 domain tables）

依 `../ai-game/src/db/schema.sql` 翻成 Laravel migrations，全用 MariaDB 12 慣用型別：

| 來源 (SQLite) | Laravel migration | 模型 | 工廠 |
|---|---|---|---|
| `users` | `0001_01_01_000000_create_users_table.php`（重寫）| `User`（55+ 欄位）| ✅ |
| `food_database` | `2026_04_28_100001` | `Food`（`$table='food_database'`）| ✅ |
| `daily_logs` | `2026_04_28_100002` | `DailyLog` | ✅ |
| `meals` | `2026_04_28_100003` | `Meal` | ✅ |
| `conversations` | `2026_04_28_100004` | `Conversation` | ✅ |
| `user_summaries` | `2026_04_28_100005` | `UserSummary`（PK = user_id）| — |
| `weekly_reports` | `2026_04_28_100006` | `WeeklyReport` | — |
| `achievements` | `2026_04_28_100007` | `Achievement` | ✅ |
| `food_discoveries` | `2026_04_28_100008` | `FoodDiscovery` | — |
| `usage_log` → `usage_logs` | `2026_04_28_100009` | `UsageLog` | — |
| `card_plays` | `2026_04_28_100010` | `CardPlay` | — |
| `card_event_offers` | `2026_04_28_100011` | `CardEventOffer` | — |
| `daily_quests` | `2026_04_28_100012` | `DailyQuest` | — |
| `store_visits` | `2026_04_28_100013` | `StoreVisit` | — |
| `journey_advances` | `2026_04_28_100014` | `JourneyAdvance` | — |

#### 重要差異 vs 舊 Node 版

1. **Primary key**：Doudou 用 TEXT id（UUID/legacy），新版改用 Laravel 標準 `bigint auto_increment`，每張表都有 `legacy_id` 欄位保留舊 TEXT id 供未來 ETL 對映
2. **JSON 欄位**：原 SQLite 用 TEXT 存 JSON，新版用 MariaDB `json` 型別 + Eloquent `array` cast
3. **Boolean 欄位**：原 SQLite 用 INTEGER 0/1（`verified` / `is_shiny` / `user_corrected` / `correct`），新版改 `boolean` 並 cast
4. **timestamps**：原表混用 `TEXT date('now')`，新版統一 `timestamps()`
5. **table 名稱**：`usage_log` 改為 `usage_logs`（Laravel 慣例複數）
6. **password / email**：新增 `password` 與 `email_verified_at` 欄位讓 Filament admin 可登入；原 Doudou 沒這些欄位（純 OAuth via line_id / apple_id）

### 已完成 — Filament Admin Resources

由 `php artisan make:filament-resource <Model> --generate` 自動產生 CRUD：

- `/admin/users`、`/admin/meals`、`/admin/daily-logs`
- `/admin/food`、`/admin/achievements`、`/admin/conversations`

### 已完成 — Auth Gate

`User implements FilamentUser`，`canAccessPanel()` 規則：
- `membership_tier === 'fp_lifetime'`，OR
- email 結尾 `@dodo.local` / `@packageplus-tw.com`

其餘使用者進 `/admin` 會被 403。

### 已完成 — Pest Test Suite

- `composer require --dev pestphp/pest pestphp/pest-plugin-laravel` (Pest 4.6)
- `./vendor/bin/pest --init`
- `tests/Feature/SchemaSmokeTest.php` — 9 tests
- `tests/Feature/AdminPanelTest.php` — 4 tests

**結果：15 passed (60 assertions)** ✅

---

## 環境一致性對母艦

| 元件 | 母艦 (pandora.js-store) | 朵朵 (本 repo) | 對齊 |
|------|------|------|------|
| Laravel | 13.4.0 | 13.6.0 | ✅ |
| PHP | ^8.3 | 8.3.27 | ✅ |
| Filament | 5.5.0 | 5.6.1 | ✅ |
| Sanctum | 4.3.1 | 4.3.1 | ✅ |
| Database | MariaDB 12 | MariaDB 12 | ✅ |
| Test framework | Pest | Pest 4.6 | ✅ |

---

## Phase A · Week 2 · Day 1（2026-04-28 凌晨）✅

### 已完成 — API 層 12 條 endpoint

| Method | Path | Auth | 來源 |
|--------|------|------|------|
| GET | /api/health | — | /api/health |
| POST | /api/auth/register | — | /api/auth/register |
| POST | /api/auth/login | — | (新) |
| POST | /api/auth/logout | sanctum | (新) |
| GET | /api/me | sanctum | /api/auth/me |
| GET | /api/daily-logs | sanctum | /api/daily-logs |
| POST | /api/daily-logs | sanctum | upsert by date |
| GET | /api/daily-logs/{date} | sanctum | /api/daily-logs/:date |
| GET | /api/meals | sanctum | /api/meals |
| POST | /api/meals | sanctum | /api/meals |
| GET | /api/meals/{id} | sanctum | (新) |
| DELETE | /api/meals/{id} | sanctum | (新) |

### 已完成 — Sanctum API 認證
- `php artisan install:api`
- `bootstrap/app.php` 加 `api: routes/api.php`
- `User` 已 `use HasApiTokens`

### 已完成 — JSON Resources
- `UserResource`、`DailyLogResource`、`MealResource`
- 按 `docs/ai-context/api-format.md` 巢狀分組

### 已完成 — Service 層
- `App\Services\TargetCalculator` — 翻自 `ai-game/src/services/targets.ts`
- Mifflin-St Jeor BMR × activity factor × (1 - 減重赤字 0.15)

### 測試 — 36 passed (132 assertions) ✅
- 15 個 schema/admin（Day 1）
- 1 個 Health、7 個 Auth、6 個 DailyLog、7 個 Meal
- 多租戶隔離驗證（A 看不到 B 的 meals / daily_logs）

---

## Phase A · Week 2 · Day 2（2026-04-28）✅ — 一口氣補完 endpoint + seeders + CI

3 個 commit 一次到位（未 push，等人審）：
- `55736b6` feat: gamification endpoints + seeders (Batch A+B)
- `95d4fcf` feat: misc endpoints + tier + AI stubs (Batch C+D+E)
- `508ec21` chore: backend CI workflow + frontend API base URL config

### 已完成 — endpoints（從 12 → 56 條 API routes）

| 區塊 | 條數 | TS source |
|---|---|---|
| 遊戲化 checkin / journey / interact / shield / cards / quests / meta / lore | 21 | checkin/journey/interact/shield/cards/quests/scoring/game/lore.ts |
| referrals / paywall / account / rating-prompt / analytics / push / bootstrap / seo / client-errors / sitemap | 16 | referrals/paywall/account_deletion/rating_prompt/analytics/notifications/push/seo/app_config.ts |
| tier / admin/tier / webhooks/ecommerce/order / subscribe/mock | 4 | tier/trial/entitlements.ts（webhook signature 待補）|
| AI stubs（meals/scan, meals/text, chat/message → 503；chat/starters 真實）| 4 | chat_starters.ts（其餘等 Phase B Python service）|

### 已完成 — Service 層（共 22 支）
新增：GameXp、ScoringService、JourneyService、CheckinService、ShieldService、InteractService、CardService、QuestService、TrialService、ReferralService、AccountDeletionService、PaywallService、RatingPromptService、AnalyticsService、PushService、AppConfigService、EntitlementsService、TierService、SeoService、ChatStarterService、AiServiceClient（+ Exception）、AdminTokenAuth middleware

### 已完成 — Seeders
- 8 份 JSON（chat_intents / island_scenes / journey_story / knowledge_decks / mascot_voices / npc_dialogs / question_decks / store_intents）→ `database/seed/` → `app_config` 表
- `AppConfigSeeder` 通用 loader，runtime-editable
- `CardEventOfferSeeder` 刻意 no-op（內含 ADR docblock：解釋為何不展平卡牌到 `card_event_offers`）
- CardService `draw()` 真的能從 seeded `app_config.question_decks` 抽牌（已測）
- QuestService 改讀 `app_config.quest_definitions`，fallback inline pool

### 已完成 — Migrations（+ 9 支）
analytics_events / push_tokens / referrals / app_config / seo_metas / client_errors / rating_prompt_events / paywall_events / users 加 deletion + referral_code + push_enabled + trial_* 欄位

### 已完成 — CI
- `.github/workflows/backend-ci.yml`：PHP 8.3 + sqlite in-memory + composer + migrate:fresh --seed + `php artisan test`
- mariadb service template 保留註解，schema diverge 時切換

### 已完成 — 前端 base URL
- `frontend/public/config.js` 改用 `window.DODO_API_BASE`，dev 預設 `http://localhost:8765/api`
- `frontend/MIGRATION_NOTES.md` 列出 endpoint 對照、4 個 TODO、OAuth 等 Pandora Core

### 測試 — 106 passed (334 assertions) ✅
- 36 既有（Day 1）+ 70 新增（gamification 26 + misc 42 + seeders 2）

---

## Phase A · 卡點（無法在後端內解）

- [ ] **Phase B Python AI service**：`AiServiceClient` 永遠 throw，等 ADR-002 §3 framework 拍板（推薦 FastAPI）+ infra
- [ ] **PostHog forwarding**：AnalyticsService::flush no-op，等 `POSTHOG_API_KEY`
- [ ] **FCM HTTP v1 push send**：PushService 只管 token 表，等 `FCM_SERVICE_ACCOUNT_JSON`
- [ ] **Webhook HMAC 簽章**：`POST /api/webhooks/ecommerce/order` 公開無驗證；**production 上線前必須補**
- [ ] **Apple IAP / ECPay callback**：`subscribe/mock` 為 dev 用；真實金流要金鑰
- [ ] **Pandora Core JWT**：朵朵目前 sanctum；ADR-001 上線後重 wire（朵朵 Phase C 設計成可降級）
- [ ] **Cards parity**：簡化版（combo bonus、rarity、FP recipe gating、scenario xp_mod 未做）— cards.ts 866 行只搬主幹
- [ ] **iOS build**：cd frontend && npm install && npx cap sync ios → Xcode 開（人類介入）
- [ ] **後端推進的 endpoint**：cards/event-draw、cards/event-skip、cards/event-offer/{id}、cards/scene-draw、foods/search（schema 已就緒、controller 缺 wiring）

### Filament Resource 美化（之後再做）
- [ ] form schema 從預設轉成業務友善版本

---

## ⚠️ 已知 Phase 邊界

- **Phase B**（Python AI service）需先決 framework（FastAPI 推薦）+ infra
- **Phase C** 卡 ADR-001 (Pandora Core) 尚未啟動 — 朵朵 Phase C 設計成「可降級為自有 Identity」
- **Phase D-G** 牽涉 RN/iOS build / Apple Developer / ECPay / IAP，需人類介入
- **目錄並列策略**：`../ai-game/`（Node 舊版）保留至 Phase G 上架成功 + 1 個月後再評估刪除

---

## 重啟 ADR-004 共用 packages 的觸發點

ADR-004 / ADR-005 標 Deferred 中。當朵朵這邊 Phase A 完成、實際出現需要 `use Pandora\…`（如 IndexNow、Discord webhook、Achievement subject）的需求時，就是重啟 ADR-004 的時機。
