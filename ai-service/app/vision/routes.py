"""POST /v1/vision/recognize — multipart food image recognition.
POST /v1/vision/recognize-text — JSON spoken/typed meal description recognition.
"""

from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.auth.jwt_verifier import VerifiedClaims
from app.auth.middleware import require_jwt_or_internal
from app.callback.client import LaravelCallbackClient, LaravelCallbackError
from app.config import Settings
from app.cost.tracker import CostTracker, UsageRecord
from app.deps import (
    get_callback_client,
    get_cost_tracker,
    get_text_recognizer,
    get_vision_client,
    settings_dep,
)
from app.safety.scanner import append_disclaimer, scan_input, validate_kcal
from app.vision.anthropic_client import AnthropicVisionClient
from app.vision.schemas import (
    VisionMealType,
    VisionRecognizeResponse,
    VisionRecognizeTextRequest,
    VisionRecognizeTextResponse,
)
from app.vision.text_service import AnthropicTextRecognizer

logger = logging.getLogger(__name__)
router = APIRouter()

_ALLOWED_MIME = {"image/jpeg", "image/png", "image/webp", "image/heic"}
_MAX_BYTES = 8 * 1024 * 1024  # 8 MB


@router.post("/v1/vision/recognize", response_model=VisionRecognizeResponse)
async def vision_recognize(
    image: Annotated[UploadFile, File(description="Food photo")],
    meal_type: Annotated[VisionMealType, Form()] = "lunch",
    claims: VerifiedClaims = Depends(require_jwt_or_internal),
    vision_client: AnthropicVisionClient = Depends(get_vision_client),
    cost_tracker: CostTracker = Depends(get_cost_tracker),
    callback: LaravelCallbackClient = Depends(get_callback_client),
    settings: Settings = Depends(settings_dep),
) -> VisionRecognizeResponse:
    if image.content_type not in _ALLOWED_MIME:
        raise HTTPException(
            status_code=status.HTTP_415_UNSUPPORTED_MEDIA_TYPE,
            detail=f"unsupported image type: {image.content_type}",
        )
    body = await image.read()
    if len(body) == 0:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="empty image",
        )
    if len(body) > _MAX_BYTES:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"image too large (max {_MAX_BYTES} bytes)",
        )

    result = await vision_client.recognize(body, image.content_type, meal_type)

    # Confidence floor — manual input required when below threshold.
    threshold = settings.vision_confidence_threshold
    manual_required = result.overall_confidence < threshold

    # Kcal floor red-line on per-meal estimate.
    total_kcal = sum(it.estimated_kcal for it in result.items)
    kcal_check = validate_kcal(
        per_meal_kcal=total_kcal if total_kcal > 0 else None,
        min_daily=settings.min_kcal_per_day,
        min_meal=settings.min_kcal_per_meal,
    )
    safety_flags = list(kcal_check.flags)
    if not result.is_food:
        # User uploaded a non-food image. Force manual_required so caller
        # short-circuits the celebration / meal-record path; surface a
        # `not_food` flag so analytics can throttle abusive uploaders.
        manual_required = True
        safety_flags.append("not_food")
    elif manual_required:
        safety_flags.append("low_confidence_manual_input_required")

    # Always append disclaimer to feedback (skipped on not_food — message is
    # already user-facing UI guidance, disclaimer would feel jarring).
    feedback = result.ai_feedback if not result.is_food else append_disclaimer(result.ai_feedback)
    if manual_required and result.is_food:
        feedback = (
            "辨識信心偏低，請手動修正項目以確保正確紀錄。\n\n" + feedback
        )

    record = UsageRecord(
        user_uuid=claims.sub,
        model=result.model,
        input_tokens=result.input_tokens,
        output_tokens=result.output_tokens,
        endpoint="/v1/vision/recognize",
        flags=safety_flags,
    )
    cost_tracker.record(record)

    response = VisionRecognizeResponse(
        items=result.items,
        overall_confidence=result.overall_confidence,
        manual_input_required=manual_required,
        ai_feedback=feedback,
        model=result.model,
        cost_usd=record.cost_usd,
        safety_flags=safety_flags,
        stub_mode=settings.stub_mode,
        is_food=result.is_food,
        dodo_comment=result.dodo_comment,
    )

    # Skip Laravel persistence on not_food — there's nothing legitimate to
    # record; we already burned the AI cost (which is the abuser-deterrent
    # already in place — they don't get a free retry).
    if not result.is_food:
        return response

    # Best-effort persist via Laravel internal callback.
    try:
        await callback.post_food_recognition(
            user_uuid=claims.sub,
            meal_type=meal_type,
            items=[it.model_dump() for it in result.items],
            confidence=result.overall_confidence,
            manual_input_required=manual_required,
            ai_feedback=feedback,
            model=result.model,
            cost_usd=record.cost_usd,
        )
    except LaravelCallbackError as exc:
        logger.warning(
            "vision callback to Laravel failed, response still returned: user=%s err=%s",
            claims.sub,
            exc,
        )

    return response


