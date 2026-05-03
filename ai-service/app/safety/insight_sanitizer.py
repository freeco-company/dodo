"""SPEC-cross-metric-insight-v1 PR #3 — output sanitizer for cross-metric insights.

Mirrors the集團 LegalContentSanitizer wordlist (food / health-act compliance).
Used by /v1/reports/narrative as a post-LLM check: if the dynamic narrative
contains any forbidden term, fall back to the deterministic free template
(which has already passed Laravel-side ContentGuardTest).

Keep wordlist conservative: false positives → fall back to template (safe);
false negatives → 食安法 / 健康食品管理法 violation (P0).
"""

from __future__ import annotations

import re
from typing import Final

# Terms forbidden in user-facing 健康行為 / 體重 narratives.
# Subset focused on insight context; full list in
# packages/pandora-shared/Compliance/LegalContentSanitizer.
_FORBIDDEN_TERMS: Final[list[str]] = [
    "減重",
    "減脂",
    "燃脂",
    "燃燒脂肪",
    "瘦身",
    "塑身",
    "速瘦",
    "暴瘦",
    "變瘦",
    "排毒",
    "排油",
    "治療",
    "療效",
    "抑制食慾",
    "加速代謝",
    "提升免疫力",
    "抗病",
    "抗氧化",
    "消炎",
    "抑菌",
    "代餐",
    "取代正餐",
    "低 GI",
    "高纖維",
    "高蛋白",
    "飽足感",
    "修復肌膚",
]

_PATTERN: Final[re.Pattern[str]] = re.compile("|".join(re.escape(t) for t in _FORBIDDEN_TERMS))


def insight_text_violates(text: str) -> list[str]:
    """Return list of forbidden terms found in `text`. Empty list = clean."""
    if not text:
        return []
    matches = _PATTERN.findall(text)
    # Dedup but preserve order.
    seen: set[str] = set()
    out: list[str] = []
    for m in matches:
        if m not in seen:
            seen.add(m)
            out.append(m)
    return out


def insight_passes(text: str) -> bool:
    return insight_text_violates(text) == []
