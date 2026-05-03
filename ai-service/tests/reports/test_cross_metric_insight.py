"""SPEC-cross-metric-insight-v1 PR #3 — narrative endpoint + sanitizer tests."""

from __future__ import annotations

import pytest
from fastapi.testclient import TestClient

from app.safety.insight_sanitizer import insight_passes, insight_text_violates


@pytest.fixture(autouse=True)
def _internal_secret(monkeypatch: pytest.MonkeyPatch) -> None:
    from app.config import reset_settings_cache

    monkeypatch.setenv("INTERNAL_SHARED_SECRET", "narrative-test-secret")
    reset_settings_cache()


def _post(client: TestClient, body: dict, *, secret: str | None = "narrative-test-secret") -> object:
    headers: dict[str, str] = {}
    if secret is not None:
        headers["X-Internal-Secret"] = secret
    return client.post("/v1/reports/narrative", json=body, headers=headers)


def _payload(**overrides) -> dict:
    base = {
        "kind": "cross_metric_insight",
        "tier": "paid",
        "pandora_user_uuid": "u-insight-test",
        "cross_metric_insight": {
            "insight_key": "weight_plateau_detected",
            "detection_payload": {"avg_kg_7d": 56.0, "avg_kg_prev_7d": 56.05, "kcal_sd_ratio": 0.06},
            "free_headline": "妳的體重 5 天平台了 🌱",
            "free_body": "不是停滯，是身體在適應。這週飲食很規律，要不要試試斷食日加散步、或變動一下 macro 比例？",
            "user_display_name": "朋友",
        },
    }
    base.update(overrides)
    return base


def test_narrative_cross_metric_stub_returns_free_template_lines(client: TestClient) -> None:
    resp = _post(client, _payload())
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
    assert body["headline"] == "妳的體重 5 天平台了 🌱"
    assert len(body["lines"]) >= 1


def test_narrative_cross_metric_free_tier_also_works(client: TestClient) -> None:
    resp = _post(client, _payload(tier="free"))
    assert resp.status_code == 200
    assert resp.json()["headline"] == "妳的體重 5 天平台了 🌱"


def test_insight_sanitizer_blocks_forbidden_terms() -> None:
    forbidden_examples = [
        "這個方法可以幫妳燃脂",
        "代餐輕鬆減重 5 公斤",
        "這款產品有療效",
        "排毒、抗氧化、提升免疫力",
        "代餐取代正餐讓妳暴瘦",
        "塑身、瘦身、變瘦",
    ]
    for text in forbidden_examples:
        assert insight_text_violates(text), f"expected violation in: {text}"
        assert not insight_passes(text), f"insight_passes should be False for: {text}"


def test_insight_sanitizer_allows_compliant_text() -> None:
    compliant_examples = [
        "妳的體重 5 天平台了 🌱 不是停滯，是身體在適應",
        "妳的節奏抓得超穩 ✨ 一週移動了 0.8kg",
        "妳這週睡眠少了一些 🌙 平均 5.3 小時，可能跟壓力有關",
        "斷食穩了，但活動掉了一點 💭",
        "30 天連勝 🌟 妳真的做到了",
    ]
    for text in compliant_examples:
        assert insight_passes(text), f"expected pass for: {text}"
        assert insight_text_violates(text) == [], f"unexpected violation in: {text}"


def test_insight_sanitizer_returns_unique_terms_in_order() -> None:
    text = "燃脂、瘦身，再次燃脂，最後排毒"
    hits = insight_text_violates(text)
    assert hits == ["燃脂", "瘦身", "排毒"]


def test_narrative_cross_metric_requires_internal_secret(client: TestClient) -> None:
    resp = _post(client, _payload(), secret=None)
    assert resp.status_code == 401
