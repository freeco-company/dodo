"""Pydantic schemas for chat endpoints."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class ChatMessage(BaseModel):
    role: Literal["user", "assistant"]
    content: str = Field(min_length=1, max_length=8000)


class ChatStreamRequest(BaseModel):
    session_id: str = Field(min_length=1, max_length=64)
    message: str = Field(min_length=1, max_length=8000)
    history: list[ChatMessage] = Field(default_factory=list, max_length=40)
    # Optional cached personalization profile (per-user diet portrait).
    diet_profile: str | None = Field(default=None, max_length=4000)
    use_premium_model: bool = False


class ChatStreamUsage(BaseModel):
    model: str
    input_tokens: int
    output_tokens: int
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    cost_usd: float
    safety_flags: list[str] = []
    stub_mode: bool = False
