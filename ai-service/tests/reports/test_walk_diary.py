"""SPEC-pikmin-walk-v1 — walk_diary narrative kind tests."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient


@pytest.fixture(autouse=True)
def _internal_secret(monkeypatch: pytest.MonkeyPatch) -> None:
    from app.config import reset_settings_cache

    monkeypatch.setenv("INTERNAL_SHARED_SECRET", "walk-test-secret")
    reset_settings_cache()


def _post(client: TestClient, body: dict) -> object:
    return client.post(
        "/v1/reports/narrative",
        json=body,
        headers={"X-Internal-Secret": "walk-test-secret"},
    )


def test_walk_diary_stub_returns_dodo_voice(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "walk_diary",
        "tier": "free",
        "pandora_user_uuid": "u-walk",
        "walk_diary": {
            "date": "2026-05-04",
            "total_steps": 6500,
            "phase": "bloom",
            "colors_collected": ["red", "green", "blue"],
        },
    })
    assert resp.status_code == 200, resp.text
    payload = resp.json()
    assert payload["stub_mode"] is True
    # Headline should mention bloom/花
    assert "花" in payload["headline"] or "🌸" in payload["headline"]
    # No forbidden compliance words slipped through
    body = " ".join([payload["headline"]] + payload["lines"])
    for forbidden in ["燃脂", "排毒", "減重", "變瘦", "瘦身", "代謝"]:
        assert forbidden not in body, f"forbidden word leaked: {forbidden}"


def test_walk_diary_seed_phase_includes_step_count(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "walk_diary",
        "tier": "free",
        "pandora_user_uuid": "u-walk-seed",
        "walk_diary": {
            "date": "2026-05-04",
            "total_steps": 850,
            "phase": "seed",
            "colors_collected": [],
        },
    })
    assert resp.status_code == 200
    payload = resp.json()
    body = " ".join(payload["lines"])
    assert "850" in body  # actual step count surfaced
    # When no mini-dodos, prompt user a hint about logging meals
    assert any("mini-dodo" in line for line in payload["lines"])


def test_walk_diary_rejects_invalid_phase(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "walk_diary",
        "tier": "free",
        "pandora_user_uuid": "u-walk-bad",
        "walk_diary": {
            "date": "2026-05-04",
            "total_steps": 100,
            "phase": "explosion",
            "colors_collected": [],
        },
    })
    assert resp.status_code == 422


def test_walk_diary_fruit_phase_celebrates(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "walk_diary",
        "tier": "paid",
        "pandora_user_uuid": "u-walk-fruit",
        "walk_diary": {
            "date": "2026-05-04",
            "total_steps": 9200,
            "phase": "fruit",
            "colors_collected": ["red", "green", "blue", "yellow", "purple"],
        },
    })
    assert resp.status_code == 200
    payload = resp.json()
    text = " ".join([payload["headline"]] + payload["lines"])
    # Fruit phase headline should celebrate
    assert any(token in payload["headline"] for token in ["結果", "🎉", "達成"])
    # Mentions hydration cue (compliant copy)
    assert "補水" in text or "💧" in text