@router.post(
    "/v1/vision/recognize-text",
    response_model=VisionRecognizeTextResponse,
)
async def vision_recognize_text(
    payload: VisionRecognizeTextRequest,
    claims: VerifiedClaims = Depends(require_jwt_or_internal),
    recognizer: AnthropicTextRecognizer = Depends(get_text_recognizer),
    cost_tracker: CostTracker = Depends(get_cost_tracker),
    callback: LaravelCallbackClient = Depends(get_callback_client),
    settings: Settings = Depends(settings_dep),
) -> VisionRecognizeTextResponse:
    """Recognize foods from a spoken/typed description.

    Runs all four red-lines:
      1. Trigger-word scan on description -> blocks output, returns help reply
      2. Kcal floor check on aggregated total -> blocks output if < per-meal floor
      3. Medical disclaimer suffix on every reply (idempotent)
      4. Confidence < threshold -> manual_input_required=True
    """
    # Red-line #1: scan user description for distress / disordered-eating triggers.
    # We scan description (and hint if present) — anything that hits forces the
    # help-resource reply with no diet output.
    safety_input = payload.description
    if payload.hint:
        safety_input = f"{safety_input}\n{payload.hint}"
    trigger_check = scan_input(safety_input)
    if trigger_check.blocked:
        # No LLM call, no cost. Forced help reply already carries disclaimer.
        return VisionRecognizeTextResponse(
            foods=[],
            total_calories=0,
            confidence=0.0,
            manual_input_required=True,
            ai_feedback=trigger_check.forced_reply or "",
            model="safety-guard",
            cost_usd=0.0,
            safety_flags=list(trigger_check.flags),
            stub=settings.stub_mode,
        )

    result = await recognizer.recognize(
        description=payload.description,
        hint=payload.hint,
    )

    # Red-line #4: confidence floor.
    threshold = settings.vision_confidence_threshold
    manual_required = result.overall_confidence < threshold

    total_calories = sum(f.estimated_kcal for f in result.foods)

    # Red-line #2: kcal floor on the per-meal total (when we have one).
    kcal_check = validate_kcal(
        per_meal_kcal=total_calories if total_calories > 0 else None,
        min_daily=settings.min_kcal_per_day,
        min_meal=settings.min_kcal_per_meal,
    )
    safety_flags = list(kcal_check.flags)
    if manual_required:
        safety_flags.append("low_confidence_manual_input_required")

    # Red-line #3: append disclaimer (idempotent).
    feedback = append_disclaimer(result.ai_feedback)
    if manual_required and not result.foods:
        feedback = "口述內容辨識信心偏低，請手動輸入餐點。\n\n" + feedback

    record = UsageRecord(
        user_uuid=claims.sub,
        model=result.model,
        input_tokens=result.input_tokens,
        output_tokens=result.output_tokens,
        endpoint="/v1/vision/recognize-text",
        flags=safety_flags,
    )
    cost_tracker.record(record)

    response = VisionRecognizeTextResponse(
        foods=result.foods,
        total_calories=total_calories,
        confidence=result.overall_confidence,
        manual_input_required=manual_required,
        ai_feedback=feedback,
        model=result.model,
        cost_usd=record.cost_usd,
        safety_flags=safety_flags,
        stub=settings.stub_mode,
    )

    # Best-effort persist via Laravel internal callback (re-using food-recognition
    # endpoint — Laravel doesn't care if it came from image or text).
    try:
        await callback.post_food_recognition(
            user_uuid=claims.sub,
            meal_type="text-described",
            items=[f.model_dump() for f in result.foods],
            confidence=result.overall_confidence,
            manual_input_required=manual_required,
            ai_feedback=feedback,
            model=result.model,
            cost_usd=record.cost_usd,
        )
    except LaravelCallbackError as exc:
        logger.warning(
            "text-recog callback to Laravel failed, response still returned: "
            "user=%s err=%s",
            claims.sub,
            exc,
        )

    return response
