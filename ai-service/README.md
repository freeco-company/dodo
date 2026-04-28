# dodo/ai-service

Python AI microservice for Dodo (ADR-002 Phase B).

- FastAPI + Pydantic v2 + uv + ruff + mypy strict
- Anthropic SDK with prompt caching
- Pandora Core RS256 JWT verification (product whitelist: `doudou`)
- HMAC-signed internal callback to Laravel
- Four hard safety red-lines (trigger words / kcal floor / medical disclaimer / vision confidence)

## Quick start

```bash
# Install (using uv, or `pip install -e ".[dev]"`)
uv sync --dev

# Copy env (leave ANTHROPIC_API_KEY blank to run in stub mode)
cp .env.example .env

# Run
uv run uvicorn app.main:app --reload --port 8002

# Quality gates
uv run ruff check .
uv run mypy app --strict
uv run pytest -v
```

## Endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| `POST` | `/v1/chat/stream` | Bearer JWT | SSE streaming chat |
| `POST` | `/v1/vision/recognize` | Bearer JWT | Multipart food image recognition |
| `GET`  | `/healthz` | – | Liveness |
| `GET`  | `/readyz` | – | Readiness (checks JWKS warm) |

## Stub mode

When `ANTHROPIC_API_KEY` is empty:

- Chat streaming yields a deterministic safe canned reply with the medical disclaimer suffix.
- Vision recognize returns a fixed stub item with `confidence=0.5` so the manual-input fallback path is exercised.
- JWT verification, safety scan, callback signing all run normally.

This lets backend / app contract tests run with no LLM key.

## Safety red-lines (ADR-002 §1.2 + ai-game/CLAUDE.md)

1. **Trigger word detection** — eating-disorder / self-harm phrases force a help-resource reply, no diet output.
2. **Kcal floor** — refuse `< 1200 kcal/day` or `< 800 kcal/meal` plans.
3. **Medical disclaimer suffix** appended to every assistant reply.
4. **Vision confidence < 0.85** marks the result as `manual_input_required`.

## Architecture

See [`../../ai-game/docs/adr/ADR-002-dodo-laravel-python.md`](../../ai-game/docs/adr/ADR-002-dodo-laravel-python.md).

Writes never touch the business DB — Python POSTs results back to Laravel via
`POST /api/internal/ai-callback/*` with an `X-Internal-Signature` HMAC header.
