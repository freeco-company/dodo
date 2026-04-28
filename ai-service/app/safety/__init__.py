"""AI safety red-lines (ADR-002 §1.2 + ai-game CLAUDE.md)."""

from app.safety.scanner import (
    HELP_RESOURCE_REPLY,
    MEDICAL_DISCLAIMER,
    SafetyResult,
    append_disclaimer,
    scan_input,
    validate_kcal,
)

__all__ = [
    "MEDICAL_DISCLAIMER",
    "HELP_RESOURCE_REPLY",
    "SafetyResult",
    "append_disclaimer",
    "scan_input",
    "validate_kcal",
]
