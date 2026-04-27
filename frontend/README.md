# 朵朵 Dodo 前端（Capacitor + 純 web bundle）

> ⚠️ 2026-04-28 從 [`../../ai-game/`](../../ai-game/) **複製**過來（不是移動）。
> 原版仍在 `ai-game/`，等 Phase G 上架成功 + 1 個月後再評估從 ai-game/ 刪除（依 ADR-002）。
>
> 本目錄目前是「並列複本 + 待調整 API endpoint」的狀態，**還沒驗證可 build 出新 iOS bundle**。

---

## 目錄

```
frontend/
├── capacitor.config.json   ← appId com.jerosse.doudou (與舊版相同)
├── package.json            ← 只裝 Capacitor + http-server，不再含 vitest / 後端 deps
├── public/                 ← 純 web bundle (vanilla JS + HTML + 42 個檔案)
│   ├── index.html
│   ├── app.js              ← 主 entry
│   ├── character.js / config.js / icons.js / sound.js
│   └── ...
└── ios/                    ← Capacitor iOS Xcode 專案 (App/, capacitor-cordova-ios-plugins/)
```

---

## 與 dodo/backend 的整合

舊版 ai-game 是 **Fastify 同 process 同時 serve `/public/*` static 與 `/api/*` JSON**。
搬到 dodo 之後：

- **後端**：`../backend/`（Laravel 13）只 serve `/api/*`，不再服務 web bundle
- **前端**：`./public/` 由 Capacitor 打包進 iOS app；本機開發可用 `npm run serve` 跑 localhost:5173
- **API endpoint**：`public/config.js` 或 `app.js` 內的 API base URL 必須改指向 Laravel

⚠️ **下一個 session 必須做**：
1. grep `public/` 找所有寫死的 `/api/...` 呼叫，確認契約對得上 dodo/backend 的 12 條新 endpoint
2. 把 `localhost:8080`（舊 Fastify）改成 `localhost:8000`（Laravel `php artisan serve`）或環境變數
3. 把舊版 LINE / Apple OAuth 流程改成 Sanctum register/login（或保留並讓 backend 接 OAuth callback）
4. `cap sync ios` → 開 Xcode 驗證 build 跑得起來

---

## 執行指令

```bash
cd dodo/frontend
npm install                    # 裝 Capacitor + http-server
npm run serve                  # 本機開 http://localhost:5173 看 web bundle

# iOS（需 macOS + Xcode）
npm run cap:sync:ios
npm run cap:open:ios           # 開啟 Xcode
```

---

## 為什麼是「複製」不是「移動」

- ai-game/ios 的 Xcode 專案有絕對路徑 reference，貿然刪除會破壞舊版 build
- ai-game/ 仍是「上 production 前的可運作參考」（依 ADR-002）
- 集團 CLAUDE.md 寫「目錄並列策略：ai-game 保留至 Phase G 上架成功 + 1 個月」
- 本目錄做完 endpoint 調整、cap sync 跑通、產出新 iOS build 後，再從 ai-game/ 刪除原版

---

## 與 pandora.js-store 結構對齊

母艦 `pandora.js-store/` 是 `backend/` + `frontend/` 並列。本 repo 同樣 `backend/` + `frontend/`。
這樣集團工具鏈、agent prompts、CI workflow 都能套用一致 pattern。
