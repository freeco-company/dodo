# SPEC：朵朵跨指標 Insight Engine v1

> 📅 起草：2026-05-03
> 🎯 目標：把已收進來的飲食 + 計步 + 斷食 + 體重 + 睡眠資料，從「各自打卡 +XP」升級成「跨指標朵朵敘事」(平台期偵測 / 睡眠×體重相關 / 斷食×活力相關)
> 📊 對標：Zero（斷食趨勢敘事）/ AutoSleep（睡眠 insight）/ Happy Scale（體重移動平均 + 平台期）/ Noom（行為 cue）/ Simple（GPT 教練式 insight）
> 💰 商業角色：**訂閱續訂主鉤子（Free 看不到深度 / Paid 有完整解讀）**
> 🔗 關聯：[SPEC-weekly-ai-report.md](SPEC-weekly-ai-report.md)（週報是 aggregator，本 SPEC 是 event-driven engine 餵給週報 / 推播 / chat）
> 🔗 關聯：[SPEC-fasting-redesign-v2.md](SPEC-fasting-redesign-v2.md)、[SPEC-healthkit-integration.md](SPEC-healthkit-integration.md)

---

## 1. 為什麼這是訂閱主鉤子

「健康行為 OS」的價值不在收齊指標（Apple Watch / 任一 tracker 都做得到），而在**整合敘事**。當前狀態：

| 現況 | 問題 |
|---|---|
| 體重每天打卡 → 數字記下、+5 XP | 沒解讀，trend 沒人看 |
| 睡眠資料來自 HK → 存在 health_metrics 但只在週報出現平均 | 沒事件性 insight |
| 斷食達標 → +10 XP + push「妳又達標了 ✨」 | 只看單指標 |
| 步數每天 8000+ → +5 XP | 同上 |

**競品做了什麼**：
- **Happy Scale**：7 天移動平均 + 平台期偵測（5 天平均 ±0.2kg = 平台），主動推「妳進入平台期了，以下是 3 個策略...」
- **AutoSleep**：「妳這週睡眠分數 78 / 平均下降 12%，可能跟咖啡因有關？」
- **Zero**：「妳已連續斷食 7 天 16:8，是時候試試 18:6 了」
- **Noom**：「妳每次焦慮都會吃糖 → 來看看替代行為」（pattern detection）

**meal 的差異化（vs 上面任一）**：跨指標 + 朵朵語氣（陪伴而非數據官腔）+ 不下醫療結論（合規）。

---

## 2. Insight 規則表（v1 上線 12 條）

每條規則 = (觸發條件) → (朵朵敘事) → (建議動作)。所有規則 **deterministic detection + AI narrative wrapping**（detection 不靠 LLM，避免 hallucinate；narrative wrapping 走 ai-service paid tier）。

