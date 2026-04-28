"""Cost tracker tests."""

from __future__ import annotations

from app.cost.tracker import CostTracker, UsageRecord, anthropic_cost_usd


def test_haiku_cost_basic() -> None:
    cost = anthropic_cost_usd(
        model="claude-3-5-haiku-latest",
        input_tokens=1_000_000,
        output_tokens=0,
    )
    assert cost == 0.80


def test_sonnet_cost_with_cache() -> None:
    cost = anthropic_cost_usd(
        model="claude-3-5-sonnet-latest",
        input_tokens=0,
        output_tokens=1_000_000,
        cache_read_tokens=1_000_000,
    )
    # 15 (output) + 0.30 (cache read) = 15.30
    assert cost == 15.30


def test_unknown_model_falls_back_to_haiku() -> None:
    a = anthropic_cost_usd(model="weird-model", input_tokens=1_000_000, output_tokens=0)
    b = anthropic_cost_usd(
        model="claude-3-5-haiku-latest", input_tokens=1_000_000, output_tokens=0
    )
    assert a == b


def test_tracker_records_and_aggregates_daily() -> None:
    t = CostTracker()
    t.record(
        UsageRecord(
            user_uuid="u1",
            model="claude-3-5-haiku-latest",
            input_tokens=100,
            output_tokens=50,
        )
    )
    t.record(
        UsageRecord(
            user_uuid="u1",
            model="claude-3-5-haiku-latest",
            input_tokens=200,
            output_tokens=100,
        )
    )
    assert t.daily_tokens("u1") == 100 + 50 + 200 + 100
    assert len(t.records) == 2
    assert t.records[0].cost_usd > 0


def test_is_over_budget() -> None:
    t = CostTracker()
    t.record(
        UsageRecord(
            user_uuid="u1",
            model="claude-3-5-haiku-latest",
            input_tokens=1000,
            output_tokens=0,
        )
    )
    assert not t.is_over_budget("u1", 5000)
    assert t.is_over_budget("u1", 500)
