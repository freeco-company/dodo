# 朵朵 Dodo Node→Laravel 遷移日誌

> 依據 [ADR-002](../ai-game/docs/adr/ADR-002-dodo-laravel-python.md) 的 10 週遷移計畫。
> 舊 Node + TS + Fastify 版本完整保留在 [../ai-game/](../ai-game/)，本目錄是並列的 Laravel 13 重寫版。
> 兩個目錄會並存到 Phase G 上架前後切換，不互相覆蓋。

---

## Phase A · Week 1 · Day 1（2026-04-28）✅

### 已完成

- [x] `composer create-project laravel/laravel "^13.0"` — Laravel **13.6.0**
- [x] `composer require filament/filament:^5.0` — Filament **5.6.1**
- [x] `composer require laravel/sanctum` — Sanctum **4.3.1**（與母艦 4.3.1 對齊）
- [x] `php artisan filament:install --panels` — admin panel 已掛 `/admin`
- [x] Sanctum migration + config 已 publish
- [x] `php artisan migrate` — users / cache / jobs / sessions / personal_access_tokens 全綠
- [x] `.env.example` 改 `APP_NAME="Dodo (朵朵)"`、預設 `DB_CONNECTION=mysql`（MariaDB）
- [x] Smoke test：`php artisan serve` → `/` 200、`/admin/login` 200
- [x] 初始 admin user：`admin@dodo.local` / `dodo-admin-2026`（Filament `/admin` 可登入）

### 環境一致性對母艦

| 元件 | 母艦 (pandora.js-store) | 朵朵 (ai-game-laravel) | 對齊 |
|------|------|------|------|
| Laravel | 13.4.0 | 13.6.0 | ✅ minor 差距，正常 |
| PHP | ^8.3 | 8.3.27 | ✅ |
| Filament | 5.5.0 | 5.6.1 | ✅ minor 差距 |
| Sanctum | 4.3.1 | 4.3.1 | ✅ 完全一致 |

### 暫未做（依設計）

- DB 暫用 sqlite（`.env` 預設）— 待 MariaDB 本機/staging 開好再切 mysql
- 未 hook 集團 `packages/pandora-*` — ADR-004 仍 Deferred，Phase A 完成後評估
- 未 git init — 等目錄結構穩、PG-XXX ticket 開好再 init + first commit

---

## Phase A · Week 1 · 剩餘（待你拍板再做）

- [ ] 把 `../ai-game/src/db/schema.sql`（20+ tables）翻成 Laravel migrations
- [ ] 對應 Eloquent models + factories
- [ ] 種子資料：`../ai-game/data/*.json` × 7 個（intents / decks / npcs / scenes / story / voices）
- [ ] 第一個 Filament Resource：User CRUD（驗證 admin panel 可用）
- [ ] Pest test setup（取代 Vitest）

## Phase A · Week 2

- [ ] 翻第一條 Fastify route → Laravel controller（建議從 user 或 daily_logs 起）
- [ ] API token via Sanctum
- [ ] 對齊既有 RN App 的 endpoint 契約（避免 client 改動）

---

## ⚠️ 已知 Phase 邊界

- **Phase B**（Python AI service）需先決 framework（FastAPI 推薦）+ infra
- **Phase C** 卡 ADR-001 (Pandora Core) 尚未啟動 — 朵朵 Phase C 設計成「可降級為自有 Identity」（ADR-002 §6 已寫 mitigation）
- **Phase D-G** 牽涉 RN/iOS build / Apple Developer / ECPay / IAP 設定，需人類介入
- **目錄並列策略**：`../ai-game/`（Node 舊版）保留至 Phase G 上架成功 + 1 個月後再評估刪除

---

## 重啟 ADR-004 共用 packages 的觸發點

ADR-004 / ADR-005 標 Deferred 中。當朵朵這邊 Phase A 完成、實際出現需要 `use Pandora\…`（如 IndexNow、Discord webhook、Achievement subject）的需求時，就是重啟 ADR-004 的時機。

預計時點：Phase A Week 2 結束 ~ Phase E（訂閱整合）開始之間。
