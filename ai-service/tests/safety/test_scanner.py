"""Safety scanner red-line tests — these MUST stay green."""

from __future__ import annotations

import pytest

from app.safety.scanner import (
    HELP_RESOURCE_REPLY,
    MEDICAL_DISCLAIMER,
    append_disclaimer,
    scan_input,
    validate_kcal,
)


@pytest.mark.parametrize(
    "phrase, expected_flag",
    [
        ("我覺得不想活了", "self_harm"),
        ("最近一直想消失", "self_harm"),
        ("我想催吐怎麼辦", "eating_disorder"),
        ("我已經斷食 5 天了", "extreme_fasting"),
        ("我斷食5天", "extreme_fasting"),
        ("我可以停我的甲狀腺藥嗎", "medical_advice_request"),
        ("我要一天只吃 500 卡", "extreme_diet"),
    ],
)
def test_trigger_words_blocked(phrase: str, expected_flag: str) -> None:
    result = scan_input(phrase)
    assert result.blocked, f"phrase {phrase!r} must be blocked"
    assert expected_flag in result.flags
    assert result.forced_reply is not None
    assert "1925" in result.forced_reply  # help resource line included


def test_safe_input_passes() -> None:
    result = scan_input("今天吃了便當，想知道熱量大概多少")
    assert not result.blocked
    assert result.flags == []
    assert result.forced_reply is None


def test_kcal_floor_blocks_low_daily() -> None:
    r = validate_kcal(daily_kcal=900, min_daily=1200, min_meal=800)
    assert r.blocked
    assert "kcal_below_daily_floor" in r.flags


def test_kcal_floor_blocks_low_meal() -> None:
    r = validate_kcal(per_meal_kcal=400, min_daily=1200, min_meal=800)
    assert r.blocked
    assert "kcal_below_meal_floor" in r.flags


def test_kcal_floor_passes_normal() -> None:
    r = validate_kcal(
        daily_kcal=1600, per_meal_kcal=600, min_daily=1200, min_meal=500
    )
    assert not r.blocked
    assert r.flags == []


def test_kcal_floor_skips_when_unset() -> None:
    r = validate_kcal(min_daily=1200, min_meal=800)
    assert not r.blocked


def test_disclaimer_appended_once() -> None:
    text = "好的，建議妳午餐選沙拉。"
    once = append_disclaimer(text)
    twice = append_disclaimer(once)
    assert once == twice
    assert MEDICAL_DISCLAIMER.strip() in once


def test_help_resource_includes_disclaimer() -> None:
    assert MEDICAL_DISCLAIMER.strip() in HELP_RESOURCE_REPLY
