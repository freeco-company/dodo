"""SPEC-photo-ai-correction-v2 PR #3 — /v1/vision/refine endpoint tests."""

from __future__ import annotations

from fastapi.testclient import TestClient


def _refine_payload(**overrides) -> dict:
    base = {
        "user_uuid": "u-refine-test",
        "image_url": "https://x.test/photo.jpg",
        "original_dishes": [
            {
                "food_name": "白飯",
                "food_key": "rice_white",
                "portion_multiplier": 1.0,
                "kcal": 320,
                "carb_g": 70.0,
                "protein_g": 6.0,
                "fat_g": 0.5,
                "confidence": 0.92,
            },
            {
                "food_name": "雞腿",
                "food_key": "chicken_thigh",
                "portion_multiplier": 1.0,
                "kcal": 280,
                "carb_g": 0.0,
                "protein_g": 35.0,
                "fat_g": 15.0,
                "confidence": 0.92,
            },
        ],
        "user_hint": {
            "dish_index": 0,
            "new_food_key": "rice_brown",
        },
    }
    base.update(overrides)
    return base


def test_refine_swap_food_key_renames_target_dish_only(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = client.post("/v1/vision/refine", json=_refine_payload(), headers=auth_header)
    assert resp.status_code == 200
    body = resp.json()
    assert body["stub_mode"] is True
    assert len(body["dishes"]) == 2
    # target dish (index 0) renamed to 糙米
    assert body["dishes"][0]["food_key"] == "rice_brown"
    assert body["dishes"][0]["food_name"] == "糙米"
    # other dish untouched
    assert body["dishes"][1]["food_key"] == "chicken_thigh"


def test_refine_portion_change_scales_macros_linearly(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    payload = _refine_payload(
        user_hint={"dish_index": 0, "new_portion": 1.5},
    )
    resp = client.post("/v1/vision/refine", json=payload, headers=auth_header)
    assert resp.status_code == 200
    dish = resp.json()["dishes"][0]
    assert dish["portion_multiplier"] == 1.5
    assert dish["kcal"] == 480  # 320 × 1.5
    assert dish["carb_g"] == 105.0  # 70 × 1.5


def test_refine_swap_and_portion_combined(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    payload = _refine_payload(
        user_hint={
            "dish_index": 0,
            "new_food_key": "rice_brown",
            "new_portion": 0.75,
        },
    )
    resp = client.post("/v1/vision/refine", json=payload, headers=auth_header)
    assert resp.status_code == 200
    dish = resp.json()["dishes"][0]
    assert dish["food_key"] == "rice_brown"
    assert dish["portion_multiplier"] == 0.75
    assert dish["kcal"] == 240  # 320 × 0.75


def test_refine_dish_index_out_of_range_returns_400(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    payload = _refine_payload(
        user_hint={"dish_index": 99, "new_food_key": "rice_brown"},
    )
    resp = client.post("/v1/vision/refine", json=payload, headers=auth_header)
    assert resp.status_code == 400


def test_refine_response_includes_user_hint_echo(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    resp = client.post("/v1/vision/refine", json=_refine_payload(), headers=auth_header)
    body = resp.json()
    assert body["user_hint"]["dish_index"] == 0
    assert body["user_hint"]["new_food_key"] == "rice_brown"


def test_refine_passthrough_dish_keeps_original_macros(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    payload = _refine_payload(
        user_hint={"dish_index": 0, "new_food_key": "rice_brown"},
    )
    resp = client.post("/v1/vision/refine", json=payload, headers=auth_header)
    other = resp.json()["dishes"][1]
    assert other["kcal"] == 280
    assert other["protein_g"] == 35.0


def test_refine_validates_portion_bounds(
    client: TestClient, auth_header: dict[str, str]
) -> None:
    payload = _refine_payload(
        user_hint={"dish_index": 0, "new_portion": 5.0},  # over 3.0 limit
    )
    resp = client.post("/v1/vision/refine", json=payload, headers=auth_header)
    assert resp.status_code == 422


def test_refine_requires_auth(client: TestClient) -> None:
    resp = client.post("/v1/vision/refine", json=_refine_payload())
    assert resp.status_code in (401, 403)
