"""Safety scanner — implements the four hard red-lines.

Red-line summary (see ai-game/CLAUDE.md §AI 安全與內容紅線):
  1. Trigger-word detection -> force help-resource reply, no diet output.
  2. Kcal floor -> refuse < 1200 kcal/day or < 800 kcal/meal plans.
  3. Medical disclaimer suffix on every assistant reply.
  4. Vision confidence < 0.85 -> manual_input_required (handled in vision module).

This is a v0 lexical scanner — production should layer an LLM-based classifier on
top, but the lexical layer is the floor and must never regress.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Final

# zh-TW phrases drawn from existing tools/safety_scan.mjs (Node v0).
# Keep deliberately conservative; false-positives are acceptable, false-negatives
# are not — a missed trigger is a P0 incident per CLAUDE.md.
_TRIGGER_PATTERNS: Final[list[tuple[str, re.Pattern[str]]]] = [
    ("self_harm", re.compile(r"(不想活|想消失|想死|活著沒意義|結束生命|自殺)")),
    ("eating_disorder", re.compile(r"(催吐|拒食|暴食|厭食)")),
    ("extreme_fasting", re.compile(r"(斷食\s*\d+\s*天|\d+\s*天\s*沒\s*吃|餓\s*\d+\s*天)")),
    ("medical_advice_request", re.compile(r"(可以停.*?藥|停掉.*?藥|不吃.*?藥)")),
    ("extreme_diet", re.compile(r"(只吃\s*\d{1,3}\s*卡|一天只吃|每天只吃\s*\d+\s*卡)")),
]

MEDICAL_DISCLAIMER: Final[str] = (
    "\n\n---\n本服務提供一般健康資訊，非醫療建議，請諮詢醫師或營養師。"
)

HELP_RESOURCE_REPLY: Final[str] = (
    "我聽到妳了，妳並不孤單。如果妳正在經歷強烈的負面情緒、進食困擾或想傷害自己的念頭，"
    "請優先尋求專業協助：\n"
    "・衛福部安心專線 1925（24h 免付費）\n"
    "・生命線 1995\n"
    "・張老師專線 1980\n\n"
    "在妳取得專業支持之前，朵朵會暫停飲食建議。如果妳願意，我可以陪妳聊聊現在的感受。"
    + MEDICAL_DISCLAIMER
)


@dataclass
class SafetyResult:
    blocked: bool
    flags: list[str] = field(default_factory=list)
    forced_reply: str | None = None  # set when blocked

    @property
    def safe(self) -> bool:
        return not self.blocked


def scan_input(text: str) -> SafetyResult:
    """Scan a user-supplied message for red-line triggers."""
    flags: list[str] = []
    for label, pattern in _TRIGGER_PATTERNS:
        if pattern.search(text):
            flags.append(label)
    if flags:
        return SafetyResult(
            blocked=True,
            flags=flags,
            forced_reply=HELP_RESOURCE_REPLY,
        )
    return SafetyResult(blocked=False, flags=[], forced_reply=None)


def validate_kcal(
    *,
    daily_kcal: int | None = None,
    per_meal_kcal: int | None = None,
    min_daily: int,
    min_meal: int,
) -> SafetyResult:
    """Reject plans below the kcal floor."""
    flags: list[str] = []
    if daily_kcal is not None and daily_kcal < min_daily:
        flags.append("kcal_below_daily_floor")
    if per_meal_kcal is not None and per_meal_kcal < min_meal:
        flags.append("kcal_below_meal_floor")
    if flags:
        return SafetyResult(
            blocked=True,
            flags=flags,
            forced_reply=(
                f"為了妳的健康，我不能建議低於每日 {min_daily} 大卡或單餐 {min_meal} 大卡的方案。"
                "極低熱量飲食可能造成營養不良、代謝下降與情緒波動。"
                "讓我們改為設計兼顧減脂與營養的方案。"
                + MEDICAL_DISCLAIMER
            ),
        )
    return SafetyResult(blocked=False, flags=[], forced_reply=None)


def append_disclaimer(reply: str) -> str:
    """Ensure every assistant reply ends with the medical disclaimer (idempotent)."""
    if MEDICAL_DISCLAIMER.strip() in reply:
        return reply
    return reply.rstrip() + MEDICAL_DISCLAIMER
