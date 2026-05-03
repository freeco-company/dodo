"""SPEC-photo-ai-correction-v2 PR #3 — vision refine service.

Refines a single dish from the original image + user hint. The user has just
told us "this isn't 白飯, it's 糙米" or "this is actually 1.5× the portion AI
estimated"; we re-derive the macros honouring the hint.

Stub mode applies the hint deterministically (food_key swap → keep AI macros
but rename; portion change → linear-scale kcal/carb/protein/fat). Real mode
will call Anthropic vision with the original image + hint as context — that
prompt path is a follow-up patch (TODO marker below) so PR #3 ships a
testable, no-cost baseline today.
"""

from __future__ import annotations

import base64
import copy
import json
import logging
from dataclasses import dataclass
from typing import Any

import httpx

from app.config import Settings
from app.vision.schemas import (
    RefinedDish,
    RefineDishInput,
    RefineUserHint,
    VisionRefineRequest,
    VisionRefineResponse,
)

logger = logging.getLogger(__name__)

_FOOD_NAME_BY_KEY = {
    "rice_white": "白飯",
    "rice_brown": "糙米",
    "rice_grain": "五穀飯",
    "rice_purple": "紫米",
    "chicken_thigh": "雞腿",
    "chicken_breast": "雞胸",
    "egg_braised": "滷蛋",
    "cabbage": "高麗菜",
}


@dataclass
class RefineResult:
    response: VisionRefineResponse
    cost_usd: float


class StubRefineService:
    """Deterministic stub — no Anthropic call. Used in tests + STUB_MODE=true."""

    def refine(self, payload: VisionRefineRequest) -> VisionRefineResponse:
        dishes_out: list[RefinedDish] = []
        for idx, orig in enumerate(payload.original_dishes):
            if idx == payload.user_hint.dish_index:
                dishes_out.append(_apply_hint_stub(orig, payload.user_hint))
            else:
                dishes_out.append(_passthrough(orig))

        return VisionRefineResponse(
            dishes=dishes_out,
            model="stub-refine",
            cost_usd=0.0,
            stub_mode=True,
            user_hint=payload.user_hint,
        )


def _passthrough(d: RefineDishInput) -> RefinedDish:
    return RefinedDish(
        food_name=d.food_name,
        food_key=d.food_key,
        portion_multiplier=d.portion_multiplier,
        kcal=d.kcal,
        carb_g=d.carb_g,
        protein_g=d.protein_g,
        fat_g=d.fat_g,
        confidence=d.confidence,
        candidates=[],
    )


def _apply_hint_stub(orig: RefineDishInput, hint: RefineUserHint) -> RefinedDish:
    """Stub: rename + linear-scale macros.

    A food swap without macro re-derivation isn't physically correct (rice
    white vs brown have different protein/fibre), but stub mode is for tests +
    dev — when STUB_MODE=false in prod the real Anthropic prompt re-derives
    the macros properly using the original image as context.
    """
    out = copy.deepcopy(orig)
    if hint.new_food_key is not None:
        out.food_key = hint.new_food_key
        out.food_name = _FOOD_NAME_BY_KEY.get(
            hint.new_food_key, hint.new_food_name or orig.food_name
        )
    elif hint.new_food_name is not None:
        out.food_name = hint.new_food_name

    if hint.new_portion is not None and hint.new_portion != orig.portion_multiplier:
        scale = hint.new_portion / max(orig.portion_multiplier, 0.01)
        out.portion_multiplier = hint.new_portion
        out.kcal = int(round(orig.kcal * scale))
        out.carb_g = round(orig.carb_g * scale, 2)
        out.protein_g = round(orig.protein_g * scale, 2)
        out.fat_g = round(orig.fat_g * scale, 2)

    return RefinedDish(
        food_name=out.food_name,
        food_key=out.food_key,
        portion_multiplier=out.portion_multiplier,
        kcal=out.kcal,
        carb_g=out.carb_g,
        protein_g=out.protein_g,
        fat_g=out.fat_g,
        confidence=0.85,  # stub: refined dish always lands at threshold
        candidates=[],
    )


_REFINE_SYSTEM_PROMPT = (
    "妳是「朵朵 dodo」的營養助手，專門做 dish-level 的 macro 校正。"
    "用戶剛剛告訴妳一道菜的食材或份量被 AI 認錯了。請用原圖 + 用戶 hint，"
    "重新估算那一道菜的 kcal / carb_g / protein_g / fat_g。"
    "硬規則：只回 JSON、不寫散文、不下醫療建議、不評論身材。"
)


