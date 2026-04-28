"""Pydantic schemas for vision endpoints."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class RecognizedItem(BaseModel):
    name: str = Field(min_length=1, max_length=80)
    estimated_kcal: int = Field(ge=0, le=5000)
    confidence: float = Field(ge=0.0, le=1.0)


class VisionRecognizeResponse(BaseModel):
    items: list[RecognizedItem]
    overall_confidence: float = Field(ge=0.0, le=1.0)
    manual_input_required: bool
    ai_feedback: str
    model: str
    cost_usd: float
    safety_flags: list[str] = []
    stub_mode: bool = False


VisionMealType = Literal["breakfast", "lunch", "dinner", "snack"]
