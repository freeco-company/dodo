"""POST /v1/chat/stream — SSE streaming chat endpoint."""

from __future__ import annotations

import json
import logging
from collections.abc import AsyncIterator

from fastapi import APIRouter, Depends, HTTPException, status
from fastapi.responses import StreamingResponse

from app.auth.jwt_verifier import VerifiedClaims
from app.auth.middleware import require_jwt_or_internal
from app.callback.client import LaravelCallbackClient, LaravelCallbackError
from app.chat.anthropic_client import AnthropicChatClient
from app.chat.schemas import ChatStreamRequest
from app.config import Settings
from app.cost.tracker import CostTracker, UsageRecord
from app.deps import (
    get_callback_client,
    get_chat_client,
    get_cost_tracker,
    settings_dep,
)
from app.safety.scanner import (
    MEDICAL_DISCLAIMER,
    append_disclaimer,
    scan_input,
)

logger = logging.getLogger(__name__)
router = APIRouter()


def _sse_event(event: str, data: dict[str, object]) -> str:
    """Format a single SSE frame."""
    return f"event: {event}\ndata: {json.dumps(data, ensure_ascii=False)}\n\n"


@router.post("/v1/chat/stream")
async def chat_stream(
    request: ChatStreamRequest,
    claims: VerifiedClaims = Depends(require_jwt_or_internal),
    chat_client: AnthropicChatClient = Depends(get_chat_client),
    cost_tracker: CostTracker = Depends(get_cost_tracker),
    callback: LaravelCallbackClient = Depends(get_callback_client),
    settings: Settings = Depends(settings_dep),
) -> StreamingResponse:
    """SSE streaming chat. Header: Authorization: Bearer <Pandora Core JWT>.

    Frames:
      - `event: delta`  data: {"text": "..."}
      - `event: safety` data: {"flags": [...], "blocked": true}
      - `event: usage`  data: {"model": ..., "tokens_in": ..., ...}
      - `event: done`   data: {}
    """
    # Cost guard — pre-flight quota check (per-user daily token budget).
    if cost_tracker.is_over_budget(claims.sub, settings.default_daily_token_budget):
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="daily token budget exceeded",
        )

    # Safety scan on inbound user message — blocking path.
    safety = scan_input(request.message)

    async def generator() -> AsyncIterator[str]:
        if safety.blocked:
            assert safety.forced_reply is not None
            yield _sse_event(
                "safety",
                {"flags": safety.flags, "blocked": True},
            )
            yield _sse_event("delta", {"text": safety.forced_reply})
            yield _sse_event(
                "usage",
                {
                    "model": "safety_override",
                    "tokens_in": 0,
                    "tokens_out": 0,
                    "cost_usd": 0.0,
                    "safety_flags": safety.flags,
                    "stub_mode": settings.stub_mode,
                },
            )
            await _persist(
                callback=callback,
                session_id=request.session_id,
                user_uuid=claims.sub,
                content=safety.forced_reply,
                tokens_in=0,
                tokens_out=0,
                cost_usd=0.0,
                safety_flags=safety.flags,
                model="safety_override",
            )
            yield _sse_event("done", {})
            return

        # Real / stub stream.
        full_text_parts: list[str] = []
        final_in = final_out = cache_r = cache_w = 0
        model_used = "unknown"

        async for chunk in chat_client.stream(request):
            if chunk.done:
                final_in = chunk.input_tokens
                final_out = chunk.output_tokens
                cache_r = chunk.cache_read_tokens
                cache_w = chunk.cache_write_tokens
                model_used = chunk.model
                break
            if chunk.delta:
                full_text_parts.append(chunk.delta)
                yield _sse_event("delta", {"text": chunk.delta})

        # Always append the medical disclaimer to the reply we persist.
        full_reply = append_disclaimer("".join(full_text_parts))
        # And emit the disclaimer suffix as a final delta so the client sees it.
        yield _sse_event("delta", {"text": MEDICAL_DISCLAIMER})

        # Cost accounting
        record = UsageRecord(
            user_uuid=claims.sub,
            model=model_used,
            input_tokens=final_in,
            output_tokens=final_out,
            cache_read_tokens=cache_r,
            cache_write_tokens=cache_w,
            endpoint="/v1/chat/stream",
            flags=safety.flags,
        )
        cost_tracker.record(record)

        yield _sse_event(
            "usage",
            {
                "model": model_used,
                "tokens_in": final_in,
                "tokens_out": final_out,
                "cache_read_tokens": cache_r,
                "cache_write_tokens": cache_w,
                "cost_usd": record.cost_usd,
                "safety_flags": safety.flags,
                "stub_mode": settings.stub_mode,
            },
        )

        await _persist(
            callback=callback,
            session_id=request.session_id,
            user_uuid=claims.sub,
            content=full_reply,
            tokens_in=final_in,
            tokens_out=final_out,
            cost_usd=record.cost_usd,
            safety_flags=safety.flags,
            model=model_used,
        )
        yield _sse_event("done", {})

    return StreamingResponse(
        generator(),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )


async def _persist(
    *,
    callback: LaravelCallbackClient,
    session_id: str,
    user_uuid: str,
    content: str,
    tokens_in: int,
    tokens_out: int,
    cost_usd: float,
    safety_flags: list[str],
    model: str,
) -> None:
    """Best-effort callback to Laravel — never crash the stream on transport error."""
    try:
        await callback.post_chat_message(
            session_id=session_id,
            role="assistant",
            content=content,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            cost_usd=cost_usd,
            safety_flags=safety_flags,
            model=model,
        )
    except LaravelCallbackError as exc:
        logger.warning(
            "chat callback to Laravel failed, message not persisted: user=%s session=%s err=%s",
            user_uuid,
            session_id,
            exc,
        )