class AnthropicRefineService:
    """SPEC PR #6 — real Anthropic vision refine.

    Downloads the original image (image_url) and sends it + the user_hint to
    Claude vision with a deterministic prompt. Returns RefinedDish for the
    target dish; other dishes pass through unchanged unless the swap
    implies structural change (rare, and stub fallback covers that).

    Fail-soft: any error → fall back to StubRefineService (linear scaling).
    Production: STUB_MODE=false + ANTHROPIC_API_KEY set → real path activates.
    """

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client: Any | None = None
        self._stub = StubRefineService()

    async def refine(self, payload: VisionRefineRequest) -> VisionRefineResponse:
        if self._settings.stub_mode or not self._settings.anthropic_api_key:
            return self._stub.refine(payload)
        try:
            return await self._real_refine(payload)
        except Exception as e:  # noqa: BLE001 — fail-soft contract
            logger.warning("[refine] anthropic call failed, stub fallback: %s", e)
            return self._stub.refine(payload)

    def _ensure_client(self) -> Any:
        if self._client is None:
            from anthropic import AsyncAnthropic
            self._client = AsyncAnthropic(api_key=self._settings.anthropic_api_key)
        return self._client

    async def _real_refine(self, payload: VisionRefineRequest) -> VisionRefineResponse:
        # Download image (short timeout; share fail-soft path with cache miss).
        if not payload.image_url:
            return self._stub.refine(payload)

        async with httpx.AsyncClient(timeout=10) as http:
            r = await http.get(payload.image_url)
            r.raise_for_status()
            image_bytes = r.content
            image_mime = r.headers.get("content-type", "image/jpeg").split(";")[0].strip()

        if image_mime not in {"image/jpeg", "image/png", "image/webp", "image/heic"}:
            image_mime = "image/jpeg"

        b64 = base64.b64encode(image_bytes).decode("ascii")
        target_idx = payload.user_hint.dish_index
        target_dish = payload.original_dishes[target_idx]
        hint_lines: list[str] = [f"目標 dish 索引：{target_idx}"]
        if payload.user_hint.new_food_key:
            hint_lines.append(f"用戶說食材其實是：{payload.user_hint.new_food_key}")
        if payload.user_hint.new_food_name:
            hint_lines.append(f"用戶提供食材名：{payload.user_hint.new_food_name}")
        if payload.user_hint.new_portion is not None:
            hint_lines.append(f"用戶說份量倍率是：{payload.user_hint.new_portion}×")

        original_lines = []
        for i, d in enumerate(payload.original_dishes):
            marker = " ← 校正目標" if i == target_idx else ""
            original_lines.append(
                f"  {i}. {d.food_name}（key={d.food_key}）"
                f" {d.kcal} kcal / 碳 {d.carb_g}g / 蛋 {d.protein_g}g / 脂 {d.fat_g}g{marker}"
            )

        user_text = (
            f"原 AI 估算的 dishes：\n{chr(10).join(original_lines)}\n\n"
            f"用戶 hint：\n{chr(10).join(hint_lines)}\n\n"
            f"請只重估目標 dish (index {target_idx})，其他 dish 保持原值。\n"
            "輸出 JSON：\n"
            '{"food_name": "...", "food_key": "...", "portion_multiplier": 1.0,\n'
            ' "kcal": 0, "carb_g": 0.0, "protein_g": 0.0, "fat_g": 0.0,\n'
            ' "confidence": 0.0}\n'
            "Confidence 0.7-0.95 視視覺證據強弱。"
        )

        client = self._ensure_client()
        resp = await client.messages.create(
            model=self._settings.anthropic_model_vision,
            max_tokens=512,
            system=_REFINE_SYSTEM_PROMPT,
            messages=[
                {
                    "role": "user",
                    "content": [
                        {
                            "type": "image",
                            "source": {"type": "base64", "media_type": image_mime, "data": b64},
                        },
                        {"type": "text", "text": user_text},
                    ],
                }
            ],
        )

        text_parts: list[str] = []
        for block in resp.content:
            if getattr(block, "type", None) == "text":
                text_parts.append(getattr(block, "text", ""))
        raw = "".join(text_parts).strip()

        # Trim leading ```json fences if any
        if raw.startswith("```"):
            raw = raw.strip("`").strip()
            if raw.startswith("json"):
                raw = raw[4:].strip()

        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            logger.warning("[refine] non-JSON response, stub fallback: %r", raw[:200])
            return self._stub.refine(payload)

        # Compose dishes_out: passthrough non-target, refined target.
        dishes_out: list[RefinedDish] = []
        for idx, orig in enumerate(payload.original_dishes):
            if idx == target_idx:
                dishes_out.append(RefinedDish(
                    food_name=parsed.get("food_name") or target_dish.food_name,
                    food_key=parsed.get("food_key") or target_dish.food_key,
                    portion_multiplier=float(
                        parsed.get("portion_multiplier") or target_dish.portion_multiplier
                    ),
                    kcal=int(parsed.get("kcal") or 0),
                    carb_g=float(parsed.get("carb_g") or 0),
                    protein_g=float(parsed.get("protein_g") or 0),
                    fat_g=float(parsed.get("fat_g") or 0),
                    confidence=float(parsed.get("confidence") or 0.85),
                    candidates=[],
                ))
            else:
                dishes_out.append(_passthrough(orig))

        # Token-based cost estimate (vision is more expensive than chat).
        usage = getattr(resp, "usage", None)
        in_tok = getattr(usage, "input_tokens", 0) if usage else 0
        out_tok = getattr(usage, "output_tokens", 0) if usage else 0
        from app.cost.tracker import anthropic_cost_usd
        cost = anthropic_cost_usd(
            model=self._settings.anthropic_model_vision,
            input_tokens=in_tok,
            output_tokens=out_tok,
        )

        return VisionRefineResponse(
            dishes=dishes_out,
            model=self._settings.anthropic_model_vision,
            cost_usd=cost,
            stub_mode=False,
            user_hint=payload.user_hint,
        )
