"""SPEC-progress-ritual-v1 PR #3 — output sanitizer for ritual narratives.

Same approach as insight_sanitizer (SPEC-cross-metric-insight-v1 PR #3) — the
two are independent SPEC chains so don't share a wordlist file (intentional;
each can evolve its own coverage). Wordlist is the strict ritual + body subset
（額外覆蓋身材外觀詞）because progress photo / weight context is the highest
食安法 risk.
"""

from __future__ import annotations

import re
from typing import Final

_FORBIDDEN_TERMS: Final[list[str]] = [
    # 體重 / 減重 area
    "減重", "減脂", "燃脂", "燃燒脂肪", "瘦身", "塑身", "速瘦", "暴瘦", "變瘦",
    # 醫療 / 療效
    "排毒", "排油", "治療", "療效", "抑制食慾", "加速代謝",
    "提升免疫力", "抗病", "抗氧化", "消炎", "抑菌",
    # 食品 functional claims
    "代餐", "取代正餐", "低 GI", "高纖維", "高蛋白", "飽足感", "修復肌膚",
    # 進度照特別禁區（身材外觀評論）
    "妳變漂亮", "身材變好", "腰瘦", "胸瘦", "腿瘦", "屁股翹", "腰圍", "胸圍",
    "BMI", "體脂率",
]

_PATTERN: Final[re.Pattern[str]] = re.compile("|".join(re.escape(t) for t in _FORBIDDEN_TERMS))


def ritual_text_violates(text: str) -> list[str]:
    """Return forbidden terms found in `text`. Empty = clean."""
    if not text:
        return []
    matches = _PATTERN.findall(text)
    seen: set[str] = set()
    out: list[str] = []
    for m in matches:
        if m not in seen:
            seen.add(m)
            out.append(m)
    return out


def ritual_passes(text: str) -> bool:
    return ritual_text_violates(text) == []
