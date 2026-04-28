"""Per-request cost tracker.

Phase B keeps the tracker in-memory — production should swap to Redis or push
events to Laravel via the cost-event callback. The interface stays stable.

Anthropic pricing (USD per 1M tokens, 2025-Q4 list price snapshot):
- claude-3-5-haiku:  $0.80 input / $4 output / $0.08 cache-read / $1 cache-write
- claude-3-5-sonnet: $3 input / $15 output / $0.30 cache-read / $3.75 cache-write
- claude-3-opus:     $15 input / $75 output (rare path)

These constants intentionally live here, not in env — pricing changes are PRs,
not silent env edits.
"""

from __future__ import annotations

import logging
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Final

logger = logging.getLogger(__name__)


@dataclass
class _ModelPricing:
    input_per_mtok: float
    output_per_mtok: float
    cache_read_per_mtok: float
    cache_write_per_mtok: float


_PRICING: Final[dict[str, _ModelPricing]] = {
    "claude-3-5-haiku-latest": _ModelPricing(0.80, 4.00, 0.08, 1.00),
    "claude-3-5-haiku": _ModelPricing(0.80, 4.00, 0.08, 1.00),
    "claude-3-5-sonnet-latest": _ModelPricing(3.00, 15.00, 0.30, 3.75),
    "claude-3-5-sonnet": _ModelPricing(3.00, 15.00, 0.30, 3.75),
    "claude-3-opus-latest": _ModelPricing(15.00, 75.00, 1.50, 18.75),
}

_DEFAULT_PRICING: Final[_ModelPricing] = _PRICING["claude-3-5-haiku-latest"]


def anthropic_cost_usd(
    *,
    model: str,
    input_tokens: int,
    output_tokens: int,
    cache_read_tokens: int = 0,
    cache_write_tokens: int = 0,
) -> float:
    """Compute USD cost for one Anthropic call (cache-aware)."""
    pricing = _PRICING.get(model, _DEFAULT_PRICING)
    return round(
        (
            input_tokens * pricing.input_per_mtok
            + output_tokens * pricing.output_per_mtok
            + cache_read_tokens * pricing.cache_read_per_mtok
            + cache_write_tokens * pricing.cache_write_per_mtok
        )
        / 1_000_000.0,
        6,
    )


@dataclass
class UsageRecord:
    user_uuid: str
    model: str
    input_tokens: int
    output_tokens: int
    cache_read_tokens: int = 0
    cache_write_tokens: int = 0
    cost_usd: float = 0.0
    endpoint: str = ""
    flags: list[str] = field(default_factory=list)


class CostTracker:
    """In-memory cost tracker — Phase B skeleton."""

    def __init__(self) -> None:
        self._daily_tokens: dict[str, int] = defaultdict(int)
        self._records: list[UsageRecord] = []

    def record(self, usage: UsageRecord) -> None:
        usage.cost_usd = anthropic_cost_usd(
            model=usage.model,
            input_tokens=usage.input_tokens,
            output_tokens=usage.output_tokens,
            cache_read_tokens=usage.cache_read_tokens,
            cache_write_tokens=usage.cache_write_tokens,
        )
        self._records.append(usage)
        self._daily_tokens[usage.user_uuid] += (
            usage.input_tokens + usage.output_tokens
        )
        logger.info(
            "ai_cost user=%s model=%s in=%d out=%d cache_r=%d cache_w=%d cost_usd=%.6f endpoint=%s",
            usage.user_uuid,
            usage.model,
            usage.input_tokens,
            usage.output_tokens,
            usage.cache_read_tokens,
            usage.cache_write_tokens,
            usage.cost_usd,
            usage.endpoint,
        )

    def daily_tokens(self, user_uuid: str) -> int:
        return self._daily_tokens[user_uuid]

    def is_over_budget(self, user_uuid: str, budget: int) -> bool:
        return self.daily_tokens(user_uuid) >= budget

    @property
    def records(self) -> list[UsageRecord]:
        return list(self._records)

    def reset(self) -> None:
        self._daily_tokens.clear()
        self._records.clear()
