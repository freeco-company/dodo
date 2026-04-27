# Frontend Migration Notes — Node → Laravel

> Status: 2026-04-28. Frontend bundle was copied from `ai-game/` and now points at the new Laravel backend (`dodo/backend/`).

## Files changed

| File | Change |
|------|--------|
| `public/config.js` | Renamed primary global to `window.DODO_API_BASE`. Falls back to legacy `DOUDOU_API_BASE` for compatibility. Default for `localhost` changed from `/api` to `http://localhost:8765/api` (matches `php artisan serve --port=8765`). Capacitor still uses `PROD_API` constant. |
| `public/app.js` | Line 3: `const API = window.DODO_API_BASE \|\| window.DOUDOU_API_BASE \|\| '/api';` |

No business logic was touched. `index.html` already loads `config.js` before `app.js` (lines 1260 / 1264) — no change needed there.

## API endpoints — frontend → new backend mapping

All confirmed routed in `backend/routes/api.php` (verified via `php artisan route:list`):

### Bootstrap / meta
| Frontend call | Backend route | Status |
|---|---|---|
| `GET /api/bootstrap` | `api/bootstrap` | OK |
| `GET /api/meta/limits` | `api/meta/limits` | OK |
| `GET /api/meta/outfits` | `api/meta/outfits` | OK |
| `GET /api/lore/spirits` | `api/lore/spirits` | OK |
| `GET /api/me` | `api/me` | OK |

### Auth (Sanctum bearer)
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/auth/register` | `api/auth/register` | OK |
| `POST /api/auth/login` | `api/auth/login` | OK |
| `POST /api/auth/logout` | `api/auth/logout` | OK |

### Meals / food
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/meals/scan` | `api/meals/scan` | OK |
| `POST /api/meals/text` | `api/meals/text` | OK |
| `GET/POST /api/meals` | `api/meals` | OK |
| `DELETE /api/meals/:id` | `api/meals/{meal}` | OK |
| `GET /api/foods/search` | not found | **TODO** — wire to FoodService search endpoint |

### Cards (Batch B real draw now active)
| Frontend call | Backend route | Status |
|---|---|---|
| `GET /api/cards/stamina` | `api/cards/stamina` | OK |
| `POST /api/cards/draw` | `api/cards/draw` | OK (now pulls from seeded `app_config.question_decks`) |
| `POST /api/cards/answer` | `api/cards/answer` | OK |
| `GET /api/cards/collection` | `api/cards/collection` | OK |
| `POST /api/cards/event-draw` | not found | **TODO** Phase B+ — event-driven NPC offers |
| `POST /api/cards/event-skip` | not found | **TODO** |
| `GET /api/cards/event-offer/...` | not found | **TODO** |
| `POST /api/cards/scene-draw` | not found | **TODO** — island_scenes integration |

### Daily checkin
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/checkin/water` / `set` | OK | OK |
| `POST /api/checkin/exercise` / `set` | OK | OK |
| `POST /api/checkin/weight` | OK | OK |
| `GET /api/checkin/goals` | OK | OK |

### Quests / journey
| Frontend call | Backend route | Status |
|---|---|---|
| `GET /api/quests/today` | OK | OK |
| `GET /api/journey` / `advance` | OK | OK |

### Interaction / chat
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/interact/pet` | OK | OK |
| `POST /api/interact/gift` / `status` | OK | OK |
| `POST /api/chat/message` | OK | OK |
| `GET /api/chat/starters` | OK | OK |

### Tier / paywall / referrals / push / rating
All present (`api/tier/redeem`, `api/subscribe/mock`, `api/paywall/event`, `api/referrals/me`+`redeem`, `api/push/register`+`unregister`, `api/rating-prompt/event`).

### Telemetry
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/analytics/track` | OK | OK |
| `POST /api/client-errors` | OK | OK |

### Account
| Frontend call | Backend route | Status |
|---|---|---|
| `POST /api/account/delete-request` | OK | OK |
| `POST /api/account/restore` | OK | OK |

## Old endpoints that are NOT yet ported (left as-is in app.js, will 404 until ported)

- `cards/event-draw`, `cards/event-skip`, `cards/event-offer/...`, `cards/scene-draw` — NPC-driven and scenario-driven card flows. Schema (`card_event_offers` table) and JSON content (`island_scenes` in `app_config`) are seeded; the routes/controllers are not yet wired. Tracked as Phase B+ work.
- `foods/search` — FoodService exists (`app/Services` includes food work via Meal flows) but no GET search route exposed; add when frontend "manual food picker" surface is reactivated.

## OAuth (LINE / Apple Sign-In) — TODO, do not delete

The legacy ai-game backend handled `/auth/me` redirects for LINE Login + Apple Sign-In. **No traces remain in `app.js`** (search for `oauth`, `line`, `apple`, `sign-in` returned only unrelated UI strings — the OAuth dance lived purely in old server-side cookies). Therefore there is nothing to mark in the frontend code right now.

When **Pandora Core Identity Service** ships (see `pandora/docs/adr/ADR-001-identity-service.md`), expect to re-introduce a small auth bootstrap module here that:

1. Detects an existing Pandora SSO cookie / token,
2. Exchanges it for a Sanctum bearer against `dodo/backend`,
3. Stores it in `localStorage` under `doudou_token` (current key already used by `app.js`).

Until then, the frontend continues to use email/password against `/api/auth/register` + `/api/auth/login`.

## Next steps

```bash
cd /Users/chris/freeco/pandora/dodo/frontend
npm install
npx cap sync ios
# then open ios/App/App.xcworkspace in Xcode and build to a real device.
```

Before `cap sync`, make sure `public/config.js` `PROD_API` is updated to the deployed backend URL (currently the placeholder `https://REPLACE-ME.fly.dev/api`).

For local web testing:

```bash
# terminal 1 — backend
cd /Users/chris/freeco/pandora/dodo/backend
php artisan serve --port=8765

# terminal 2 — frontend (any static server pointing at public/)
cd /Users/chris/freeco/pandora/dodo/frontend/public
python3 -m http.server 5173
# open http://localhost:5173/
```

`config.js` will auto-detect `localhost` and point at `http://localhost:8765/api`.
