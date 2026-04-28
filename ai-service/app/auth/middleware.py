"""FastAPI auth dependencies."""

from __future__ import annotations

from fastapi import Header, HTTPException, status

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