| # | Insight key | 觸發條件 | 朵朵敘事範本（Free=固定/Paid=動態） | 建議動作 |
|---|---|---|---|---|
| 1 | `weight_plateau_detected` | 7 天移動平均 vs 前 7 天 ±0.2kg + 連續 5 天無變化 + 飲食卡路里 SD < 10% | 「妳的體重 5 天平台了 🌱 不是停滯，是身體在適應」 | 試試斷食日加散步 / 變動 macro 比例 |
| 2 | `weight_dropping_steady` | 7 天移動平均 -0.5kg ~ -1.5kg + 飲食達標 ≥ 5/7 天 | 「妳的節奏抓得超穩 ✨ 一週掉 X kg，剛剛好」 | 維持，不要太快 |
| 3 | `weight_dropping_too_fast` | 7 天移動平均 -1.5kg+ + 飲食卡路里 < 1200/天 ≥ 5 天 | 「掉太快了 🌱 可能是水分流失，記得補蛋白」 | 提高蛋白質目標 |
| 4 | `sleep_deficit_with_weight_stall` | 7 天平均睡眠 < 6h + 體重平台或上升 | 「妳這週睡眠少了一些，平台期可能跟皮質醇有關 🌙」 | 早 30 分鐘關螢幕 |
| 5 | `fasting_streak_with_steps_drop` | 斷食連勝 ≥ 7 天 + 步數比前 7 天降 ≥ 30% | 「斷食穩了，但活動掉了 💭 想試試斷食日加散步嗎？」 | 加 2000 步目標 |
| 6 | `fasting_breaking_late_night` | 過去 7 天有 ≥ 3 次破戒在 22:00 後 | 「妳常在晚上 10 點後吃 🌙 可能是壓力或睡前餓」 | 提早晚餐 / 高蛋白消夜 |
| 7 | `late_night_eating_pattern` | 7 天內 ≥ 4 次最後一餐在 21:00 後 | 「最近晚餐越來越晚 🌃 對睡眠不太友善」 | 提早 1 小時 |
| 8 | `protein_low_with_strength_decline` | 7 天平均蛋白質 < 1g/kg 體重 + 平台期同時出現 | 「蛋白質有點少 🥩 平台期更需要補」 | 提高蛋白質目標 +20g |
| 9 | `streak_milestone_30` | 任意 streak（飲食/斷食/步數）達 30 天 | 「30 天連勝 🌟 妳真的做到了」 | celebration（解 outfit + share card） |
| 10 | `weekend_drift_pattern` | 連 3 個週末平均卡路里超目標 ≥ 30% | 「週末妳會放鬆一下 🌱 沒關係，但要不要試試半放鬆？」 | 週末目標放寬 10%（而非無上限） |
| 11 | `consistency_high_no_weight_change` | 飲食達標 ≥ 6/7 天 + 步數達標 ≥ 5/7 + 體重 4 週 ±0.2kg | 「妳很努力但體重沒動 💭 可能熱量缺口算太保守」 | 重算 TDEE / 試 16:8 |
| 12 | `recovery_after_setback` | 前 1 週連勝中斷 + 本週重新達標 ≥ 3 天 | 「妳又回來了 ✨ 這比連勝更難」 | 朵朵手寫信（celebration）|

**規則新增成本**：每條規則 = 1 個 `InsightRule` class + unit test，半天搞定。v2 會擴到 30 條（含月經週期 × 食慾 / 旅遊變動 / 季節變化）。

---

## 3. 三個 insight 出口

### 3.1 朵朵 Chat 主動 surface（最即時）

每次用戶開朵朵 chat tab 時：
- 拉 `unread_insights` 表（過去 7 天 fired 但 user 還沒看的）
- 若有 → 朵朵第一句變成 insight narrative（取代制式問候）
- 用戶 tap insight bubble → mark as read + 展開「為什麼這樣建議」（show data chart）

```
朵朵 chat tab：
  [朵朵 avatar]
  「妳的體重 5 天平台了 🌱
   不是停滯，是身體在適應。
   想看看為什麼嗎？」
  [📊 看數據] [🌱 給我建議]
```

### 3.2 推播（事件型，最高開信）

| Insight | 推播時機 | 文案範本 |
|---|---|---|
| `weight_plateau_detected` | 偵測當天 09:00 | 「朵朵發現一件事 🌱 妳的體重 5 天平台了，要看看嗎？」 |
| `streak_milestone_30` | 觸發即時 | 「30 天連勝 🌟 妳真的做到了！朵朵幫妳準備了禮物」 |
| `weekend_drift_pattern` | 週四 18:00（提前提醒） | 「週末快到了 🌱 朵朵幫妳想了個小策略」 |

→ 每個 insight 一週只推 1 次（idempotent），避免轟炸。
→ Free 推 insight headline；Paid 推 + 內容預覽。

### 3.3 週報整合（補強既有 SPEC-weekly-ai-report）

週報內頁新增 **「本週 insight」** 區塊：
- list 過去 7 天 fired 的所有 insights（< 5 條 list 全部，≥ 5 條 highlight top 3）
- 每條一張 mini card：icon + headline + 查看 detail
- Paid only

---

## 4. 後端設計：InsightEngine

### 4.1 資料模型

**新 migration `2026_05_04_140000_create_insights_tables.php`**

