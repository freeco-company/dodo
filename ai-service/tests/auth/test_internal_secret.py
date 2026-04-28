"""Tests for the X-Internal-Secret auth path used by dodo Laravel backend."""

from __future__ import annotations

from collections.abc import Iterator

import pytest
from fastapi.testclient import TestClient

from app.config import reset_settings_cache


@pytest.fixture()
def with_internal_secret(monkeypatch: pytest.MonkeyPatch) -> Iterator[str]:
    """Configure a known internal secret for the duration of a test."""
    secret = "test-internal-secret-xyz"
    monkeypatch.setenv("INTERNAL_SHARED_SECRET", secret)
    reset_settings_cache()
    yield secret
    reset_settings_cache()


def test_chat_accepts_internal_secret_with_uuid(
    client: TestClient,
    with_internal_secret: str,
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "你好"},
        headers={
            "X-Internal-Secret": with_internal_secret,
            "X-Pandora-User-Uuid": "00000000-0000-0000-0000-000000000001",
        },
    )
    assert resp.status_code == 200
    assert resp.headers["content-type"].startswith("text/event-stream")


def test_chat_rejects_internal_secret_without_uuid(
    client: TestClient,
    with_internal_secret: str,
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "你好"},
        headers={"X-Internal-Secret": with_internal_secret},
    )
    assert resp.status_code == 400
    assert "uuid" in resp.json()["detail"].lower()


def test_chat_rejects_wrong_internal_secret(
    client: TestClient,
    with_internal_secret: str,
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "你好"},
        headers={
            "X-Internal-Secret": "WRONG",
            "X-Pandora-User-Uuid": "00000000-0000-0000-0000-000000000001",
        },
    )
    assert resp.status_code == 401


def test_chat_rejects_when_secret_unconfigured(
    client: TestClient,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """No env => internal path disabled even if the right header arrives."""
    monkeypatch.setenv("INTERNAL_SHARED_SECRET", "")
    reset_settings_cache()
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "你好"},
        headers={
            "X-Internal-Secret": "anything",
            "X-Pandora-User-Uuid": "00000000-0000-0000-0000-000000000001",
        },
    )
    assert resp.status_code == 401


def test_vision_accepts_internal_secret_with_uuid(
    client: TestClient,
    with_internal_secret: str,
) -> None:
    png = bytes.fromhex(
        "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4"
        "890000000d49444154789c63600100000005000175a3070b0000000049454e44ae426082"
    )
    resp = client.post(
        "/v1/vision/recognize",
        files={"image": ("food.png", png, "image/png")},
        data={"meal_type": "lunch"},
        headers={
            "X-Internal-Secret": with_internal_secret,
            "X-Pandora-User-Uuid": "00000000-0000-0000-0000-000000000001",
        },
    )
    assert resp.status_code == 200
