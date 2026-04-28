"""Anthropic streaming client (with prompt caching) + stub fallback.

Production path uses ``anthropic.AsyncAnthropic`` with cache_control on the
system prompt and per-user diet profile blocks. When ``ANTHROPIC_API_KEY`` is
empty the stream() method yields a deterministic safe canned reply so contract
tests run with no key.
"""

from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from dataclasses import dataclass
from typing import Any

from app.chat.schemas import ChatMessage, ChatStreamRequest
from app.chat.system_prompt import DODO_SYSTEM_PROMPT
from app.config import Settings

logger = logging.getLogger(__name__)


@dataclass
class StreamChunk:
    """A streaming delta. ``done=True`` marks the terminal frame with usage info."""

    delta: str = ""
    done: bool = False
    input_tokens: int = 0
    output_tokens: int = 0
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    model: str = ""


_STUB_REPLY = (
    "嗨，朵朵收到妳的訊息了。"
    "（目前 LLM 服務未連線，這是預設安全回應，後端 contract 仍可運作）"
    "如果妳想討論今天的飲食，可以先告訴我吃了什麼，我會給妳一個簡單的方向。"
)


class AnthropicChatClient:
    """Wraps Anthropic Messages streaming API with prompt caching."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._client: Any | None = None  # lazy-init to avoid import cost in stub mode

    def _ensure_client(self) -> Any:
        if self._client is None:
            # Imported lazily; mypy ignore — anthropic stubs may differ.
            from anthropic import AsyncAnthropic

            self._client = AsyncAnthropic(api_key=self._settings.anthropic_api_key)
        return self._client

    def _model_for(self, request: ChatStreamRequest) -> str:
        if request.use_premium_model:
            return self._settings.anthropic_model_premium
        return self._settings.anthropic_model_default

    def _build_system_blocks(self, diet_profile: str | None) -> list[dict[str, Any]]:
        """Build system prompt with prompt-caching markers.

        Two cacheable blocks:
          1. The static Dodo system prompt (cache hit across all users)
          2. Per-user diet portrait (cache hit across that user's day)
        """
        blocks: list[dict[str, Any]] = [
            {
                "type": "text",
                "text": DODO_SYSTEM_PROMPT,
                "cache_control": {"type": "ephemeral"},
            }
        ]
        if diet_profile and diet_profile.strip():
            blocks.append(
                {
                    "type": "text",
                    "text": f"# 使用者飲食畫像\n{diet_profile.strip()}",
                    "cache_control": {"type": "ephemeral"},
                }
            )
        return blocks

    @staticmethod
    def _to_anthropic_messages(
        history: list[ChatMessage], current: str
    ) -> list[dict[str, str]]:
        msgs: list[dict[str, str]] = [
            {"role": m.role, "content": m.content} for m in history
        ]
        msgs.append({"role": "user", "content": current})
        return msgs

    async def stream(self, request: ChatStreamRequest) -> AsyncIterator[StreamChunk]:
        """Yield streaming chunks. Last chunk has ``done=True`` with usage."""
        if self._settings.stub_mode:
            async for chunk in self._stub_stream(request):
                yield chunk
            return

        async for chunk in self._real_stream(request):
            yield chunk

    async def _stub_stream(
        self, request: ChatStreamRequest
    ) -> AsyncIterator[StreamChunk]:
        # Yield the stub reply word-by-word so SSE framing is exercised.
        words = _STUB_REPLY.split()
        for w in words:
            yield StreamChunk(delta=w + " ", done=False)
        yield StreamChunk(
            done=True,
            input_tokens=len(request.message),  # rough proxy
            output_tokens=len(_STUB_REPLY),
            model="stub",
        )

    async def _real_stream(
        self, request: ChatStreamRequest
    ) -> AsyncIterator[StreamChunk]:
        client = self._ensure_client()
        model = self._model_for(request)
        system_blocks = self._build_system_blocks(request.diet_profile)
        messages = self._to_anthropic_messages(request.history, request.message)

        # SDK provides .messages.stream() async context manager.
        async with client.messages.stream(
            model=model,
            system=system_blocks,
            messages=messages,
            max_tokens=1024,
        ) as stream:
            async for text_delta in stream.text_stream:
                yield StreamChunk(delta=text_delta, done=False)
            final = await stream.get_final_message()

        usage = final.usage
        yield StreamChunk(
            done=True,
            input_tokens=getattr(usage, "input_tokens", 0),
            output_tokens=getattr(usage, "output_tokens", 0),
            cache_read_tokens=getattr(usage, "cache_read_input_tokens", 0) or 0,
            cache_write_tokens=getattr(usage, "cache_creation_input_tokens", 0) or 0,
            model=model,
        )
