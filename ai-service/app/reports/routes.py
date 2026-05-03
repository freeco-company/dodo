"""POST /v1/reports/narrative — non-streaming 朵朵 narrative generator.

Used by Laravel WeeklyReportService / FastingService / ProgressSnapshotController
to upgrade rule-based fallback text to dynamic朵朵-voice copy for paid users.

Internal-only: auth via X-Internal-Secret (server-to-server). No JWT path —
this is never user-fronting.
"""

from __future__ import annotations

import logging
from typing import Any

from fastapi import APIRouter, Depends

from app.auth.internal import require_internal_secret
from app.config import Settings
from app.deps import settings_dep
from app.reports.schemas import NarrativeRequest, NarrativeResponse
from app.safety.ritual_sanitizer import ritual_text_violates

logger = logging.getLogger(__name__)
router = APIRouter()


_FALLBACK_HEADLINE = "朵朵的小報告 ✨"


def _build_user_prompt(req: NarrativeRequest) -> str:
    """Serialize the typed payload into a deterministic prompt body.

    Keeps the LLM output predictable and avoids hallucinating numbers
    that weren't in the input.
    """
    lines: list[str] = [f"類型: {req.kind}", f"訂閱層級: {req.tier}"]

    if req.weekly_report is not None:
        w = req.weekly_report
        lines.append(f"\n本週數據（{w.window_start} ~ {w.window_end}）:")
        lines.append(f"- 記錄天數 {w.days_logged} 天")
        lines.append(f"- 飲食 {w.meals_count} 餐 / {w.meals_kcal} kcal")
        if w.top_foods:
            lines.append(f"- 常吃: {', '.join(w.top_foods[:3])}")
        lines.append(f"- 步數 {w.steps_total:,} 步")
        if w.fasting_sessions > 0:
            lines.append(f"- 斷食 {w.fasting_sessions} 次（達標 {w.fasting_completed} 次）")
        if w.weight_change_kg is not None:
            sign = "+" if w.weight_change_kg > 0 else ""
            lines.append(f"- 體重變化 {sign}{w.weight_change_kg}kg")
        if w.sleep_avg_minutes is not None and req.tier != "free":
            h, m = divmod(w.sleep_avg_minutes, 60)
            lines.append(f"- 平均睡眠 {h}h {m}m")

    if req.fasting_completed is not None:
        f = req.fasting_completed
        h, m = divmod(f.elapsed_minutes, 60)
        target_h = f.target_minutes // 60
        lines.append(f"\n剛剛完成的斷食:\n- 模式 {f.mode}（目標 {target_h}h）")
        lines.append(f"- 實際 {h}h {m}m · 連續達標 {f.streak_days} 天")

    if req.progress_snapshot is not None:
        p = req.progress_snapshot
        lines.append(f"\n進度照數據（{p.snapshot_count_90d} 筆 90 天內）:")
        if p.weight_kg_now is not None:
            lines.append(f"- 目前體重 {p.weight_kg_now}kg")
        if p.weight_kg_30d_ago is not None and p.weight_kg_now is not None:
            delta = round(p.weight_kg_now - p.weight_kg_30d_ago, 1)
            lines.append(f"- 30 天前 {p.weight_kg_30d_ago}kg（差 {delta:+}kg）")
        lines.append(f"- 連續記錄 {p.streak_days} 天")

    if req.photo_meal is not None:
        meal = req.photo_meal
        lines.append(f"\n剛拍的這餐:\n- {meal.food_name}（{meal.calories} kcal）")
        lines.append(f"- 蛋白 {meal.protein_g}g / 碳水 {meal.carbs_g}g / 脂肪 {meal.fat_g}g")

    if req.monthly_collage_letter is not None:
        c = req.monthly_collage_letter
        lines.append(f"\n月度集錦：{c.month}")
        lines.append(f"- 規律記錄 {c.food_days_logged} 天")
        lines.append(f"- 累積 {c.steps_total:,} 步")
        lines.append(f"- 斷食達標 {c.fasting_days} 天")
        lines.append(f"- 進度照拍了 {c.snapshot_count} 張")
        lines.append(
            "請寫一封朵朵手寫信，1 行 headline (≤30字) + 2-3 行 body，每行 ≤40字。"
            "硬規則禁止：減重 / 減脂 / 燃脂 / 瘦身 / 塑身 / 變瘦 / 排毒 / 治療。"
            "也不評論身材外觀（不寫『妳的腰』『身形比例』）。"
            "可用：堅持 / 累積 / 規律 / 動了起來 / 變化 / 步伐。"
        )

    if req.streak_milestone_letter is not None:
        sm = req.streak_milestone_letter
        kind_label = {
            "meal": "飲食記錄",
            "fasting": "斷食達標",
            "steps": "步數達標",
            "weight_log": "體重打卡",
            "photo": "進度照",
        }.get(sm.streak_kind, sm.streak_kind)
        lines.append(f"\nStreak milestone：{sm.streak_count} 天 {kind_label}連勝")
        lines.append(
            "寫一封 fullscreen celebration 朵朵手寫信。"
            "1 行 headline (≤30字)『XX 天連勝 🌟』風格 + 2-3 行 body 每行 ≤40字。"
            "重點是『妳真的做到了』『不是每個人都能堅持』式的肯定 + 提醒對自己說一聲『辛苦了』。"
            "禁用：減重 / 變瘦 / 燃脂 / 瘦身。"
        )

    if req.progress_slider_caption is not None:
        lines.append(f"\n進度照對比 caption：{req.progress_slider_caption.days_between} 天")
        lines.append(
            "回 1 行 ≤30 字短文：『妳堅持了 X 天 ✨』風格。"
            "硬規則禁止：減重 / 減脂 / 燃脂 / 變瘦 / 瘦身 / 塑身 / 排毒。"
            "也禁止評論身材外觀。可用：堅持 / 動了起來 / 累積 / 持續 / 變化。"
            "輸出格式：只一行（沒有 body）。"
        )

    if req.fasting_stage_transition is not None:
        st = req.fasting_stage_transition
        h, m = divmod(st.elapsed_minutes, 60)
        target_h = st.target_minutes // 60
        phase_label = {
            "settling": "進入空腹（4h）",
            "glycogen_switch": "肝醣轉換（8h）",
            "fat_burning": "脂肪燃燒區（12h）",
            "autophagy": "自噬作用（16h）",
            "deep_fast": "深度斷食（20h+）",
        }.get(st.phase, st.phase)
        lines.append(f"\n剛剛進入新階段：{phase_label}")
        lines.append(f"- 模式 {st.mode}（目標 {target_h}h）· 已斷食 {h}h {m}m")
        if st.streak_days > 0:
            lines.append(f"- 連續達標 {st.streak_days} 天")
        lines.append(
            "請給 1 行 headline + 1-2 行身體狀態說明（不寫療效，不寫保證減重）+ 1 行鼓勵。"
        )

    lines.append(
        "\n請以「朵朵」的口吻回覆 2-4 行繁中文字，每行 < 40 字。"
        "正向、溫暖、避免負面評價（不要說「妳變胖了」等）。"
        "如果是 weekly_report 並且 free 層級，最後一行可以暗示升級才有更深的點評；"
        "其他情況不要出現升級字樣。"
        "輸出格式：第一行是 headline（朵朵的小標題），後續每行一條心得，用換行分隔。"
        "禁止：療效宣稱（治療 / 排毒 / 燃脂 / 抗氧化）、醫療建議、減重保證、外貌負評。"
    )
    return "\n".join(lines)


