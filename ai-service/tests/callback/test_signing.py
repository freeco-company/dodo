"""HMAC signing tests."""

from __future__ import annotations

from app.callback.signing import canonical_json, sign_payload, verify_signature


def test_canonical_json_sorted_keys() -> None:
    a = canonical_json({"b": 1, "a": 2})
    b = canonical_json({"a": 2, "b": 1})
    assert a == b


def test_sign_and_verify_roundtrip() -> None:
    payload = {"foo": "bar", "n": 42}
    sig = sign_payload(payload, "secret")
    assert sig.startswith("sha256=")
    assert verify_signature(payload, "secret", sig)


def test_verify_rejects_tampered_payload() -> None:
    sig = sign_payload({"a": 1}, "secret")
    assert not verify_signature({"a": 2}, "secret", sig)


def test_verify_rejects_wrong_secret() -> None:
    sig = sign_payload({"a": 1}, "secret")
    assert not verify_signature({"a": 1}, "different-secret", sig)


def test_unicode_canonical_stable() -> None:
    payload = {"msg": "你好朵朵"}
    sig1 = sign_payload(payload, "k")
    sig2 = sign_payload({"msg": "你好朵朵"}, "k")
    assert sig1 == sig2
