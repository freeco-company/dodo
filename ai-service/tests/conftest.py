"""Shared pytest fixtures.

Generates an in-process RSA keypair so JWT verification path is exercised end-to-end
without any external Pandora Core dependency.
"""

from __future__ import annotations

import time
from collections.abc import Iterator
from typing import Any

import pytest
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from fastapi.testclient import TestClient
from jose import jwt as jose_jwt

from app.auth.jwt_verifier import get_jwt_verifier, reset_jwt_verifier_cache
from app.config import get_settings, reset_settings_cache
from app.cost.tracker import CostTracker
from app.deps import (
    get_callback_client,
    get_chat_client,
    get_cost_tracker,
    get_vision_client,
)

# ---- RSA keypair (module-scope: generation is slow) ----


@pytest.fixture(scope="session")
def rsa_keypair() -> tuple[str, str]:
    private_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    private_pem = private_key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ).decode("ascii")
    public_pem = (
        private_key.public_key()
        .public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        )
        .decode("ascii")
    )
    return private_pem, public_pem


# ---- Env / settings ----


@pytest.fixture(autouse=True)
def _set_env_and_prime_key(
    monkeypatch: pytest.MonkeyPatch,
    rsa_keypair: tuple[str, str],
) -> None:
    """Default test env — STUB MODE (no Anthropic key) + tight budgets.

    Also primes the JWT verifier with the test RSA public key so no HTTP
    fetch is attempted during requests.
    """
    monkeypatch.setenv("ANTHROPIC_API_KEY", "")
    monkeypatch.setenv("PANDORA_CORE_ISSUER", "https://id.test.local")
    monkeypatch.setenv("PANDORA_CORE_ALLOWED_PRODUCTS", "doudou")
    monkeypatch.setenv("LARAVEL_INTERNAL_BASE_URL", "http://laravel.test.local")
    monkeypatch.setenv("LARAVEL_INTERNAL_SHARED_SECRET", "test-secret")
    monkeypatch.setenv("DEFAULT_DAILY_TOKEN_BUDGET", "1000000")
    monkeypatch.setenv("VISION_CONFIDENCE_THRESHOLD", "0.85")
    reset_settings_cache()
    reset_jwt_verifier_cache()
    _private_pem, public_pem = rsa_keypair
    get_jwt_verifier()._set_public_key_for_testing(public_pem)


# ---- JWT factory ----


@pytest.fixture()
def make_jwt(rsa_keypair: tuple[str, str]) -> Any:
    private_pem, _public_pem = rsa_keypair

    def _make(
        sub: str = "user-uuid-1",
        product_code: str = "doudou",
        scopes: list[str] | None = None,
        expires_in: int = 3600,
        issuer: str | None = None,
    ) -> str:
        now = int(time.time())
        claims: dict[str, Any] = {
            "iss": issuer or get_settings().pandora_core_issuer,
            "sub": sub,
            "product_code": product_code,
            "scopes": scopes or ["chat:write", "vision:write"],
            "iat": now,
            "exp": now + expires_in,
        }
        return jose_jwt.encode(claims, private_pem, algorithm="RS256")

    return _make


@pytest.fixture()
def auth_header(make_jwt: Any) -> dict[str, str]:
    return {"Authorization": f"Bearer {make_jwt()}"}


# ---- Callback stub (records calls, never hits HTTP) ----


class RecordingCallback:
    def __init__(self) -> None:
        self.chat_calls: list[dict[str, Any]] = []
        self.vision_calls: list[dict[str, Any]] = []
        self.cost_calls: list[dict[str, Any]] = []

    async def post_chat_message(self, **kwargs: Any) -> dict[str, Any]:
        self.chat_calls.append(kwargs)
        return {"ok": True}

    async def post_food_recognition(self, **kwargs: Any) -> dict[str, Any]:
        self.vision_calls.append(kwargs)
        return {"ok": True}

    async def post_cost_event(self, **kwargs: Any) -> dict[str, Any]:
        self.cost_calls.append(kwargs)
        return {"ok": True}


@pytest.fixture()
def recording_callback() -> RecordingCallback:
    return RecordingCallback()


# ---- TestClient with overridden deps ----


@pytest.fixture()
def client(
    recording_callback: RecordingCallback,
) -> Iterator[TestClient]:
    from app.main import app

    fresh_tracker = CostTracker()
    app.dependency_overrides[get_callback_client] = lambda: recording_callback
    app.dependency_overrides[get_cost_tracker] = lambda: fresh_tracker
    # Reset the singleton chat/vision clients so they pick up fresh settings.
    get_chat_client.cache_clear()
    get_vision_client.cache_clear()

    with TestClient(app) as c:
        yield c

    app.dependency_overrides.clear()
