"""Pydantic schemas for vision endpoints."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class MacroGrams(BaseModel):
    """三大營養素估算（公克）。

    SPEC-photo-ai-calorie-polish §4.1：拍照 AI 回傳每道菜的 macro 估算，
    供前端 macro ring 視覺化。AI 不確定時整個欄位為 None（不要硬猜 0）。
    """

    carb: float = Field(ge=0.0, le=500.0)
    protein: float = Field(ge=0.0, le=500.0)
    fat: float = Field(ge=0.0, le=500.0)


class RecognizedItem(BaseModel):
    name: str = Field(min_length=1, max_length=80)
    estimated_kcal: int = Field(ge=0, le=5000)
    confidence: float = Field(ge=0.0, le=1.0)
    # Optional 為了向後相容：舊呼叫端 / stub / 舊版 prompt 解析失敗 → None。
    macro_grams: MacroGrams | None = None


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
    # 朵朵 NPC 一句點評（≤25 字）；AI 不可解析或 stub → None / 空字串。
    # SPEC-photo-ai-calorie-polish §3 progressive disclosure 結果頁第二段。
    dodo_comment: str | None = None


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
