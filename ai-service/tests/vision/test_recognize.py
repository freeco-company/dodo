"""Vision /v1/vision/recognize endpoint tests in stub mode."""

from __future__ import annotations

from fastapi.testclient import TestClient

from tests.conftest import RecordingCallback

# Tiny valid PNG (1x1 transparent) so multipart parses but we never decode it
# in stub mode. Bytes copied from a known-good fixture.
_PNG_1x1 = bytes.fromhex(
    "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4"
    "890000000d49444154789c63600100000005000175a3070b0000000049454e44ae426082"
)


def _post_image(
    client: TestClient,
    auth_header: dict[str, str],
    *,
    meal_type: str = "lunch",
    content_type: str = "image/png",
    body: bytes = _PNG_1x1,
) -> object:
    return client.post(
        "/v1/vision/recognize",
        files={"image": ("food.png", body, content_type)},
        data={"meal_type": meal_type},
        headers=auth_header,
    )


def test_recognize_stub_returns_low_confidence_manual(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    resp = _post_image(client, auth_header)
    assert resp.status_code == 200
    body = resp.json()

    assert body["stub_mode"] is True
    assert body["model"] == "stub"
    # Stub overall_confidence is 0.5 -> below 0.85 threshold -> manual required
    assert body["manual_input_required"] is True
    assert "low_confidence_manual_input_required" in body["safety_flags"]
    assert "本服務" in body["ai_feedback"]  # disclaimer suffix

    # Callback persisted.
    assert len(recording_callback.vision_calls) == 1
    persisted = recording_callback.vision_calls[0]
    assert persisted["meal_type"] == "lunch"
    assert persisted["manual_input_required"] is True


def test_recognize_rejects_unsupported_mime(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = _post_image(client, auth_header, content_type="image/svg+xml")
    assert resp.status_code == 415


def test_recognize_requires_auth(client: TestClient) -> None:
    resp = client.post(
        "/v1/vision/recognize",
        files={"image": ("food.png", _PNG_1x1, "image/png")},
        data={"meal_type": "lunch"},
    )
    assert resp.status_code == 401


def test_recognize_rejects_empty_image(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = _post_image(client, auth_header, body=b"")
    assert resp.status_code == 400
