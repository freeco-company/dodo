"""Liveness / readiness probes."""

from __future__ import annotations

from fastapi import APIRouter, Depends

from app.config import Settings
from app.deps import settings_dep

router = APIRouter()


@router.get("/healthz")
async def healthz() -> dict[str, str]:
    return {"status": "ok"}


@router.get("/readyz")
async def readyz(settings: Settings = Depends(settings_dep)) -> dict[str, object]:
    return {
        "status": "ok",
        "stub_mode": settings.stub_mode,
        "app_env": settings.app_env,
    }