def _stub_response(req: NarrativeRequest) -> NarrativeResponse:
    """Deterministic safe fallback when ANTHROPIC_API_KEY is empty or call fails."""
    if req.kind == "fasting_completed" and req.fasting_completed is not None:
        h = req.fasting_completed.elapsed_minutes // 60
        return NarrativeResponse(
            headline=f"完成 {h} 小時斷食 ✨",
            lines=["朵朵：「達成目標了 🌱 記得補水休息」", "下一次也會幫妳記著節奏"],
            model="stub",
            stub_mode=True,
        )
    if req.kind == "photo_meal" and req.photo_meal is not None:
        return NarrativeResponse(
            headline=req.photo_meal.food_name,
            lines=[f"記錄了 {req.photo_meal.calories} kcal ✨", "朵朵：「吃得均衡就好 🌷」"],
            model="stub",
            stub_mode=True,
        )
    if req.kind == "fasting_stage_transition" and req.fasting_stage_transition is not None:
        st = req.fasting_stage_transition
        stub_titles = {
            "settling": "進入空腹 🌱",
            "glycogen_switch": "能量切換中 ✨",
            "fat_burning": "進入脂肪燃燒區 🔥",
            "autophagy": "細胞清潔模式 🌟",
            "deep_fast": "深度斷食 💪",
        }
        return NarrativeResponse(
            headline=stub_titles.get(st.phase, "繼續加油 🌷"),
            lines=["朵朵：「身體在好好運作 🌱」", "記得補水"],
            model="stub",
            stub_mode=True,
        )
    if req.kind == "monthly_collage_letter" and req.monthly_collage_letter is not None:
        c = req.monthly_collage_letter
        return NarrativeResponse(
            headline=f"{c.month} 朵朵的月度回顧 🌱",
            lines=[
                f"這個月妳堅持了 {c.food_days_logged} 天的記錄",
                f"累積 {c.steps_total:,} 步、斷食 {c.fasting_days} 天",
                "下個月繼續走下去吧 ✨",
            ],
            model="stub",
            stub_mode=True,
        )
    if req.kind == "streak_milestone_letter" and req.streak_milestone_letter is not None:
        sm = req.streak_milestone_letter
        return NarrativeResponse(
            headline=f"{sm.streak_count} 天連勝 🌟",
            lines=[
                "妳真的做到了。",
                "不是每個人都能堅持這麼久。",
                "要記得對自己說一聲「辛苦了」。",
            ],
            model="stub",
            stub_mode=True,
        )
    if req.kind == "progress_slider_caption" and req.progress_slider_caption is not None:
        return NarrativeResponse(
            headline=f"妳堅持了 {req.progress_slider_caption.days_between} 天 ✨",
            lines=[],
            model="stub",
            stub_mode=True,
        )
    return NarrativeResponse(
        headline=_FALLBACK_HEADLINE,
        lines=["朵朵：「這週的紀錄都收進來了 🌱」", "下週再一起看看走勢 ✨"],
        model="stub",
        stub_mode=True,
    )


