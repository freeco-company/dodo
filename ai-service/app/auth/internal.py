"""Internal service-to-service authentication via shared secret.

Mirrors py-service's pattern (``py-service/app/auth/internal.py``). Phase B
this is the only auth dodo backend can offer because it does not yet hold a
user-scoped Pandora Core JWT (ADR-007 Phase 5 token exchange not done). The
endpoint accepts EITHER a Bearer JWT (real product clients) OR an
``X-Internal-Secret`` header (dodo backend pass-through). Once dodo backend
gets real JWTs we drop the internal path.

Header name:
    X-Internal-Secret: <secret>

Body / form contract when using the internal path:
    must carry ``pandora_user_uuid`` so usage / cost / callbacks still attribute
    to the right user.
"""

from __future__ import annotations

import hmac

from fastapi import Header, HTTPException, status

from app.auth.jwt_verifier import VerifiedClaims
from app.config import get_settings


class InternalSecretError(Exception):
    """Raised when the shared secret header is missing or wrong."""


def verify_internal_secret(provided: str | None) -> None:
    """Constant-time compare against the configured secret.

    Empty / unconfigured secret is treated as DISABLED (always reject) so a
    misconfigured env never silently lets traffic in.
    """
    settings = get_settings()
    expected = settings.internal_shared_secret
    if not expected:
        raise InternalSecretError("internal secret not configured")
    if not provided or not hmac.compare_digest(provided, expected):
        raise InternalSecretError("invalid internal secret")


def require_internal_secret(
    x_internal_secret: str | None = Header(default=None),
) -> None:
    """FastAPI dep — pure shared-secret guard (no user scope)."""
    try:
        verify_internal_secret(x_internal_secret)
    except InternalSecretError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=str(exc),
        ) from exc


def claims_for_internal(pandora_user_uuid: str) -> VerifiedClaims:
    """Synthesize a VerifiedClaims for an internal-secret request.

    The caller (dodo backend) is trusted to have already authenticated the
    user via Sanctum and is passing through the user uuid. We tag scopes as
    chat:write/vision:write so downstream cost/safety logic sees the same
    shape as a real JWT.
    """
    return VerifiedClaims(
        sub=pandora_user_uuid,
        product_code="doudou",
        scopes=["chat:write", "vision:write"],
        raw={"auth": "internal_secret"},
    )
