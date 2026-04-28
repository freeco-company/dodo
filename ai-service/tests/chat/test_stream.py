"""End-to-end SSE streaming tests in stub mode."""

from __future__ import annotations

import json

from fastapi.testclient import TestClient

from tests.conftest import RecordingCallback


def _parse_sse(body: str) -> list[tuple[str, dict[str, object]]]:
    events: list[tuple[str, dict[str, object]]] = []
    event_name = "message"
    for chunk in body.split("\n\n"):
        if not chunk.strip():
            continue
        for line in chunk.split("\n"):
            if line.startswith("event:"):
                event_name = line[len("event:") :].strip()
            elif line.startswith("data:"):
                data_str = line[len("data:") :].strip()
                events.append((event_name, json.loads(data_str)))
    return events


def test_stream_happy_path_in_stub_mode(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={
            "session_id": "session-abc",
            "message": "今天午餐吃了便當，覺得有點罪惡感",
            "history": [],
        },
        headers=auth_header,
    )
    assert resp.status_code == 200
    events = _parse_sse(resp.text)
    event_names = [name for name, _ in events]

    assert "delta" in event_names
    assert "usage" in event_names
    assert event_names[-1] == "done"

    usage = next(data for name, data in events if name == "usage")
    assert usage["stub_mode"] is True
    assert usage["model"] == "stub"

    # Callback was invoked once.
    assert len(recording_callback.chat_calls) == 1
    persisted = recording_callback.chat_calls[0]
    assert persisted["session_id"] == "session-abc"
    assert persisted["role"] == "assistant"
    # Disclaimer must be present in the persisted reply.
    assert "本服務" in persisted["content"]


def test_stream_blocks_on_self_harm(
    client: TestClient,
    auth_header: dict[str, str],
    recording_callback: RecordingCallback,
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={
            "session_id": "s2",
            "message": "我覺得活著沒意義，不想活了",
        },
        headers=auth_header,
    )
    assert resp.status_code == 200
    events = _parse_sse(resp.text)
    safety_events = [data for name, data in events if name == "safety"]
    assert safety_events, "must emit a safety event"
    assert safety_events[0]["blocked"] is True
    assert "self_harm" in safety_events[0]["flags"]

    # Forced reply emitted as delta — must contain help resource line.
    delta_text = "".join(
        str(data["text"]) for name, data in events if name == "delta"
    )
    assert "1925" in delta_text

    # Persisted record carries safety flags + safety_override model.
    assert len(recording_callback.chat_calls) == 1
    persisted = recording_callback.chat_calls[0]
    assert persisted["model"] == "safety_override"
    assert "self_harm" in persisted["safety_flags"]


def test_stream_validates_request_schema(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    # Missing required field
    resp = client.post(
        "/v1/chat/stream",
        json={"message": "hi"},
        headers=auth_header,
    )
    assert resp.status_code == 422


def test_stream_rejects_oversized_message(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = client.post(
        "/v1/chat/stream",
        json={"session_id": "s1", "message": "x" * 10_000},
        headers=auth_header,
    )
    assert resp.status_code == 422
