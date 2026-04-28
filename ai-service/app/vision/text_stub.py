"""Stub fallback for text recognition (no Anthropic API key).

Returns deterministic empty / low-confidence result so the manual-input path is
exercised end-to-end during contract tests.
"""

from __future__ import annotations

from app.vision.text_service import TextRecognitionResult


def stub_text_result() -> TextRecognitionResult:
    """Match the spec'd shape: foods=[], confidence=0.5, manual_input_required=true."""
    return TextRecognitionResult(
        foods=[],
        overall_confidence=0.5,
        ai_feedback="目前 LLM 服務未連線（stub），無法解析口述內容，請手動輸入。",
        model="stub",
        input_tokens=0,
        output_tokens=0,
    )
