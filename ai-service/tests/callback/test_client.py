"""Laravel callback HTTP client tests (with respx mock)."""

from __future__ import annotations

import json

import httpx
import pytest
import respx

from app.callback.client import LaravelCallbackClient, LaravelCallbackError
from app.callback.signing import verify_signature
from app.config import get_settings


@pytest.fixture()
def callback_client() -> LaravelCallbackClient:
    return LaravelCallbackClient(get_settings())


@respx.mock
async def test_post_chat_message_signs_request(
    callback_client: LaravelCallbackClient,
) -> None:
    route = respx.post(
        "http://laravel.test.local/api/internal/ai-callback/chat-message"
    ).mock(return_value=httpx.Response(200, json={"ok": True}))

    result = await callback_client.post_chat_message(
        session_id="s1",
        role="assistant",
        content="hi",
        tokens_in=10,
        tokens_out=20,
        cost_usd=0.001,
        safety_flags=[],
        model="claude-3-5-haiku-latest",
    )
    assert result == {"ok": True}
    assert route.called

    # Verify the signature header matches the body.
    sent = route.calls[0].request
    sig = sent.headers["x-internal-signature"]
    payload = json.loads(sent.content)
    assert verify_signature(payload, "test-secret", sig)


@respx.mock
async def test_post_food_recognition_signs_request(
    callback_client: LaravelCallbackClient,
) -> None:
    route = respx.post(
        "http://laravel.test.local/api/internal/ai-callback/food-recognition"
    ).mock(return_value=httpx.Response(200, json={"ok": True}))

    await callback_client.post_food_recognition(
        user_uuid="u1",
        meal_type="lunch",
        items=[{"name": "雞胸沙拉", "estimated_kcal": 380, "confidence": 0.92}],
        confidence=0.92,
        manual_input_required=False,
        ai_feedback="不錯！",
        model="claude-3-5-sonnet-latest",
        cost_usd=0.012,
    )
    assert route.called


@respx.mock
async def test_callback_raises_on_5xx(
    callback_client: LaravelCallbackClient,
) -> None:
    respx.post(
        "http://laravel.test.local/api/internal/ai-callback/chat-message"
    ).mock(return_value=httpx.Response(500, text="boom"))

    with pytest.raises(LaravelCallbackError, match="500"):
        await callback_client.post_chat_message(
            session_id="s1",
            role="assistant",
            content="x",
            tokens_in=0,
            tokens_out=0,
            cost_usd=0.0,
            safety_flags=[],
            model="m",
        )


@respx.mock
async def test_callback_raises_on_transport_error(
    callback_client: LaravelCallbackClient,
) -> None:
    respx.post(
        "http://laravel.test.local/api/internal/ai-callback/chat-message"
    ).mock(side_effect=httpx.ConnectError("nope"))

    with pytest.raises(LaravelCallbackError):
        await callback_client.post_chat_message(
            session_id="s1",
            role="assistant",
            content="x",
            tokens_in=0,
            tokens_out=0,
            cost_usd=0.0,
            safety_flags=[],
            model="m",
        )
