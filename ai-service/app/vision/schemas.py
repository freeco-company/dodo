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
    is_food: bool = True


VisionMealType = Literal["breakfast", "lunch", "dinner", "snack"]


class VisionRecognizeTextRequest(BaseModel):
    """Body for /v1/vision/recognize-text — user types/speaks the food they ate."""

    user_uuid: str = Field(min_length=1, max_length=64)
    description: str = Field(min_length=1, max_length=1000)
    hint: str | None = Field(default=None, max_length=200)


class RecognizedTextFood(BaseModel):
    name: str = Field(min_length=1, max_length=80)
    estimated_kcal: int = Field(ge=0, le=5000)
    confidence: float = Field(ge=0.0, le=1.0)


class VisionRecognizeTextResponse(BaseModel):
    foods: list[RecognizedTextFood]
    total_calories: int = Field(ge=0)
    confidence: float = Field(ge=0.0, le=1.0)
    manual_input_required: bool
    ai_feedback: str
    model: str
    cost_usd: float
    safety_flags: list[str] = []
    stub: bool = False
