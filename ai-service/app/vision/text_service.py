"""Text-based meal recognition (Claude text model).

Companion to ``anthropic_client.py`` (image vision). The user describes what they
ate in free text (or via STT), we ask Claude to extract structured foods +
calories. Same red-line safety stack as the image path.
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from typing import Any

from app.config import Settings
from app.vision.schemas import RecognizedTextFood

logger = logging.getLogger(__name__)


@dataclass
class TextRecognitionResult:
    foods: list[RecognizedTextFood]
    overall_confidence: float
    ai_feedback: str
    model: str
    input_tokens: int
    output_tokens: int


_TEXT_PROMPT = (
    "使用者口述自己吃了什麼，請辨識並輸出 JSON：\n"
    '{"foods":[{"name":"...","estimated_kcal":整數,"confidence":0-1}],'
    '"overall_confidence":0-1,"feedback":"一句鼓勵或建議"}\n'
    "規則：\n"
    "- 只輸出 JSON，不要 markdown / 說明文字。\n"
    "- 不確定份量就把 confidence 拉低（< 0.85），不要硬猜。\n"
    "- feedback 用繁體中文，1-2 句，溫暖正向，不批判飲食選擇。\n"
    "- 不可建議單餐 < 800 大卡或全日 < 1200 大卡的方案。\n"
    "- 描述模糊（如「吃了一些東西」）時 foods 留空、confidence 給 0.3。\n"
)


class AnthropicTextRecognizer:
    """Wraps Anthropic text model for spoken/typed meal description recognition."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client: Any | None = None

    def _ensure_client(self) -> Any:
        if self._client is None:
            from anthropic import AsyncAnthropic

            self._client = AsyncAnthropic(api_key=self._settings.anthropic_api_key)
        return self._client

    async def recognize(
        self, *, description: str, hint: str | None = None
    ) -> TextRecognitionResult:
        if self._settings.stub_mode:
            from app.vision.text_stub import stub_text_result

            return stub_text_result()
        return await self._real_recognize(description=description, hint=hint)

    async def _real_recognize(
        self, *, description: str, hint: str | None
    ) -> TextRecognitionResult:
        client = self._ensure_client()
        # Use the default (cheap) text model — text recog is high-volume, sonnet
        # is overkill. Vision-only requests still use anthropic_model_vision.
        model = self._settings.anthropic_model_default

        user_text = f"使用者描述：{description}"
        if hint:
            user_text += f"\n額外提示：{hint}"

        resp = await client.messages.create(
            model=model,
            max_tokens=1024,
            messages=[
                {
                    "role": "user",
                    "content": f"{_TEXT_PROMPT}\n\n{user_text}",
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
            logger.warning(
                "text recognition response not JSON, falling back to manual: %r",
                raw_text[:200],
            )
            return TextRecognitionResult(
                foods=[],
                overall_confidence=0.0,
                ai_feedback="辨識結果無法解析，請手動輸入。",
                model=model,
                input_tokens=getattr(resp.usage, "input_tokens", 0),
                output_tokens=getattr(resp.usage, "output_tokens", 0),
            )

        foods_raw = payload.get("foods") or []
        foods: list[RecognizedTextFood] = []
        for it in foods_raw:
            try:
                foods.append(
                    RecognizedTextFood(
                        name=str(it.get("name", "未知")),
                        estimated_kcal=int(it.get("estimated_kcal", 0)),
                        confidence=float(it.get("confidence", 0.0)),
                    )
                )
            except (TypeError, ValueError):
                continue

        overall = float(payload.get("overall_confidence", 0.0))
        feedback = str(payload.get("feedback", "")).strip() or "已記錄。"

        return TextRecognitionResult(
            foods=foods,
            overall_confidence=overall,
            ai_feedback=feedback,
            model=model,
            input_tokens=getattr(resp.usage, "input_tokens", 0),
            output_tokens=getattr(resp.usage, "output_tokens", 0),
        )
