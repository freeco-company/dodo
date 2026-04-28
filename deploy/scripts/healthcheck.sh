#!/usr/bin/env bash
# =============================================================================
# Lightweight healthcheck — runs on the Linode box itself, designed for cron.
#
# Probes each service's /health endpoint over loopback (no external DNS / TLS
# round-trip needed for internal checks). On failure, posts an alert to a
# Discord webhook (set DISCORD_WEBHOOK_URL env or in /etc/dodo/healthcheck.env).
#
# Install on the Linode box:
#   sudo cp deploy/scripts/healthcheck.sh /usr/local/bin/dodo-healthcheck
#   sudo chmod +x /usr/local/bin/dodo-healthcheck
#   sudo mkdir -p /etc/dodo
#   sudo bash -c 'cat > /etc/dodo/healthcheck.env <<EOF
# DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/XXX/YYY
# EOF'
#   sudo chmod 600 /etc/dodo/healthcheck.env
#
# Crontab (every 5 min):
#   */5 * * * * /usr/local/bin/dodo-healthcheck >>/var/log/dodo/healthcheck.log 2>&1
# =============================================================================

set -u

ENV_FILE="${ENV_FILE:-/etc/dodo/healthcheck.env}"
[[ -f "$ENV_FILE" ]] && source "$ENV_FILE"

DISCORD_WEBHOOK_URL="${DISCORD_WEBHOOK_URL:-}"
TIMEOUT="${TIMEOUT:-5}"

# service_name | url | expected_substring (optional)
CHECKS=(
    "dodo-backend-public|https://dodo.js-store.com.tw/api/health|"
    "dodo-frontend-public|https://app.dodo.js-store.com.tw/|"
    "dodo-ai-service|http://127.0.0.1:8002/healthz|"
    "py-service|http://127.0.0.1:8003/healthz|"
    "platform-identity|https://id.js-store.com.tw/api/v1/auth/public-key|BEGIN PUBLIC KEY"
)

failures=()
ts=$(date -Iseconds)

for line in "${CHECKS[@]}"; do
    name="${line%%|*}"
    rest="${line#*|}"
    url="${rest%%|*}"
    expect="${rest#*|}"

    body=$(curl -fsS --max-time "$TIMEOUT" "$url" 2>&1) || {
        failures+=("$name: HTTP failure -> $url")
        continue
    }

    if [[ -n "$expect" ]] && ! grep -q "$expect" <<<"$body"; then
        failures+=("$name: response missing expected token '$expect'")
        continue
    fi

    echo "[$ts] OK  $name"
done

if [[ ${#failures[@]} -eq 0 ]]; then
    exit 0
fi

# --- Failures: log + (optional) Discord alert -------------------------------
echo "[$ts] FAIL: ${#failures[@]} service(s) unhealthy"
for f in "${failures[@]}"; do echo "  - $f"; done

if [[ -n "$DISCORD_WEBHOOK_URL" ]]; then
    msg="**[dodo-healthcheck] $ts** — ${#failures[@]} service(s) unhealthy on Linode 139.162.121.187"
    for f in "${failures[@]}"; do msg+="\n• $f"; done
    payload=$(printf '{"content": "%s"}' "$msg")
    curl -fsS -H 'Content-Type: application/json' \
        -X POST -d "$payload" "$DISCORD_WEBHOOK_URL" >/dev/null 2>&1 \
        || echo "[$ts] WARN: discord webhook post failed"
fi

exit 1
