"""FastAPI application entrypoint for the Dodo AI service."""

from __future__ import annotations

import logging
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.auth.jwt_verifier import get_jwt_verifier
from app.chat.routes import router as chat_router
from app.config import get_settings
from app.health.routes import router as health_router
from app.vision.routes import router as vision_router

logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncIterator[None]:
    settings = get_settings()
    logging.basicConfig(level=settings.log_level)
    if settings.stub_mode:
        logger.warning(
            "Dodo AI service starting in STUB MODE (ANTHROPIC_API_KEY not set). "
            "JWT/safety/callback paths active; LLM calls are canned."
        )
    # Best-effort warm-up of JWT public key — failures tolerated in dev.
    verifier = get_jwt_verifier()
    try:
        await verifier.refresh_public_key()
    except Exception as exc:  # pragma: no cover - dev convenience
        logger.warning("JWT public key warm-up failed: %s", exc)
    yield


app = FastAPI(
    title="Dodo AI Service",
    version="0.1.0",
    description="Dodo chat streaming + food vision recognition (ADR-002 Phase B).",
    lifespan=lifespan,
)

app.include_router(health_router, tags=["health"])
app.include_router(chat_router, tags=["chat"])
app.include_router(vision_router, tags=["vision"])
