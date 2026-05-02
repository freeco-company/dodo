import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 13-cards-completion — SPEC-06 Phase 1 contract smoke.
 */

test('completion endpoint returns shape and seasonal arrays', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

  const r = await fetch(`${backend}/api/cards/completion`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(r.status).toBe(200);
  const body = await r.json();
  expect(body.completion).toBeDefined();
  expect(typeof body.completion.total).toBe('number');
  expect(typeof body.completion.collected).toBe('number');
  expect(typeof body.completion.percent).toBe('number');
  expect(Array.isArray(body.completion.categories)).toBe(true);
  expect(Array.isArray(body.seasonal_active)).toBe(true);
  expect(Array.isArray(body.seasonal_upcoming)).toBe(true);
  // Fresh user — collected zero, percent zero
  expect(body.completion.collected).toBe(0);
});
