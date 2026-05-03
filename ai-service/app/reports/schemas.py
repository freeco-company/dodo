"""Pydantic schemas for reports endpoints (SPEC-04 / 05 dynamic narratives)."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class WeeklyReportPayload(BaseModel):
    """Aggregated 7-day metrics from Laravel WeeklyReportService."""

    window_start: str
    window_end: str
    days_logged: int = 0
    meals_count: int = 0
    meals_kcal: int = 0
    top_foods: list[str] = Field(default_factory=list, max_length=5)
    fasting_sessions: int = 0
    fasting_completed: int = 0
    fasting_longest_minutes: int = 0
    steps_total: int = 0
    active_kcal_total: int = 0
    sleep_avg_minutes: int | None = None
    weight_change_kg: float | None = None
    avg_score: float | None = None


class FastingCompletedPayload(BaseModel):
    mode: str
    target_minutes: int
    elapsed_minutes: int
    streak_days: int = 0


class FastingStageTransitionPayload(BaseModel):
    """SPEC-fasting-redesign-v2 §2.3 — stage push payload (paid LLM voice)."""

    mode: str
    target_minutes: int
    elapsed_minutes: int
    phase: Literal[
        "settling", "glycogen_switch", "fat_burning", "autophagy", "deep_fast"
    ]
    streak_days: int = 0


class ProgressSnapshotPayload(BaseModel):
    """For progress-photo album narrative — never receives photo bytes (SPEC §4.3)."""

    weight_kg_now: float | None = None
    weight_kg_30d_ago: float | None = None
    weight_kg_90d_ago: float | None = None
    snapshot_count_90d: int = 0
    streak_days: int = 0


class PhotoMealPayload(BaseModel):
    """For ad-hoc replacement of the 25-char dodo_comment on photo recognition."""

    food_name: str
    calories: int
    protein_g: float = 0
    carbs_g: float = 0
    fat_g: float = 0


class MonthlyCollageLetterPayload(BaseModel):
    """SPEC-progress-ritual-v1 PR #3 — paid 朵朵 letter for monthly collage.

    Compliance: NEVER expose weight kg in payload (insulates LLM from numbers
    that would tempt 變瘦/減重 phrasing). Aggregator passes neutral counts only.
    """

    month: str = Field(pattern=r"^\d{4}/\d{2}$")
    food_days_logged: int = Field(ge=0, le=31)
    steps_total: int = Field(ge=0)
    fasting_days: int = Field(ge=0, le=31)
    snapshot_count: int = Field(ge=0, le=31)


class StreakMilestoneLetterPayload(BaseModel):
    """SPEC-progress-ritual-v1 PR #3 — fullscreen celebration letter."""

    streak_kind: Literal["meal", "fasting", "steps", "weight_log", "photo"]
    streak_count: int = Field(ge=1)


class ProgressSliderCaptionPayload(BaseModel):
    """SPEC-progress-ritual-v1 PR #3 — ≤30 char slider caption (compliance hard).

    NEVER expose kg here — caption only sees days_between (already neutral).
    """

    days_between: int = Field(ge=1, le=3650)


NarrativeKind = Literal[
    "weekly_report",
    "fasting_completed",
    "fasting_stage_transition",
    "progress_snapshot",
    "photo_meal",
    "monthly_collage_letter",
    "streak_milestone_letter",
    "progress_slider_caption",
]


class NarrativeRequest(BaseModel):
    kind: NarrativeKind
    tier: Literal["free", "paid", "vip"] = "free"
    pandora_user_uuid: str = Field(min_length=1, max_length=64)
    weekly_report: WeeklyReportPayload | None = None
    fasting_completed: FastingCompletedPayload | None = None
    fasting_stage_transition: FastingStageTransitionPayload | None = None
    progress_snapshot: ProgressSnapshotPayload | None = None
    photo_meal: PhotoMealPayload | None = None
    monthly_collage_letter: MonthlyCollageLetterPayload | None = None
    streak_milestone_letter: StreakMilestoneLetterPayload | None = None
    progress_slider_caption: ProgressSliderCaptionPayload | None = None


class NarrativeResponse(BaseModel):
    headline: str
    lines: list[str] = Field(default_factory=list)
    model: str
    cost_usd: float = 0.0
    stub_mode: bool = False