async def _call_anthropic(req: NarrativeRequest, settings: Settings) -> NarrativeResponse:
    """Non-streaming Anthropic Messages call. Returns stub response on any error."""
    from anthropic import AsyncAnthropic

    model = (
        settings.anthropic_model_premium
        if req.tier in {"paid", "vip"}
        else settings.anthropic_model_default
    )
    client: Any = AsyncAnthropic(api_key=settings.anthropic_api_key)
    user_prompt = _build_user_prompt(req)

    try:
        msg = await client.messages.create(
            model=model,
            max_tokens=400,
            temperature=0.5,
            system=(
                "妳是「朵朵 dodo」，潘朵拉飲食 App 的導師 NPC。語氣溫暖、簡潔、用繁中、"
                "稱呼用戶「妳」（不寫「您」「會員」）。專注健康行為與生活節奏。"
                "不做療效宣稱、醫療建議、外貌負評。"
            ),
            messages=[{"role": "user", "content": user_prompt}],
        )
    except Exception as e:  # noqa: BLE001 — fail-soft is the contract
        logger.warning("[reports] anthropic call failed, falling back to stub: %s", e)
        stub = _stub_response(req)
        stub.model = f"stub_after_error:{model}"
        return stub

    text = ""
    try:
        for block in getattr(msg, "content", []) or []:
            if getattr(block, "type", None) == "text":
                text += getattr(block, "text", "")
    except Exception:  # noqa: BLE001
        text = ""

    text = text.strip()
    if not text:
        return _stub_response(req)

    parts = [s.strip() for s in text.split("\n") if s.strip()]
    headline = parts[0] if parts else _FALLBACK_HEADLINE
    body = parts[1:] if len(parts) > 1 else ["朵朵：「妳今天有來看，就很棒了 🌱」"]

    usage = getattr(msg, "usage", None)
    in_tok = getattr(usage, "input_tokens", 0) if usage else 0
    out_tok = getattr(usage, "output_tokens", 0) if usage else 0
    # Reuse the cost tracker pricing constants.
    from app.cost.tracker import anthropic_cost_usd

    cost = anthropic_cost_usd(
        model=model,
        input_tokens=in_tok,
        output_tokens=out_tok,
    )

    # SPEC-progress-ritual-v1 PR #3 — post-LLM sanitize for ritual kinds.
    # Ritual narratives sit on top of progress photos / weight context — the
    # 食安法 violation surface is the highest in the SPEC suite. Conservative:
    # any forbidden term → fall back to deterministic stub (already vetted).
    if req.kind in {"monthly_collage_letter", "streak_milestone_letter", "progress_slider_caption"}:
        joined = headline + " " + " ".join(body)
        violations = ritual_text_violates(joined)
        if violations:
            logger.warning(
                "[reports] ritual narrative violated sanitizer (%s); falling back to template",
                violations,
            )
            stub = _stub_response(req)
            stub.model = f"stub_after_sanitize:{model}"
            return stub

    return NarrativeResponse(
        headline=headline,
        lines=body,
        model=model,
        cost_usd=cost,
        stub_mode=False,
    )


@router.post(
    "/v1/reports/narrative",
    response_model=NarrativeResponse,
    dependencies=[Depends(require_internal_secret)],
)
async def generate_narrative(
    request: NarrativeRequest,
    settings: Settings = Depends(settings_dep),
) -> NarrativeResponse:
    if settings.stub_mode:
        return _stub_response(request)
    return await _call_anthropic(request, settings)
