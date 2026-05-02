"""Unit tests for SPEC-photo-ai-calorie-polish macro_grams + dodo_comment parsing.

Direct schema + helper tests — no FastAPI roundtrip needed.
"""

from __future__ import annotations

from app.vision.anthropic_client import _parse_macro_grams
from app.vision.schemas import MacroGrams, RecognizedItem, VisionRecognizeResponse


def test_macro_grams_parses_valid_dict() -> None:
    result = _parse_macro_grams({"carb": 80, "protein": 35, "fat": 28})
    assert isinstance(result, MacroGrams)
    assert result.carb == 80.0
    assert result.protein == 35.0
    assert result.fat == 28.0


def test_macro_grams_returns_none_when_missing() -> None:
    assert _parse_macro_grams(None) is None
    assert _parse_macro_grams("not a dict") is None
    assert _parse_macro_grams(42) is None


def test_macro_grams_returns_none_on_invalid_values() -> None:
    # Negative + non-numeric should both fail validation → None.
    assert _parse_macro_grams({"carb": -1, "protein": 0, "fat": 0}) is None
    assert _parse_macro_grams({"carb": "abc", "protein": 0, "fat": 0}) is None


def test_macro_grams_partial_dict_defaults_missing_to_zero() -> None:
    # AI may omit one macro; we default to 0 (still valid).
    result = _parse_macro_grams({"carb": 80})
    assert result is not None
    assert result.carb == 80.0
    assert result.protein == 0.0
    assert result.fat == 0.0


def test_recognized_item_macro_grams_optional() -> None:
    # Old-shape (no macro) still constructs.
    old = RecognizedItem(name="便當", estimated_kcal=720, confidence=0.9)
    assert old.macro_grams is None

    # New-shape (with macro) preserves it.
    new = RecognizedItem(
        name="便當",
        estimated_kcal=720,
        confidence=0.9,
        macro_grams=MacroGrams(carb=80, protein=35, fat=28),
    )
    assert new.macro_grams is not None
    assert new.macro_grams.protein == 35.0


def test_response_dodo_comment_optional_and_serializable() -> None:
    # Without dodo_comment.
    bare = VisionRecognizeResponse(
        items=[],
        overall_confidence=0.0,
        manual_input_required=True,
        ai_feedback="ok",
        model="stub",
        cost_usd=0.0,
    )
    assert bare.dodo_comment is None
    payload = bare.model_dump()
    assert payload["dodo_comment"] is None

    # With dodo_comment.
    with_comment = VisionRecognizeResponse(
        items=[],
        overall_confidence=0.0,
        manual_input_required=False,
        ai_feedback="ok",
        model="claude-3-5-sonnet",
        cost_usd=0.001,
        dodo_comment="分量充足 ✨ 記得多走走",
    )
    assert with_comment.dodo_comment == "分量充足 ✨ 記得多走走"
