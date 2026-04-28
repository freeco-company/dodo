"""Shared FastAPI dependency providers."""

from __future__ import annotations

from functools import lru_cache

from app.callback.client import LaravelCallbackClient
from app.chat.anthropic_client import AnthropicChatClient
from app.config import Settings, get_settings
from app.cost.tracker import CostTracker
from app.vision.anthropic_client import AnthropicVisionClient


@lru_cache(maxsize=1)
def get_chat_client() -> AnthropicChatClient:
    return AnthropicChatClient(get_settings())


@lru_cache(maxsize=1)
def get_vision_client() -> AnthropicVisionClient:
    return AnthropicVisionClient(get_settings())


@lru_cache(maxsize=1)
def get_callback_client() -> LaravelCallbackClient:
    return LaravelCallbackClient(get_settings())


@lru_cache(maxsize=1)
def get_cost_tracker() -> CostTracker:
    return CostTracker()


def settings_dep() -> Settings:
    return get_settings()
