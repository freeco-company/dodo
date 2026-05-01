"""is_food=false short-circuit (Issue #90).

When the vision model marks a photo as non-food, the route must:
  - Return 200 with is_food=False, manual_input_required=True, safety_flags contains 'not_food'
  - NOT call the Laravel callback (no meal record created)
  - Still record cost (Anthropic already charged us)
"""

from __future__ import annotations

from fastapi.testclient import TestClient

from app.deps import get_vision_client
from app.main import app
from app.vision.anthropic_client import VisionResult
from tests.conftest import RecordingCallback

_PNG_1x1 = bytes.fromhex(
    "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4"
    "890000000d49444154789c63600100000005000175a3070b0000000049454e44ae426082"
)


class _FakeNotFoodClient:
    async def recognize(self, image_bytes: bytes, image_mime: str, meal_type: str) -> VisionResult:
        return VisionResult(
            items=[],
            overall_confidence=0.0,
            ai_feedback="看起來不是食物喔，幫我拍張清楚的食物照吧 ✨",
            model="claude-fake",
            input_tokens=120,
            output_tokens=20,
            is_food=False,
        )


def test_not_food_short_circuits_callback_and_flags_safety(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    app.dependency_overrides[get_vision_client] = lambda: _FakeNotFoodClient()
    try:
        resp = client.post(
            "/v1/vision/recognize",
            files={"image": ("not-food.png", _PNG_1x1, "image/png")},
            data={"meal_type": "lunch"},
            headers=auth_header,
        )
    finally:
        app.dependency_overrides.pop(get_vision_client, None)

    assert resp.status_code == 200
    body = resp.json()
    assert body["is_food"] is False
    assert body["manual_input_required"] is True
    assert "not_food" in body["safety_flags"]
    assert body["items"] == []
    assert "不是食物" in body["ai_feedback"]
    # disclaimer should NOT be appended on not_food (UI guidance, not advice)
    assert "本服務" not in body["ai_feedback"]

    # Critical: no Laravel persistence on not_food
    assert len(recording_callback.vision_calls) == 0
