"""JWT verifier tests."""

from __future__ import annotations

import time
from typing import Any

import pytest
from jose import jwt as jose_jwt

from app.auth.jwt_verifier import JwtVerificationError, get_jwt_verifier
from app.config import get_settings


async def test_verify_happy_path(make_jwt: Any) -> None:
    token = make_jwt(sub="user-1")
    verifier = get_jwt_verifier()
    claims = await verifier.verify(token)
    assert claims.sub == "user-1"
    assert claims.product_code == "doudou"
    assert "chat:write" in claims.scopes


async def test_verify_rejects_unknown_product(make_jwt: Any) -> None:
    token = make_jwt(product_code="not_in_whitelist")
    verifier = get_jwt_verifier()
    with pytest.raises(JwtVerificationError, match="not in whitelist"):
        await verifier.verify(token)


async def test_verify_rejects_expired(make_jwt: Any) -> None:
    token = make_jwt(expires_in=-10)
    verifier = get_jwt_verifier()
    with pytest.raises(JwtVerificationError):
        await verifier.verify(token)


async def test_verify_rejects_wrong_issuer(make_jwt: Any) -> None:
    token = make_jwt(issuer="https://attacker.example.com")
    verifier = get_jwt_verifier()
    with pytest.raises(JwtVerificationError):
        await verifier.verify(token)


async def test_verify_required_scopes(make_jwt: Any) -> None:
    token = make_jwt(scopes=["chat:write"])
    verifier = get_jwt_verifier()
    # Has chat:write
    await verifier.verify(token, required_scopes=["chat:write"])
    # Missing admin:all
    with pytest.raises(JwtVerificationError, match="missing scopes"):
        await verifier.verify(token, required_scopes=["admin:all"])


async def test_verify_rejects_missing_sub(rsa_keypair: tuple[str, str]) -> None:
    private_pem, public_pem = rsa_keypair
    verifier = get_jwt_verifier()
    verifier._set_public_key_for_testing(public_pem)
    now = int(time.time())
    token = jose_jwt.encode(
        {
            "iss": get_settings().pandora_core_issuer,
            "product_code": "doudou",
            "scopes": [],
            "iat": now,
            "exp": now + 60,
        },
        private_pem,
        algorithm="RS256",
    )
    with pytest.raises(JwtVerificationError, match="sub"):
        await verifier.verify(token)
