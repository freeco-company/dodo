#!/usr/bin/env bash
#
# smoke.sh — quick "is the backend alive and the API contract honoured?"
# probe. Registers a fresh user, then curls 20 endpoints and asserts
# every response is 2xx (or an explicitly-allowed 503 / 204 / 401 for
# stubbed / no-content / public surfaces).
#
# Run AFTER:
#   - backend Laravel running on $DODO_BASE_URL (default http://127.0.0.1:8000)
#   - DB migrated + seeded
#
# Usage:
#   ./scripts/smoke.sh
#   DODO_BASE_URL=https://staging.example.com ./scripts/smoke.sh
#
set -euo pipefail

BASE="${DODO_BASE_URL:-http://127.0.0.1:8000}"
PASS=0
FAIL=0
FAILED_LINES=()

color() { printf "\033[%sm%s\033[0m" "$1" "$2"; }
green() { color "32" "$1"; }
red()   { color "31" "$1"; }
ylw()   { color "33" "$1"; }

# hit METHOD PATH ALLOWED_CODES_REGEX [BODY_JSON]
hit() {
  local method="$1"
  local path="$2"
  local allowed="$3"
  local body="${4:-}"
  local args=(-sS -o /tmp/dodo-smoke-body -w "%{http_code}" -X "$method" -H "Accept: application/json")
  if [[ -n "$TOKEN" ]]; then
    args+=(-H "Authorization: Bearer $TOKEN")
  fi
  if [[ -n "$body" ]]; then
    args+=(-H "Content-Type: application/json" --data "$body")
  fi
  local code
  code=$(curl "${args[@]}" "${BASE}${path}" || echo "000")
  if [[ "$code" =~ ^($allowed)$ ]]; then
    echo "  $(green "PASS")  $method $path → $code"
    PASS=$((PASS+1))
  else
    echo "  $(red "FAIL")  $method $path → $code (expected $allowed)"
    FAIL=$((FAIL+1))
    FAILED_LINES+=("$method $path got $code")
  fi
}

echo "$(ylw "[smoke]") base=${BASE}"

# 1. Health (public)
TOKEN=""
hit GET /api/health "200"

# 2. Bootstrap (public-with-optional-auth)
hit GET /api/bootstrap "200"

# 3. Register a fresh user
echo "$(ylw "[smoke]") registering throwaway user..."
STAMP=$(date +%s%N)
REG_BODY=$(cat <<JSON
{
  "name": "smoke-${STAMP}",
  "email": "smoke+${STAMP}@dodo.local",
  "password": "password123",
  "height_cm": 165,
  "current_weight_kg": 65,
  "target_weight_kg": 60,
  "avatar_animal": "cat",
  "activity_level": "light",
  "gender": "female"
}
JSON
)
REG_RESP=$(curl -sS -X POST "${BASE}/api/auth/register" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  --data "$REG_BODY")
TOKEN=$(echo "$REG_RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('token') or d.get('data',{}).get('token',''))" 2>/dev/null || true)
if [[ -z "$TOKEN" ]]; then
  echo "  $(red "FAIL") /api/auth/register did not return a token"
  echo "  body: $REG_RESP" | head -c 500
  exit 1
fi
echo "  $(green "PASS")  POST /api/auth/register (token captured)"
PASS=$((PASS+1))

# 4-23. Authenticated endpoints — 20 calls covering each domain.
hit GET    /api/me                       "200"
hit GET    /api/me/dashboard             "200"
hit GET    /api/me/settings              "200"
hit GET    /api/bootstrap                "200"
hit GET    /api/entitlements             "200"
hit GET    /api/journey                  "200"
hit GET    /api/quests/today             "200"
hit GET    /api/calendar                 "200"
hit GET    /api/cards/stamina            "200"
hit GET    /api/cards/collection         "200"
hit GET    /api/cards/event-offer/next   "200|204"
hit GET    /api/pokedex                  "200"
hit GET    /api/achievements             "200"
hit GET    /api/outfits                  "200"
hit GET    /api/island/scenes            "200"
hit GET    /api/foods/search?q=apple     "200"
hit GET    /api/meta/limits              "200"
hit GET    /api/lore/spirits             "200"
hit GET    /api/referrals/me             "200"
hit POST   /api/checkin/water            "200|201" '{"ml":250}'

# 24. AI stub — should be 503 + AI_SERVICE_DOWN when py-service not wired
hit POST   /api/meals/text               "200|201|503" '{"description":"一杯水","meal_type":"breakfast"}'

echo ""
echo "$(ylw "[smoke]") summary:"
echo "  passed:  $(green "$PASS")"
echo "  failed:  $(red   "$FAIL")"

if (( FAIL > 0 )); then
  echo ""
  echo "$(red "[smoke] FAILED")"
  for line in "${FAILED_LINES[@]}"; do
    echo "  - $line"
  done
  exit 1
fi
echo "$(green "[smoke] OK")"
