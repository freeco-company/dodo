"""POST /v1/vision/recognize — multipart food image recognition."""

from __future__ import annotations

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.auth.jwt_verifier import VerifiedClaims
from app.auth.middleware import require_jwt
from app.callback.client import LaravelCallbackClient, LaravelCallbackError
from app.config import Settings
from app.cost.tracker import CostTracker, UsageRecord
from app.deps import (
    get_callback_client,
    get_cost_tracker,
    get_vision_client,
    settings_dep,
)
from app.safety.scanner import append_disclaimer, validate_kcal
from app.vision.anthropic_client import AnthropicVisionClient
from app.vision.schemas import VisionMealType, VisionRecognizeResponse

logger = logging.getLogger(__name__)
router = APIRouter()

_ALLOWED_MIME = {"image/jpeg", "image/png", "image/webp", "image/heic"}
_MAX_BYTES = 8 * 1024 * 1024  # 8 MB


@router.post("/v1/vision/recognize", response_model=VisionRecognizeResponse)
async def vision_recognize(
    image: Annotated[UploadFile, File(description="Food photo")],
    meal_type: Annotated[VisionMealType, Form()] = "lunch",
    claims: VerifiedClaims = Depends(require_jwt),
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
    if manual_required:
        safety_flags.append("low_confidence_manual_input_required")

    # Always append disclaimer to feedback.
    feedback = append_disclaimer(result.ai_feedback)
    if manual_required:
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
    )

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
