"""Application settings loaded from env vars."""

from __future__ import annotations

from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )

    app_env: str = "local"
    app_debug: bool = True
    log_level: str = "INFO"

    # Anthropic — empty => stub mode
    anthropic_api_key: str = ""
    anthropic_model_default: str = "claude-3-5-haiku-latest"
    anthropic_model_premium: str = "claude-3-5-sonnet-latest"
    anthropic_model_vision: str = "claude-3-5-sonnet-latest"

    # Pandora Core (RS256 JWT verification, ADR-007)
    pandora_core_base_url: str = "http://localhost:8001"
    pandora_core_issuer: str = "https://id.js-store.com.tw"
    pandora_core_public_key_ttl: int = 3600
    pandora_core_allowed_products: str = "doudou"

    # Laravel internal callback (for ai-service -> Laravel direction)
    laravel_internal_base_url: str = "http://localhost:8000"
    laravel_internal_shared_secret: str = "change-me-in-prod"

    # Inbound internal-service auth (for Laravel -> ai-service direction).
    # Used by `app.auth.internal` to gate the X-Internal-Secret header path
    # (Phase B fallback for endpoints that would normally require a real
    # Pandora Core JWT). Empty => internal path disabled.
    internal_shared_secret: str = ""

    # Cost guard
    default_daily_token_budget: int = Field(default=200_000)

    # Safety (ADR-002 §1.2 / ai-game CLAUDE.md red-lines)
    min_kcal_per_day: int = 1200
    min_kcal_per_meal: int = 800
    vision_confidence_threshold: float = 0.85

    @property
    def allowed_products(self) -> set[str]:
        return {
            p.strip()
            for p in self.pandora_core_allowed_products.split(",")
            if p.strip()
        }

    @property
    def stub_mode(self) -> bool:
        """Stub when no LLM key — keeps JWT/safety/callback live for contract tests."""
        return not self.anthropic_api_key.strip()


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()


def reset_settings_cache() -> None:
    """Test seam — reset the lru_cache when env changes mid-test."""
    get_settings.cache_clear()
