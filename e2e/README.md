# Dodo E2E

Playwright-driven end-to-end tests + a curl-based smoke probe. Lives
**outside** `backend/` and `frontend/` because it exercises the
contract between them (and the Filament admin).

## What's here

```
e2e/
├── package.json          (Playwright + TS toolchain)
├── playwright.config.ts  (single chromium project, no auto webServer)
├── helpers/
│   ├── api.ts            (programmatic register / authed fetch)
│   └── fixtures.ts       (pinApiBase, loginViaToken, uiRegister)
├── tests/
│   ├── 01-onboarding.spec.ts      runnable
│   ├── 02-daily-flow.spec.ts      runnable
│   ├── 03-cards.spec.ts           skip (TODO)
│   ├── 04-island.spec.ts          skip (TODO)
│   ├── 05-franchise-cta.spec.ts   skip (TODO)
│   ├── 06-me-tab.spec.ts          skip (TODO)
│   └── 07-admin-funnel.spec.ts    runnable
└── scripts/
    └── smoke.sh          (curl 24 endpoints + assert 2xx)
```

## Local run (4-step recipe)

You need **two** servers running before invoking Playwright. The
config does **not** boot them for you.

### 1. Backend (Laravel) on `:8000`

```bash
cd backend
cp .env.example .env       # only first time
php artisan key:generate   # only first time

# Override .env to use sqlite + file sessions for e2e:
cat >> .env <<'EOF'
DB_CONNECTION=sqlite
DB_DATABASE=/abs/path/to/backend/database/dodo-e2e.sqlite
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=array
EOF

touch database/dodo-e2e.sqlite
php artisan migrate:fresh --force --seed
php artisan serve --host=127.0.0.1 --port=8000
```

### 2. Frontend (static) on `:5173`

```bash
cd frontend/public
python3 -m http.server 5173 --bind 127.0.0.1
```

### 3. Install Playwright (once)

```bash
cd e2e
npm install
npx playwright install chromium
```

### 4. Run

```bash
cd e2e
DODO_BASE_URL=http://127.0.0.1:8000 \
DODO_FRONTEND_URL=http://127.0.0.1:5173 \
npx playwright test
```

Expected:

```
✓  01-onboarding ............ register → ceremony → main visible
✓  02-daily-flow ............ tap water + log meal (api stub OK)
-  03 / 04 / 05 / 06 .......... 11 TODO skips
✓  07-admin-funnel .......... admin login → funnel + leads inbox
3 passed, 11 skipped (≈12 s)
```

## Smoke test (no browser)

Faster sanity probe — registers a fresh user, then `curl`s 24
endpoints. Useful in CI before the Playwright run, or for ad-hoc
backend-only checks.

```bash
DODO_BASE_URL=http://127.0.0.1:8000 ./scripts/smoke.sh
```

## Conventions

### Selectors

Prefer this order:

1. `#id` (most stable; the SPA's existing IDs are stable)
2. `[data-…]` (e.g. `data-care`, `data-animal`)
3. `getByRole(…)` or `getByText(…)` (Filament / generated UI)

Avoid raw class selectors — Tailwind + Filament both rewrite classes
between releases.

### When a selector breaks

Annotate the failure as TODO and **continue** rather than block the
spec. Pattern:

```ts
const link = page.getByRole('link', { name: /xyz/ });
if (!(await link.isVisible().catch(() => false))) {
  test.info().annotations.push({
    type: 'TODO',
    description: 'sidebar link missing — direct nav fallback used',
  });
  await page.goto(directUrl, { waitUntil: 'domcontentloaded' });
}
```

### API base override

The frontend's `config.js` auto-picks `http://localhost:8765/api` on
any localhost host. To redirect it to our test backend, every spec
that drives the SPA UI must call `pinApiBase(page)` **before** the
first `page.goto`:

```ts
import { pinApiBase } from '../helpers/fixtures';
await pinApiBase(page);
await page.goto('/');
```

### Test isolation

Each test registers a brand-new user (timestamped email). The sqlite
file persists across runs but the test data is namespaced by stamp
so collisions are extremely unlikely. If you want a clean slate:

```bash
cd backend && php artisan migrate:fresh --force --seed
```

## Known TODOs (specs 03-06)

Tracked as `test.skip(...)` with inline comments — search `TODO:` in
the `tests/` directory. The blockers in the current build:

- **Cards**: deterministic stamina + offer seeding needs a
  test-only artisan command before UI flow can be locked in.
- **Island**: same — entitlement seeds for scene unlocks.
- **Franchise CTA**: lifecycle stage (`loyalist`) seeding — banner
  visibility hinges on it.
- **Me tab**: design freeze on settings shape pending.

## Known limitations

- `02-daily-flow` asserts the **/checkin/water network response**
  rather than the on-screen counter. The DOM update relies on
  `/me/dashboard.today.water_ml` which currently returns null on a
  fresh user (backend aggregation gap, separate ticket).
- `02-daily-flow` accepts `503 AI_SERVICE_DOWN` for `/meals/text`
  because the py-service hand-off (ADR-002 §3) isn't wired in this
  build. When it lands, the spec auto-asserts the success path.
- CI workflow at `.github/workflows/e2e-ci.yml` is committed
  `workflow_dispatch`-only until a maintainer green-runs it once;
  enable the `pull_request` trigger then.
