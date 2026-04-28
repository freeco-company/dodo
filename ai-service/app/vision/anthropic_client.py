"""Anthropic Vision client (food recognition) + stub fallback.

Production path uses Claude Vision with a structured-output prompt asking for
JSON ``items`` + ``confidence``. Stub mode returns a deterministic low-confidence
result so the manual-input fallback path is exercised.
"""

from __future__ import annotations

import base64
import json
import logging
from dataclasses import dataclass
from typing import Any

from app.config import Settings
from app.vision.schemas import RecognizedItem

logger = logging.getLogger(__name__)


@dataclass
class VisionResult:
    items: list[RecognizedItem]
    overall_confidence: float
    ai_feedback: str
    model: str
    input_tokens: int
    output_tokens: int


_VISION_PROMPT = (
    "請辨識照片中的食物，輸出 JSON：\n"
    '{"items":[{"name":"...","estimated_kcal":整數,"confidence":0-1}],'
    '"overall_confidence":0-1,"feedback":"一句鼓勵或建議"}\n'
    "規則：\n"
    "- 只輸出 JSON，不要 markdown。\n"
    "- 不確定就把 confidence 拉低，不要硬猜。\n"
    "- feedback 用繁體中文，1-2 句，溫暖正向。\n"
    "- 不可建議單餐 < 800 大卡或全日 < 1200 大卡的方案。\n"
)


class AnthropicVisionClient:
    """Wraps Anthropic Vision (multimodal) for food recognition."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client: Any | None = None

    def _ensure_client(self) -> Any:
        if self._client is None:
            from anthropic import AsyncAnthropic

            self._client = AsyncAnthropic(api_key=self._settings.anthropic_api_key)
        return self._client

    async def recognize(
        self, image_bytes: bytes, image_mime: str, meal_type: str
    ) -> VisionResult:
        if self._settings.stub_mode:
            return self._stub_result(meal_type)
        return await self._real_recognize(image_bytes, image_mime, meal_type)

    def _stub_result(self, meal_type: str) -> VisionResult:
        # Deliberately low confidence (< 0.85) so the manual-input fallback fires.
        return VisionResult(
            items=[
                RecognizedItem(
                    name=f"未知{meal_type}項目（stub）",
                    estimated_kcal=500,
                    confidence=0.5,
                )
            ],
            overall_confidence=0.5,
            ai_feedback="目前 LLM 服務未連線（stub），這是預設低信心結果，請使用者手動輸入。",
            model="stub",
            input_tokens=0,
            output_tokens=0,
        )

    async def _real_recognize(
        self, image_bytes: bytes, image_mime: str, meal_type: str
    ) -> VisionResult:
        client = self._ensure_client()
        model = self._settings.anthropic_model_vision
        b64 = base64.b64encode(image_bytes).decode("ascii")

        resp = await client.messages.create(
            model=model,
            max_tokens=1024,
            messages=[
                {
                    "role": "user",
                    "content": [
                        {
                            "type": "image",
                            "source": {
                                "type": "base64",
                                "media_type": image_mime,
                                "data": b64,
                            },
                        },
                        {
                            "type": "text",
                            "text": f"餐別：{meal_type}\n\n{_VISION_PROMPT}",
                        },
                    ],
                }
            ],
        )

        text_parts: list[str] = []
        for block in resp.content:
            if getattr(block, "type", None) == "text":
                text_parts.append(getattr(block, "text", ""))
        raw_text = "".join(text_parts).strip()

        try:
            payload = json.loads(raw_text)
        except json.JSONDecodeError:
            logger.warning("vision response not JSON, falling back to manual: %r", raw_text[:200])
            return VisionResult(
                items=[],
                overall_confidence=0.0,
                ai_feedback="辨識結果無法解析，請手動輸入。",
                model=model,
                input_tokens=getattr(resp.usage, "input_tokens", 0),
                output_tokens=getattr(resp.usage, "output_tokens", 0),
            )

        items_raw = payload.get("items") or []
        items: list[RecognizedItem] = []
        for it in items_raw:
            try:
                items.append(
                    RecognizedItem(
                        name=str(it.get("name", "未知")),
                        estimated_kcal=int(it.get("estimated_kcal", 0)),
                        confidence=float(it.get("confidence", 0.0)),
                    )
                )
            except (TypeError, ValueError):
                continue

        overall = float(payload.get("overall_confidence", 0.0))
        feedback = str(payload.get("feedback", "")).strip() or "辨識完成。"

        return VisionResult(
            items=items,
            overall_confidence=overall,
            ai_feedback=feedback,
            model=model,
            input_tokens=getattr(resp.usage, "input_tokens", 0),
            output_tokens=getattr(resp.usage, "output_tokens", 0),
        )
