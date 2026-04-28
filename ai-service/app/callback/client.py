"""HTTP client that POSTs AI results back to Laravel internal endpoints.

ADR-002 §3.4 / §3.2 — Python never writes the business DB; it sends results to
``/api/internal/ai-callback/*`` with an HMAC signature header.
"""

from __future__ import annotations

import logging
from typing import Any

import httpx

from app.callback.signing import canonical_json, sign_payload
from app.config import Settings

logger = logging.getLogger(__name__)


class LaravelCallbackError(Exception):
    """Raised when the Laravel callback returns non-2xx."""


class LaravelCallbackClient:
    """Thin HMAC-signed POSTer to Laravel internal endpoints."""

    def __init__(self, settings: Settings, *, timeout: float = 10.0) -> None:
        self._settings = settings
        self._timeout = timeout

    async def post_chat_message(
        self,
        *,
        session_id: str,
        role: str,
        content: str,
        tokens_in: int,
        tokens_out: int,
        cost_usd: float,
        safety_flags: list[str],
        model: str,
    ) -> dict[str, Any]:
        return await self._post(
            "/api/internal/ai-callback/chat-message",
            {
                "sessionId": session_id,
                "role": role,
                "content": content,
                "tokensIn": tokens_in,
                "tokensOut": tokens_out,
                "costUsd": cost_usd,
                "safetyFlags": safety_flags,
                "model": model,
            },
        )

    async def post_food_recognition(
        self,
        *,
        user_uuid: str,
        meal_type: str,
        items: list[dict[str, Any]],
        confidence: float,
        manual_input_required: bool,
        ai_feedback: str,
        model: str,
        cost_usd: float,
    ) -> dict[str, Any]:
        return await self._post(
            "/api/internal/ai-callback/food-recognition",
            {
                "userUuid": user_uuid,
                "mealType": meal_type,
                "items": items,
                "confidence": confidence,
                "manualInputRequired": manual_input_required,
                "aiFeedback": ai_feedback,
                "model": model,
                "costUsd": cost_usd,
            },
        )

    async def post_cost_event(
        self,
        *,
        user_uuid: str,
        endpoint: str,
        model: str,
        tokens_in: int,
        tokens_out: int,
        cache_read: int,
        cache_write: int,
        cost_usd: float,
    ) -> dict[str, Any]:
        return await self._post(
            "/api/internal/ai-callback/cost-event",
            {
                "userUuid": user_uuid,
                "endpoint": endpoint,
                "model": model,
                "tokensIn": tokens_in,
                "tokensOut": tokens_out,
                "cacheReadTokens": cache_read,
                "cacheWriteTokens": cache_write,
                "costUsd": cost_usd,
            },
        )

    async def _post(self, path: str, payload: dict[str, Any]) -> dict[str, Any]:
        url = f"{self._settings.laravel_internal_base_url.rstrip('/')}{path}"
        secret = self._settings.laravel_internal_shared_secret
        body = canonical_json(payload)
        signature = sign_payload(payload, secret)
        headers = {
            "Content-Type": "application/json",
            "X-Internal-Signature": signature,
        }
        try:
            async with httpx.AsyncClient(timeout=self._timeout) as client:
                resp = await client.post(url, content=body, headers=headers)
        except httpx.HTTPError as exc:
            logger.warning("laravel callback failed (transport): %s url=%s", exc, url)
            raise LaravelCallbackError(str(exc)) from exc

        if resp.status_code >= 400:
            logger.warning(
                "laravel callback non-2xx: status=%d url=%s body=%s",
                resp.status_code,
                url,
                resp.text[:300],
            )
            raise LaravelCallbackError(
                f"laravel callback {resp.status_code}: {resp.text[:200]}"
            )
        try:
            data: dict[str, Any] = resp.json()
        except ValueError:
            data = {"raw": resp.text}
        return data
