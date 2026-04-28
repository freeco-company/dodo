"""HMAC-SHA256 signing for internal Python -> Laravel callbacks.

Wire format:
  X-Internal-Signature: sha256=<hex>
  Body: canonical JSON (sorted keys, no whitespace)

Laravel side verifies the same hex digest with `hash_equals`. Both sides MUST
share the secret only via env / vault, never commit.
"""

from __future__ import annotations

import hashlib
import hmac
import json
from typing import Any


def canonical_json(payload: dict[str, Any]) -> bytes:
    """Stable JSON for signing — sorted keys, no whitespace."""
    return json.dumps(
        payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False
    ).encode("utf-8")


def sign_payload(payload: dict[str, Any], secret: str) -> str:
    body = canonical_json(payload)
    digest = hmac.new(secret.encode("utf-8"), body, hashlib.sha256).hexdigest()
    return f"sha256={digest}"


def verify_signature(payload: dict[str, Any], secret: str, signature: str) -> bool:
    expected = sign_payload(payload, secret)
    # constant-time compare
    return hmac.compare_digest(expected, signature)
