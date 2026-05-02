"""Tests for POST /v1/reports/narrative — stub mode + internal secret + shape."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient


@pytest.fixture(autouse=True)
def _internal_secret(monkeypatch: pytest.MonkeyPatch) -> None:
    """Configure a known internal secret for these tests."""
    from app.config import reset_settings_cache

    monkeypatch.setenv("INTERNAL_SHARED_SECRET", "narrative-test-secret")
    reset_settings_cache()


def _post(client: TestClient, body: dict, *, secret: str | None = "narrative-test-secret") -> object:
    headers: dict[str, str] = {}
    if secret is not None:
        headers["X-Internal-Secret"] = secret
    return client.post("/v1/reports/narrative", json=body, headers=headers)


def test_narrative_requires_internal_secret(client: TestClient) -> None:
    resp = _post(
        client,
        {"kind": "weekly_report", "pandora_user_uuid": "u-1"},
        secret=None,
    )
    assert resp.status_code == 401


def test_narrative_rejects_wrong_secret(client: TestClient) -> None:
    resp = _post(
        client,
        {"kind": "weekly_report", "pandora_user_uuid": "u-1"},
        secret="wrong",
    )
    assert resp.status_code == 401


def test_weekly_report_stub_returns_dodo_voice(client: TestClient) -> None:
    resp = _post(
        client,
        {
            "kind": "weekly_report",
            "tier": "free",
            "pandora_user_uuid": "u-1",
            "weekly_report": {
                "window_start": "2026-04-26",
                "window_end": "2026-05-02",
                "days_logged": 5,
                "meals_count": 14,
                "meals_kcal": 9800,
                "fasting_sessions": 3,
                "fasting_completed": 2,
                "steps_total": 38120,
            },
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
    assert body["model"] == "stub"
    assert body["headline"]
    assert isinstance(body["lines"], list)
    assert len(body["lines"]) >= 1


def test_fasting_completed_stub_includes_hours(client: TestClient) -> None:
    resp = _post(
        client,
        {
            "kind": "fasting_completed",
            "tier": "paid",
            "pandora_user_uuid": "u-2",
            "fasting_completed": {
                "mode": "16:8",
                "target_minutes": 960,
                "elapsed_minutes": 970,
                "streak_days": 3,
            },
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
    assert "16" in body["headline"]


def test_photo_meal_stub_uses_food_name(client: TestClient) -> None:
    resp = _post(
        client,
        {
            "kind": "photo_meal",
            "tier": "free",
            "pandora_user_uuid": "u-3",
            "photo_meal": {
                "food_name": "雞腿便當",
                "calories": 720,
                "protein_g": 35,
                "carbs_g": 90,
                "fat_g": 20,
            },
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["headline"] == "雞腿便當"
    assert body["stub_mode"] is True


def test_progress_snapshot_stub_safe_fallback(client: TestClient) -> None:
    resp = _post(
        client,
        {
            "kind": "progress_snapshot",
            "tier": "vip",
            "pandora_user_uuid": "u-4",
            "progress_snapshot": {
                "weight_kg_now": 53.5,
                "weight_kg_30d_ago": 55.0,
                "snapshot_count_90d": 12,
                "streak_days": 4,
            },
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True


def test_invalid_kind_rejected_by_pydantic(client: TestClient) -> None:
    resp = _post(
        client,
        {"kind": "blood_test", "pandora_user_uuid": "u-1"},
    )
    assert resp.status_code == 422


def test_no_payload_for_weekly_still_returns_safe_stub(client: TestClient) -> None:
    """Caller forgot to send the payload object — safe deterministic fallback."""
    resp = _post(
        client,
        {"kind": "weekly_report", "pandora_user_uuid": "u-1"},
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
