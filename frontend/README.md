# 朵朵 Dodo 前端（Capacitor + 純 web bundle）

> ⚠️ 2026-04-28 從 [`../../ai-game/`](../../ai-game/) **複製**過來。原版仍在 `ai-game/`，
> 等 Phase G 上架成功 + 1 個月後再評估從 `ai-game/` 刪除（依 ADR-002）。

---

## 目錄

```
frontend/
├── capacitor.config.json   ← appId com.jerosse.pandora.meal (與舊版相同)
├── package.json            ← Capacitor + http-server，不再含後端 deps
├── public/                 ← 純 web bundle (vanilla JS + HTML)
│   ├── index.html
│   ├── config.js           ← 解析 window.DODO_API_BASE（單一 source of truth）
│   ├── app.js              ← 主 entry，所有 fetch 都走 const API
│   ├── character.js / icons.js / sound.js / style.css
│   └── ...
└── ios/                    ← Capacitor iOS Xcode 專案（user 之後 cap sync）
```

---

## API base URL

**Single source of truth**：`window.DODO_API_BASE`（在 `public/config.js` 解析）。
所有 `fetch()` 呼叫一律 `fetch(API + path, ...)`，其中 `const API = window.DODO_API_BASE || ...`（見 `app.js`）。

**解析優先順序**（第一個非空值勝出）：

1. `?api=...` query string（會寫進 `sessionStorage` 持久化到下次刷新）
2. `window.DODO_API_BASE` 在 `index.html` 顯式設定
3. `window.DOUDOU_API_BASE`（legacy，向後相容）
4. 自動判斷：
   - Capacitor / `file://` shell → `PROD_API` 常數（`config.js` 內）
   - `localhost` / `127.0.0.1` → `http://localhost:8765/api`
   - 其他 web origin → `/api`（同 origin）

**production 部署**：
- 在 `config.js` 改 `PROD_API` 常數，或
- 在 build 時在 `index.html` `<head>` 加 `<script>window.DODO_API_BASE = 'https://...'</script>`

---

## 本機開發流程

```bash
# terminal 1 — Laravel backend
cd /Users/chris/freeco/pandora/dodo/backend
php artisan serve --port=8765

# terminal 2 — frontend static server
cd /Users/chris/freeco/pandora/dodo/frontend
npx http-server public -p 5173 -c-1
#   或：python3 -m http.server 5173 --directory public

# 瀏覽器
open http://localhost:5173/                 # 主 app
# API smoke test (dev-only, NOT served in prod — moved out of public/):
#   開另一個 terminal: npx http-server frontend/dev -p 5174 -c-1
#   open http://localhost:5174/dev-smoke.html
# 想覆寫 base：http://localhost:5173/?api=http://localhost:9000/api
```

`config.js` 偵測 `localhost` 時會自動指向 `http://localhost:8765/api`，所以一般情況下不用帶 `?api=`。

---

## CORS

前端 `:5173` ↔ 後端 `:8765` 是跨 origin。後端 `backend/config/cors.php` 已加：

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_origins' => [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:5174',
    'http://localhost:8080',
],
'allowed_headers' => ['*'],   // 包含 Authorization、Content-Type
```

production 上線前要把 `allowed_origins` 收緊到實際的 web origin（Capacitor 走 `file://` / `capacitor://` 不觸發瀏覽器 CORS，可不列）。

---

## Auth（Sanctum bearer）

- token 從 `POST /api/auth/login` 或 `POST /api/auth/register` 拿，存 `localStorage.doudou_token`
- 所有 `fetch` 透過 `app.js` 的 `api()` helper 自動帶 `Authorization: Bearer ...`
- 收到 `401` 時 `api()` helper 會自動清掉 stale token（`doudou_user` / `doudou_token`），下次 render 由 SPA 自己處理 onboarding / login

OAuth（LINE / Apple Sign-In）目前**前端沒程式碼**，等 [Pandora Core Identity](../../docs/adr/ADR-001-identity-service.md) ship 後再加 SSO bootstrap module（`MIGRATION_NOTES.md` 有規劃）。

---

## Endpoint mapping（Fastify → Laravel）

詳見 [`MIGRATION_NOTES.md`](MIGRATION_NOTES.md)。摘要：

- ✅ 已對應的 endpoint：56 條 Laravel routes 涵蓋 bootstrap / auth / meals / cards / checkin / quests / chat / paywall / referral / push / analytics / account
- ⚠️ 已知 TODO（前端會打但 Laravel 還沒）：
  - `GET /api/foods/search`（Phase B+ 手動食物 picker）
  - `POST /api/cards/event-draw`、`event-skip`、`event-offer/...`、`scene-draw`（Phase B+ NPC / 劇情卡）

---

## SSE / streaming / 上傳

目前前端沒用 `EventSource`、`ReadableStream`、`multipart/form-data`：

- chat 用 `POST /api/chat/message` 一次回一段（非 streaming）
- meals/scan 用 JSON `{ image_base64 }` 而不是 multipart
- analytics / client-errors 是 fire-and-forget JSON POST

未來若改 streaming（chat 邊打邊吐），不能用 `EventSource`（無法帶 `Authorization` header），要改 `fetch + ReadableStream + TextDecoder`。

---

## 執行指令

```bash
cd /Users/chris/freeco/pandora/dodo/frontend
npm install                    # 裝 Capacitor + http-server
npx http-server public -p 5173 # 本機 web 預覽

# iOS（需 macOS + Xcode）— 由 user 之後執行
npx cap sync ios
npx cap open ios
```

---

## 為什麼是「複製」不是「移動」

- ai-game/ios 的 Xcode 專案有絕對路徑 reference，貿然刪除會破壞舊版 build
- ai-game/ 仍是「上 production 前的可運作參考」（依 ADR-002）
- 集團 CLAUDE.md：「目錄並列策略：ai-game 保留至 Phase G 上架成功 + 1 個月」
- 本目錄做完 endpoint 調整、cap sync 跑通、產出新 iOS build 後，再從 ai-game/ 刪除原版
