"""Auth middleware (FastAPI dep) integration tests via /readyz-style probe."""

from __future__ import annotations

from typing import Any

from fastapi.testclient import TestClient


def test_chat_requires_bearer(client: TestClient) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "hi"},
    )
    assert resp.status_code == 401
    assert "missing bearer" in resp.json()["detail"].lower()


def test_chat_rejects_garbage_token(client: TestClient) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "hi"},
        headers={"Authorization": "Bearer not.a.jwt"},
    )
    assert resp.status_code == 401


def test_chat_accepts_valid_jwt(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "今天午餐吃了便當，蠻不錯"},
        headers=auth_header,
    )
    assert resp.status_code == 200
    assert resp.headers["content-type"].startswith("text/event-stream")


def test_chat_rejects_wrong_product(
    client: TestClient, make_jwt: Any
) -> None:
    bad_token = make_jwt(product_code="not_doudou")
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "hi"},
        headers={"Authorization": f"Bearer {bad_token}"},
    )
    assert resp.status_code == 401
