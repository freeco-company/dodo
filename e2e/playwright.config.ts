import { defineConfig, devices } from '@playwright/test';

/**
 * Dodo Playwright config — see e2e/README.md for full setup.
 *
 * Required env vars at run time:
 *   DODO_BASE_URL    — backend Laravel `php artisan serve` (default 8000)
 *   DODO_FRONTEND_URL — http-server hosting frontend/public (default 5173)
 *
 * The frontend's config.js auto-picks `http://localhost:8765/api` on
 * any localhost host. To override that we inject window.DODO_API_BASE
 * via an init script in each spec (see helpers/fixtures.ts).
 */
const FRONTEND = process.env.DODO_FRONTEND_URL ?? 'http://127.0.0.1:5173';

export default defineConfig({
  testDir: './tests',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: FRONTEND,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
});
