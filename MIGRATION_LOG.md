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

## Phase A · Week 2（待做）

- [ ] **API 層**：第一條 Fastify route 翻 Laravel — 建議從 `daily_logs` GET/POST 起
- [ ] Sanctum 發 token 的 endpoint
- [ ] 對齊既有 RN App endpoint 契約
- [ ] 翻 `../ai-game/src/services/` 中的 business logic 進 `app/Services/`
- [ ] 把 `../ai-game/data/*.json` × 7 包進 Seeder
- [ ] Filament Resource 美化
- [ ] CI workflow（GitHub Actions）— migrate test + Pest

---

## ⚠️ 已知 Phase 邊界

- **Phase B**（Python AI service）需先決 framework（FastAPI 推薦）+ infra
- **Phase C** 卡 ADR-001 (Pandora Core) 尚未啟動 — 朵朵 Phase C 設計成「可降級為自有 Identity」
- **Phase D-G** 牽涉 RN/iOS build / Apple Developer / ECPay / IAP，需人類介入
- **目錄並列策略**：`../ai-game/`（Node 舊版）保留至 Phase G 上架成功 + 1 個月後再評估刪除

---

## 重啟 ADR-004 共用 packages 的觸發點

ADR-004 / ADR-005 標 Deferred 中。當朵朵這邊 Phase A 完成、實際出現需要 `use Pandora\…`（如 IndexNow、Discord webhook、Achievement subject）的需求時，就是重啟 ADR-004 的時機。
