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


# ---------- SPEC-photo-ai-correction-v2 PR #3 ----------
#
# /v1/vision/refine — re-infer a single dish from the original image + user hint.
# Used by Laravel MealCorrectionService::refineDishViaAi after the user changes
# food_key or portion_multiplier and asks for a fresh AI estimate.


class RefineDishInput(BaseModel):
    """A dish from the previous recognize call, sent back as context."""

    food_name: str = Field(min_length=1, max_length=80)
    food_key: str | None = Field(default=None, max_length=64)
    portion_multiplier: float = Field(ge=0.25, le=3.0, default=1.0)
    kcal: int = Field(ge=0, le=5000, default=0)
    carb_g: float = Field(ge=0.0, le=500.0, default=0.0)
    protein_g: float = Field(ge=0.0, le=500.0, default=0.0)
    fat_g: float = Field(ge=0.0, le=500.0, default=0.0)
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)


class RefineUserHint(BaseModel):
    """Which dish to refine + what the user said it actually is."""

    dish_index: int = Field(ge=0)
    new_food_key: str | None = Field(default=None, max_length=64)
    new_food_name: str | None = Field(default=None, max_length=80)
    new_portion: float | None = Field(default=None, ge=0.25, le=3.0)


class VisionRefineRequest(BaseModel):
    user_uuid: str = Field(min_length=1, max_length=64)
    image_url: str | None = Field(default=None, max_length=2048)
    original_dishes: list[RefineDishInput]
    user_hint: RefineUserHint


class RefinedDish(BaseModel):
    food_name: str = Field(min_length=1, max_length=80)
    food_key: str | None = Field(default=None, max_length=64)
    portion_multiplier: float = Field(ge=0.25, le=3.0)
    kcal: int = Field(ge=0, le=5000)
    carb_g: float = Field(ge=0.0, le=500.0)
    protein_g: float = Field(ge=0.0, le=500.0)
    fat_g: float = Field(ge=0.0, le=500.0)
    confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    candidates: list[dict[str, object]] = Field(default_factory=list)


class VisionRefineResponse(BaseModel):
    dishes: list[RefinedDish]
    model: str
    cost_usd: float
    stub_mode: bool = False
    user_hint: RefineUserHint
