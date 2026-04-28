"""Tests for POST /v1/vision/recognize-text in stub mode + safety red-lines.

5 cases per task spec：
  1. stub returns empty foods + low confidence + manual_input_required
  2. requires JWT (401 without auth header)
  3. trigger word in description -> blocked, help-resource reply
  4. confidence floor flag fires (< 0.85 in stub)
  5. medical disclaimer appended to feedback
"""

from __future__ import annotations

from typing import Any

from fastapi.testclient import TestClient

from tests.conftest import RecordingCallback


def _post(
    client: TestClient,
    auth_header: dict[str, str],
    *,
    description: str = "我中午吃了一碗白飯加滷雞腿",
    hint: str | None = None,
    user_uuid: str = "user-uuid-1",
) -> Any:
    body: dict[str, Any] = {"user_uuid": user_uuid, "description": description}
    if hint is not None:
        body["hint"] = hint
    return client.post(
        "/v1/vision/recognize-text",
        json=body,
        headers=auth_header,
    )


def test_recognize_text_stub_returns_empty_foods_manual(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    resp = _post(client, auth_header)
    assert resp.status_code == 200
    body = resp.json()

    assert body["stub"] is True
    assert body["model"] == "stub"
    assert body["foods"] == []
    assert body["total_calories"] == 0
    assert body["confidence"] == 0.5
    assert body["manual_input_required"] is True

    # Callback persisted (best-effort).
    assert len(recording_callback.vision_calls) == 1
    persisted = recording_callback.vision_calls[0]
    assert persisted["meal_type"] == "text-described"
    assert persisted["manual_input_required"] is True


def test_recognize_text_requires_jwt(client: TestClient) -> None:
    resp = client.post(
        "/v1/vision/recognize-text",
        json={"user_uuid": "u", "description": "吃了便當"},
    )
    assert resp.status_code == 401


def test_recognize_text_trigger_word_returns_help_reply_no_llm_cost(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    # "想消失" is in the self_harm trigger pattern set.
    resp = _post(client, auth_header, description="我今天又催吐了，覺得想消失")
    assert resp.status_code == 200
    body = resp.json()

    # Forced help reply, no LLM model used, cost = 0.
    assert body["model"] == "safety-guard"
    assert body["cost_usd"] == 0.0
    assert body["foods"] == []
    assert body["manual_input_required"] is True
    # Multiple flags possible; both eating_disorder + self_harm should match.
    assert "self_harm" in body["safety_flags"] or "eating_disorder" in body["safety_flags"]
    assert "1925" in body["ai_feedback"]  # help line in HELP_RESOURCE_REPLY
    # No callback to Laravel for blocked content (we never reached the post path).
    assert len(recording_callback.vision_calls) == 0


def test_recognize_text_confidence_floor_flag_fires(
    client: TestClient,
    auth_header: dict[str, str],
) -> None:
    # Stub returns 0.5 confidence which is below the 0.85 threshold.
    resp = _post(client, auth_header, description="吃了一些東西")
    body = resp.json()

    assert body["manual_input_required"] is True
    assert "low_confidence_manual_input_required" in body["safety_flags"]


def test_recognize_text_appends_medical_disclaimer(
    client: TestClient,
    auth_header: dict[str, str],
) -> None:
    resp = _post(client, auth_header, description="早餐吃了三明治")
    body = resp.json()

    # Disclaimer suffix from safety/scanner.MEDICAL_DISCLAIMER.
    assert "本服務" in body["ai_feedback"]
    assert "醫師或營養師" in body["ai_feedback"]