```php
Schema::create('insights', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('insight_key', 64)->index();           // 'weight_plateau_detected'
    $t->string('idempotency_key')->unique();          // user_id:insight_key:YYYY-WW
    $t->json('detection_payload');                     // 觸發資料 snapshot
    $t->string('narrative_headline');                  // 朵朵語氣 1 句
    $t->text('narrative_body')->nullable();            // 朵朵語氣 50-150 字（Paid only）
    $t->json('action_suggestion');                     // [{label, action_key, deeplink}]
    $t->enum('source', ['rule_engine', 'ai_narrative_paid']);
    $t->timestamp('fired_at');
    $t->timestamp('read_at')->nullable();
    $t->timestamp('pushed_at')->nullable();
    $t->timestamps();

    $t->index(['user_id', 'fired_at']);
    $t->index(['user_id', 'read_at']);
});

Schema::create('insight_rule_runs', function (Blueprint $t) {
    $t->id();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('rule_key', 64);
    $t->date('eval_date');
    $t->boolean('triggered');
    $t->json('eval_context')->nullable();              // debug: 觸發條件當時的值
    $t->timestamps();

    $t->unique(['user_id', 'rule_key', 'eval_date']);
});
```

→ `insight_rule_runs` 用於 idempotent + debug（為什麼今天沒 fire？查表看條件值）。

### 4.2 Service 結構

```
app/Services/Insight/
├── InsightEngine.php                  # main entry
├── Rules/
│   ├── InsightRule.php                # abstract base
│   ├── WeightPlateauRule.php          # rule #1
│   ├── WeightDroppingSteadyRule.php   # rule #2
│   ├── ...                            # 12 rules total
│   └── RuleRegistry.php               # rule discovery
├── NarrativeRenderer.php              # Free=template / Paid=ai-service
├── InsightDispatcher.php              # 寫 insights 表 + dispatch 推播
└── UserDataAggregator.php             # 統一拉 7 天 / 30 天 metrics
```

**InsightRule contract**:

```php
abstract class InsightRule
{
    abstract public function key(): string;
    abstract public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult;
    abstract public function freeNarrativeTemplate(): string;
    abstract public function paidNarrativePromptHint(): string;
    abstract public function actionSuggestions(): array;
    public function cooldownDays(): int { return 7; } // idempotent gap
}
```

**InsightEngine entry**:

```php
class InsightEngine
{
    public function evaluateAllForUser(User $user, ?CarbonImmutable $now = null): array
    {
        $snapshot = $this->aggregator->snapshotFor($user, $now);
        $fired = [];
        foreach ($this->registry->all() as $rule) {
            if ($this->isInCooldown($user, $rule)) continue;
            $result = $rule->evaluate($user, $snapshot);
            if ($result) {
                $insight = $this->dispatcher->dispatch($user, $rule, $result);
                $fired[] = $insight;
            }
            $this->logRuleRun($user, $rule, $result);
        }
        return $fired;
    }
}
```

### 4.3 Schedule

| Cron | 行為 |
|---|---|
| `insights:evaluate-daily` 每天 08:00 Asia/Taipei | run InsightEngine for all active users (active = 過去 7 天有任一打卡) |
| `insights:evaluate-realtime` event-driven | 用戶完成飲食記錄 / 體重打卡 / 斷食結束時，evaluate 1 次（給 milestone insight 即時觸發） |
| `insights:cleanup` 每週日 03:00 | 清理 90 天前的 insight_rule_runs（避免表爆） |

→ realtime evaluate 走 dispatchAfterCommit job，不阻塞 user request。

### 4.4 Endpoints

| Method | Path | 說明 |
|---|---|---|
| GET | `/api/insights/unread` | 拉 user 未讀 insights（朵朵 chat tab 用） |
| POST | `/api/insights/{insight}/read` | mark as read |
| GET | `/api/insights/history` | 看歷史 insights（paginate） |
| POST | `/api/insights/{insight}/dismiss` | 用戶不感興趣（影響後續 cooldown 翻倍） |
| GET | `/api/insights/{insight}/detail` | insight 詳細（含 detection_payload chart 資料） |

---

## 5. ai-service 變更

### 5.1 新 NarrativeKind: `cross_metric_insight`

