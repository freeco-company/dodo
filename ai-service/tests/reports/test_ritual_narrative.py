"""SPEC-progress-ritual-v1 PR #3 — ritual narrative endpoint + sanitizer tests."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.safety.ritual_sanitizer import ritual_passes, ritual_text_violates


@pytest.fixture(autouse=True)
def _internal_secret(monkeypatch: pytest.MonkeyPatch) -> None:
    from app.config import reset_settings_cache

    monkeypatch.setenv("INTERNAL_SHARED_SECRET", "ritual-test-secret")
    reset_settings_cache()


def _post(client: TestClient, body: dict, *, secret: str | None = "ritual-test-secret") -> object:
    headers: dict[str, str] = {}
    if secret is not None:
        headers["X-Internal-Secret"] = secret
    return client.post("/v1/reports/narrative", json=body, headers=headers)


def test_monthly_collage_letter_stub_returns_dodo_voice(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "monthly_collage_letter",
        "tier": "paid",
        "pandora_user_uuid": "u-collage",
        "monthly_collage_letter": {
            "month": "2026/04",
            "food_days_logged": 23,
            "steps_total": 245000,
            "fasting_days": 18,
            "snapshot_count": 6,
        },
    })
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
    assert "2026/04" in body["headline"]
    assert any("堅持" in line or "累積" in line for line in body["lines"])


def test_streak_milestone_letter_stub_celebrates(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "streak_milestone_letter",
        "tier": "paid",
        "pandora_user_uuid": "u-streak",
        "streak_milestone_letter": {
            "streak_kind": "meal",
            "streak_count": 30,
        },
    })
    assert resp.status_code == 200
    body = resp.json()
    assert "30 天連勝" in body["headline"]
    assert any("做到了" in line for line in body["lines"])


def test_progress_slider_caption_stub_is_one_line(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "progress_slider_caption",
        "tier": "paid",
        "pandora_user_uuid": "u-slider",
        "progress_slider_caption": {"days_between": 114},
    })
    assert resp.status_code == 200
    body = resp.json()
    assert body["headline"] == "妳堅持了 114 天 ✨"
    assert body["lines"] == []


def test_ritual_sanitizer_blocks_body_shaming_and_weight_terms() -> None:
    forbidden_examples = [
        "妳變漂亮了，腰瘦了好多",
        "30 天燃脂成功",
        "BMI 從 25 降到 22",
        "代餐取代正餐",
        "體脂率下降",
        "排毒效果好",
        "腰圍變細",
    ]
    for text in forbidden_examples:
        assert ritual_text_violates(text), f"expected violation: {text}"
        assert not ritual_passes(text)


def test_ritual_sanitizer_allows_neutral_celebration() -> None:
    compliant = [
        "妳堅持了 30 天 ✨",
        "30 天連勝 🌟 妳真的做到了",
        "這個月妳累積 245,000 步",
        "身形有變化是自然的事",
        "下個月繼續走下去吧",
    ]
    for text in compliant:
        assert ritual_passes(text), f"expected pass: {text}"


def test_ritual_kinds_require_internal_secret(client: TestClient) -> None:
    resp = _post(client, {
        "kind": "streak_milestone_letter",
        "pandora_user_uuid": "u-x",
        "streak_milestone_letter": {"streak_kind": "meal", "streak_count": 30},
    }, secret=None)
    assert resp.status_code == 401
