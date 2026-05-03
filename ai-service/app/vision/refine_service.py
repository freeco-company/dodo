"""SPEC-photo-ai-correction-v2 PR #3 — vision refine service.

Refines a single dish from the original image + user hint. The user has just
told us "this isn't 白飯, it's 糙米" or "this is actually 1.5× the portion AI
estimated"; we re-derive the macros honouring the hint.

Stub mode applies the hint deterministically (food_key swap → keep AI macros
but rename; portion change → linear-scale kcal/carb/protein/fat). Real mode
will call Anthropic vision with the original image + hint as context — that
prompt path is a follow-up patch (TODO marker below) so PR #3 ships a
testable, no-cost baseline today.
"""

from __future__ import annotations

import copy
from dataclasses import dataclass

from app.vision.schemas import (
    RefinedDish,
    RefineDishInput,
    RefineUserHint,
    VisionRefineRequest,
    VisionRefineResponse,
)

_FOOD_NAME_BY_KEY = {
    "rice_white": "白飯",
    "rice_brown": "糙米",
    "rice_grain": "五穀飯",
    "rice_purple": "紫米",
    "chicken_thigh": "雞腿",
    "chicken_breast": "雞胸",
    "egg_braised": "滷蛋",
    "cabbage": "高麗菜",
}


@dataclass
class RefineResult:
    response: VisionRefineResponse
    cost_usd: float


class StubRefineService:
    """Deterministic stub — no Anthropic call. Used in tests + STUB_MODE=true."""

    def refine(self, payload: VisionRefineRequest) -> VisionRefineResponse:
        dishes_out: list[RefinedDish] = []
        for idx, orig in enumerate(payload.original_dishes):
            if idx == payload.user_hint.dish_index:
                dishes_out.append(_apply_hint_stub(orig, payload.user_hint))
            else:
                dishes_out.append(_passthrough(orig))

        return VisionRefineResponse(
            dishes=dishes_out,
            model="stub-refine",
            cost_usd=0.0,
            stub_mode=True,
            user_hint=payload.user_hint,
        )


def _passthrough(d: RefineDishInput) -> RefinedDish:
    return RefinedDish(
        food_name=d.food_name,
        food_key=d.food_key,
        portion_multiplier=d.portion_multiplier,
        kcal=d.kcal,
        carb_g=d.carb_g,
        protein_g=d.protein_g,
        fat_g=d.fat_g,
        confidence=d.confidence,
        candidates=[],
    )


def _apply_hint_stub(orig: RefineDishInput, hint: RefineUserHint) -> RefinedDish:
    """Stub: rename + linear-scale macros.

    A food swap without macro re-derivation isn't physically correct (rice
    white vs brown have different protein/fibre), but stub mode is for tests +
    dev — when STUB_MODE=false in prod the real Anthropic prompt re-derives
    the macros properly using the original image as context.
    """
    out = copy.deepcopy(orig)
    if hint.new_food_key is not None:
        out.food_key = hint.new_food_key
        out.food_name = _FOOD_NAME_BY_KEY.get(hint.new_food_key, hint.new_food_name or orig.food_name)
    elif hint.new_food_name is not None:
        out.food_name = hint.new_food_name

    if hint.new_portion is not None and hint.new_portion != orig.portion_multiplier:
        scale = hint.new_portion / max(orig.portion_multiplier, 0.01)
        out.portion_multiplier = hint.new_portion
        out.kcal = int(round(orig.kcal * scale))
        out.carb_g = round(orig.carb_g * scale, 2)
        out.protein_g = round(orig.protein_g * scale, 2)
        out.fat_g = round(orig.fat_g * scale, 2)

    return RefinedDish(
        food_name=out.food_name,
        food_key=out.food_key,
        portion_multiplier=out.portion_multiplier,
        kcal=out.kcal,
        carb_g=out.carb_g,
        protein_g=out.protein_g,
        fat_g=out.fat_g,
        confidence=0.85,  # stub: refined dish always lands at threshold
        candidates=[],
    )


# TODO(PR #3.5): real Anthropic vision prompt path.
#   When STUB_MODE=false:
#   - download image_url (or accept image bytes from Laravel proxy)
#   - prompt: "Original AI estimate was these dishes [...]. The user says
#     dish #N is actually {new_food_key|new_food_name} at {new_portion}×.
#     Re-derive macros for that one dish using the image as visual ground
#     truth. Other dishes: leave as-is unless the swap implies a structural
#     change to the meal composition. Return same schema as recognize."
#   - inject user_calibration hints from Laravel:
#     "user typically logs rice_white at -15% AI's portion estimate"
#   - cost guard: charge ~30% of recognize cost (single-dish re-derivation
#     uses fewer output tokens)
