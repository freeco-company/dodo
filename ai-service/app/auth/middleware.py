"""FastAPI auth dependencies."""

from __future__ import annotations

from fastapi import Header, HTTPException, status

from app.auth.internal import (
    InternalSecretError,
    claims_for_internal,
    verify_internal_secret,
)
from app.auth.jwt_verifier import (
    JwtVerificationError,
    VerifiedClaims,
    get_jwt_verifier,
)


async def require_jwt(
    authorization: str | None = Header(default=None),
) -> VerifiedClaims:
    """Parse `Authorization: Bearer <jwt>` header and verify against Pandora Core."""
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="missing bearer token",
        )
    token = authorization.split(" ", 1)[1].strip()
    verifier = get_jwt_verifier()
    try:
        return await verifier.verify(token)
    except JwtVerificationError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=str(exc),
        ) from exc


async def require_jwt_or_internal(
    authorization: str | None = Header(default=None),
    x_internal_secret: str | None = Header(default=None),
    x_pandora_user_uuid: str | None = Header(default=None),
) -> VerifiedClaims:
    """Accept either a Pandora Core JWT or an internal shared-secret header.

    The internal path is used by dodo Laravel backend (Phase B / ADR-002 §3)
    until token exchange (ADR-007 Phase 5) is wired. Internal callers must
    additionally carry ``X-Pandora-User-Uuid`` so we can attribute cost /
    callbacks to the right user.

    JWT takes precedence if both are present (real client tokens are stronger).
    """
    if authorization and authorization.lower().startswith("bearer "):
        return await require_jwt(authorization)

    if x_internal_secret:
        try:
            verify_internal_secret(x_internal_secret)
        except InternalSecretError as exc:
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail=str(exc),
            ) from exc
        if not x_pandora_user_uuid:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="X-Pandora-User-Uuid header required for internal calls",
            )
        return claims_for_internal(x_pandora_user_uuid)

    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="missing bearer token or internal secret",
    )
