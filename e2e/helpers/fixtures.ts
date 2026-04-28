import { Page } from '@playwright/test';
import { registerUser, RegisteredUser } from './api';

const BACKEND = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

/**
 * Pin the SPA's API base URL to our local Laravel — must be called
 * BEFORE the first `page.goto`. config.js reads `window.DODO_API_BASE`
 * if set, otherwise auto-detects (and on localhost defaults to
 * http://localhost:8765/api which is wrong for our test setup).
 */
export async function pinApiBase(page: Page): Promise<void> {
  const apiBase = `${BACKEND}/api`;
  await page.addInitScript((base: string) => {
    (window as any).DODO_API_BASE = base;
    (window as any).DOUDOU_API_BASE = base;
  }, apiBase);
}

/**
 * Open / and inject a sanctum token into localStorage so the SPA
 * boots straight into the authenticated state — bypassing the
 * onboarding flow that 01-onboarding covers.
 *
 * The frontend persists tokens under `dodo_token` (legacy key name
 * `doudou_token` is still read as fallback).
 */
export async function loginViaToken(page: Page, user: RegisteredUser): Promise<void> {
  await pinApiBase(page);
  // Land on / first so localStorage is bound to the frontend origin.
  await page.goto('/');
  await page.evaluate(({ token, userId }) => {
    try {
      // Frontend reads these keys (see app.js:7-8 + 422-423).
      localStorage.setItem('doudou_token', token);
      if (userId !== undefined && userId !== null) {
        localStorage.setItem('doudou_user', String(userId));
      }
      localStorage.setItem('doudou_animal', 'cat');
    } catch (_e) { /* localStorage unavailable — skip */ }
  }, { token: user.token, userId: user.userId });
  // Reload so app.js boot picks up the token on this fresh nav.
  await page.reload();
}

export async function registerAndLogin(page: Page): Promise<RegisteredUser> {
  const u = await registerUser();
  await loginViaToken(page, u);
  return u;
}

/**
 * Drive the SPA's onboarding flow end-to-end via UI clicks. Slower
 * than registerAndLogin but gives us the same boot state a real
 * user lands on (level=1, ceremony done, journey day=1).
 *
 * Returns when #main is visible.
 */
export async function uiRegister(page: Page, opts: { name?: string } = {}): Promise<void> {
  await pinApiBase(page);
  await page.goto('/');

  // Welcome must be visible — wait for the SPA to finish its boot.
  const welcome = page.locator('#screen-welcome');
  await welcome.waitFor({ state: 'visible', timeout: 10_000 });

  // Pick rabbit avatar (any non-default works; the existing default
  // `cat` is also fine but exercising a click verifies the picker).
  await page.locator('button[data-animal="rabbit"]').click();

  const stamp = Date.now() + Math.floor(Math.random() * 1e4);
  await page.locator('input[name="name"]').fill(opts.name ?? `e2e-${stamp}`);
  await page.locator('input[name="height_cm"]').fill('165');
  await page.locator('input[name="current_weight_kg"]').fill('65');
  await page.locator('input[name="target_weight_kg"]').fill('60');

  await page.locator('#reg-form button[type="submit"]').click();

  // Wait for ceremony continue button → click → main visible.
  const ceremonyContinue = page.locator('#ceremony-continue');
  await ceremonyContinue.waitFor({ state: 'visible', timeout: 15_000 });
  await ceremonyContinue.click();

  await page.locator('#main').waitFor({ state: 'visible', timeout: 10_000 });
}
