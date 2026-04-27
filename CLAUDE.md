# CLAUDE.md — 朵朵 Dodo（潘朵拉集團 AI 飲食教練 App）

> 你（Claude / 任一 AI agent）在這個**子專案（朵朵）**工作時的指導文件。
> Claude Code 會自動載入此檔；同時會載入父層 [`../CLAUDE.md`](../CLAUDE.md)（**Pandora 集團憲法**）。
>
> **優先順序**：本檔（朵朵專屬）> 父層集團憲法 > 全域 `~/.claude/CLAUDE.md`
>
> **集團共用資源**（不在本 repo，已抽到父層）：
> - 集團策略：[`../docs/`](../docs/)（vision / spec / products / screens）
> - 集團 ADR：[`../docs/adr/`](../docs/adr/) — 重點看 ADR-001（Identity）、ADR-003（愛用者→加盟轉換）、ADR-004（共用 composer package，目前 Deferred）
> - 朵朵專屬 ADR：[`../ai-game/docs/adr/ADR-002-dodo-laravel-python.md`](../ai-game/docs/adr/ADR-002-dodo-laravel-python.md) — 本 repo 的根本依據
> - 共用 18 個 agent：[`../.claude/agents/`](../.claude/agents/)
>
> **朵朵在集團裡的角色**：第二個產品線（婕樂纖之後），AI 含金量服務帶訂閱 ARR + 把愛用者推進加盟漏斗。

---

## 🎯 朵朵是什麼

| 項目 | 內容 |
|---|---|
| 定位 | AI 飲食教練 + 遊戲化養成 + 拍照食物辨識 |
| TA | 25-40 歲台灣女性減脂/體態管理 |
| 商業模式 | 訂閱制（Free / Monthly NT$290 / Yearly / VIP） |
| 通路 | App Store / Google Play / LINE Bot / Web demo |
| 北極星指標 | D30 留存率 > 35% |
| Guard rails | 月平均 AI 成本 < NT$80/活躍用戶；食物辨識準確率 > 85% |

---

## 🏗️ Repo 結構

```
dodo/
├── CLAUDE.md          ← 你正在看的這個
├── MIGRATION_LOG.md   ← Node→Laravel 遷移進度
├── backend/           ← Laravel 13 主棧（本 repo 主體）
└── frontend/          ← Capacitor + 純 web bundle（2026-04-28 從 ai-game/ 複製）
```

**舊 Node 版本** 並列保留在 [`../ai-game/`](../ai-game/) 至 Phase G 上架成功 + 1 個月後再評估刪除。

**前端狀態**：`frontend/public/` + `frontend/ios/` 是從 `../ai-game/` **複製**過來的，API 呼叫還寫死指向舊 Fastify。下個 session 必須先把 endpoint 對接 dodo/backend 才能 cap sync 出新 iOS build。詳見 [`frontend/README.md`](frontend/README.md) 與根層 [`../HANDOFF.md`](../HANDOFF.md)。

---

## ⚙️ 技術棧（與母艦 pandora.js-store 對齊）

| 元件 | 版本 | 對齊母艦 |
|------|------|------|
| Laravel | 13.6.0 | ✅ (母艦 13.4.0) |
| PHP | ^8.3 | ✅ |
| Filament | 5.6.1 | ✅ (母艦 5.5.0) |
| Sanctum | 4.3.1 | ✅ 完全一致 |
| Database | MariaDB 12 | ✅ (母艦同棧) |

---

## 🚧 當前狀態

ADR-002 §4 Phase A 進行中（Week 1）：
- ✅ Day 1：skeleton + Filament + Sanctum + MariaDB
- ⏳ Day 2+：把 `../ai-game/src/db/schema.sql`（20+ tables）翻成 Laravel migrations + Eloquent models
- ⏳ Week 2：第一條 Fastify route → Laravel controller

詳見 [MIGRATION_LOG.md](MIGRATION_LOG.md)。

---

## 🔑 朵朵專屬原則

1. **集團一致性優先於朵朵獨特性**：技術選型遇到分歧時，先看母艦怎麼做（同 stack 才能享受共用 packages 的乘數效應）
2. **AI 互動走 Python 微服務**：Laravel 不直接 call Anthropic API，而是 HTTP proxy 給 Python service（ADR-002 §3）
3. **Pandora Core Identity 還沒做**：朵朵 Phase C 設計成「可降級為自有 Identity」，現階段先用本 repo `users` 表
4. **「諮詢加盟」入口要埋設**：依集團原則，所有 App 必須有把使用者導向婕樂纖加盟漏斗的設計（Premium 升級頁、學院 banner 等）

---

## 📋 Jira / Branch / Commit

- Jira Key：`PG`（集團共用）— **本 repo 暫不強制 Jira** 直到上 production
- 開發追蹤：先用 GitHub issue（`freeco-company/dodo`）
- Branch 規範依父層 `~/.claude/CLAUDE.md`

---

> 📅 最後更新：2026-04-28
> 🏛️ 結構鏡像 pandora.js-store 的 backend/ + frontend/ 配置