```python
class NarrativeKind(str, Enum):
    ...
    CROSS_METRIC_INSIGHT = "cross_metric_insight"

# Endpoint: POST /v1/narrative
# input: kind=cross_metric_insight, context={
#   insight_key, detection_payload, user_metric_snapshot, dodo_voice_hint
# }
# output: {headline: str, body: str, tone: 'celebration'|'gentle'|'curious'|'cautious'}
```

### 5.2 Prompt 結構

```
SYSTEM: 妳是朵朵 dodo，集團健康教練 NPC...
- 對用戶用「妳 / 朋友」
- 不下醫療結論（不寫「皮質醇升高 → 失眠」這種因果，改寫「可能跟壓力有關」）
- 不違反食安法（禁用「治療 / 排毒 / 燃脂」清單，pre-sanitize）
- 50-150 字（依 detection 嚴重度）
- 結尾不必下命令，可開放問句邀請

CONTEXT:
Insight type: weight_plateau_detected
Detection: 7-day MA = 56.2kg, prior 7-day MA = 56.3kg, sleep avg = 5.8h, calorie SD = 6%
User name: 朋友

GENERATE narrative_headline (≤ 30字) + narrative_body (50-150字) + tone.
```

### 5.3 stub mode

`STUB_MODE=true` 時：用 InsightRule 的 `freeNarrativeTemplate()` 直接回（已經是合規朵朵語氣）。

### 5.4 cost guard

每個 insight narrative ≈ 0.3-0.5 NT$，per-user 一週上限 5 條動態 narrative（其餘走 free template）。

---

## 6. 食安法合規（**特別嚴審**）

Insight 內容直接踩到「健康行為 → 體重 → 體脂」相關，是食安法 / 健食法高風險區。

| 點 | 規則 |
|---|---|
| 規則 detection 名 | 內部 key 用 snake_case，不外露 |
| Free template 文案 | 集中在 `InsightRule::freeNarrativeTemplate()`，PR review 必過 narrative-designer + design-brand-guardian |
| AI 動態 narrative | sanitize post-generation；違反清單立即降級到 free template + Discord 告警 |
| 推播文案 | 同上 |
| **絕對禁用詞**：減重 / 減脂 / 燃脂 / 排毒 / 治療 / 療效 / 抑制食慾 / 加速代謝 / 燃燒脂肪 / 瘦身 / 塑身 / 速瘦 / 暴瘦 / 抗病 / 排油 |
| **可用替代**：習慣 / 節奏 / 平台期 / 規律 / 補充能量 / 身體適應 / 變化 / 堅持 |

CI guard：
- `tests/Feature/Compliance/InsightContentGuardTest.php` — 所有 12 條 rule 的 free template 全跑 sanitizer pass
- ai-service pytest 加 `test_insight_narrative_sanitizer_post.py` — 模擬 100 個 narrative output 全 sanitize pass

---

## 7. 訂閱 Gating

| 功能 | Free | Paid |
|---|---|---|
| Insight detection（rule engine 跑） | ✅ 全部 12 條 | ✅ |
| 朵朵 chat 主動 surface（headline） | ✅ 顯示 1 句 + 「升級看完整解讀」CTA | ✅ 完整 + 動態 narrative |
| 推播（headline only） | ✅ | ✅ + body preview |
| Insight detail 頁（含 chart） | ❌ | ✅ |
| 動態 AI narrative（vs 固定 template） | ❌ | ✅ |
| 週報 insight 區塊 | ❌ | ✅ |
| 歷史 insights | ❌ 過去 7 天 | ✅ 無限 |

→ Free 看到 headline 但要 paywall 才看 body / chart / 行動建議 = 自然 paywall trigger。

---

## 8. 驗收條件

### Backend
- [ ] migration / rollback 安全
- [ ] 12 條 rule 各有 unit test（happy + boundary）
- [ ] InsightEngine evaluateAll daily schedule pass
- [ ] realtime evaluate dispatchAfterCommit 正確觸發
- [ ] idempotent：同 user / rule / 7 天內只 fire 1 次
- [ ] 5 個 endpoint feature test happy + 401 + cross-tenant
- [ ] dismiss 觸發 cooldown 翻倍（7 → 14 天）
- [ ] Pest 全綠 + phpstan + InsightContentGuardTest pass

### ai-service
- [ ] CROSS_METRIC_INSIGHT narrative happy + stub_mode
- [ ] post-sanitize 阻擋違規詞 100%（單元測 100 個 hostile prompt）
- [ ] cost guard 強制 paid 5/週上限
- [ ] pytest 全綠

### Frontend
- [ ] 朵朵 chat tab 拉 unread + 主動 surface
- [ ] insight bubble tap → detail 頁（chart + action）
- [ ] paywall sheet 在 free user tap detail 時出現
- [ ] 推播 deep-link 進 insight detail
- [ ] e2e smoke：mock 平台期觸發 → 朵朵 chat surface → tap detail（paid）

### 量化指標（上線後 4 週追蹤）
- [ ] insight 觸發率 > 30% paid users 一週內收到 ≥ 1 條
- [ ] insight 推播 CTR > 25%
- [ ] insight 推播 → paywall conversion > 1.5%（free user）
- [ ] insight detail 頁停留 > 15s（=有看完）
- [ ] 違規詞投訴 = 0

---

## 9. PR 切片建議

| PR | 範圍 | 依賴 |
|---|---|---|
| **#1 schema + UserDataAggregator + 4 核心 rules** | migration + InsightEngine 主框架 + WeightPlateau / SleepDeficit / FastingStreakStepDrop / StreakMilestone30 規則 + Pest | 無 |
| **#2 剩餘 8 rules + endpoint + free narrative** | 8 條 rule + 5 endpoints + Free narrative templates + ContentGuard | #1 |
| **#3 ai-service 動態 narrative + paid path** | NarrativeKind 加 + prompt + post-sanitize + pytest | #1（schema） |
| **#4 frontend chat surface + insight detail + push** | 朵朵 chat 改 + insight detail 頁 + paywall + 推播整合 + e2e | #2 + #3 |
| **#5 weekly report 整合「本週 insight」區塊** | WeeklyReportService 加 insight section + frontend render | #4 |

---

## 10. 預估工時

| 區塊 | 工時 |
|---|---|
| #1 Schema + Engine + 4 rules | 3 天 |
| #2 剩 8 rules + endpoints + ContentGuard | 3 天 |
| #3 ai-service narrative + sanitize | 2 天 |
| #4 frontend chat + detail + push + e2e | 4 天 |
| #5 weekly report 整合 | 1 天 |
| **合計** | **13 天** |

---

## 11. 不做（Out of Scope）

- ❌ 月經週期 × 食慾 / 體重相關 insight（要等 calendar app 跨 App 資料 sync，留 v2）
- ❌ ML model 自動 discover pattern（規則表夠用 + 比 ML 可控可審）
- ❌ Insight 自然語言問答（「為什麼我會這樣？」對話） — 留給朵朵 chat 主流程
- ❌ Insight share card（不適合分享：太個人 + 隱私）— 進度照才適合
- ❌ 真人營養師審核（VIP tier 後續）

---

## 12. 風險

| 風險 | 緩解 |
|---|---|
| Insight 太頻繁打擾 | cooldown + dismiss 翻倍 cooldown + 全局上限 3 條/週 active surface |
| AI narrative 違反食安法 | post-sanitize + free template fallback + Discord 告警 + ContentGuardTest |
| Rule false positive（誤判平台期） | detection 條件嚴格 + sample size guard（飲食 SD < 10% 才算穩定 baseline） |
| 用戶資料不足（新用戶） | aggregator 樣本 < 7 天直接 skip rule（不 force fire） |
| 跨指標相關性其實沒因果 | 朵朵語氣寫「可能 / 也許」不寫「因為」；不下結論 |
| Realtime evaluate 性能 | dispatchAfterCommit + queue worker，不阻塞 request |

---

## 13. 後續鉤子（v2 / v3）

- 月經週期 × 食慾 / 體重 insight（calendar app 整合）
- 旅遊 / 出差 detection（GPS or HK steps pattern 突變）
- 季節變化（冬天食慾 / 夏天活動）
- Pattern detection ML（規則表跑滿 30 條後）
- 朵朵 voice insight（語音播報）
