import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 11-weekly-report — SPEC-04 Phase 1/2 contract smoke.
 */

test('current returns empty-state narrative for fresh user', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };

  const r = await fetch(`${backend}/api/reports/weekly/current`, { headers });
  expect(r.status).toBe(200);
  const body = await r.json();
  expect(body.tier).toBe('free');
  expect(body.features.image_card).toBe(false);
  expect(body.features.history_capped_weeks).toBe(4);
  expect(body.narrative.headline).toContain('還沒');
});

test('history endpoint returns empty data for fresh user', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

  const r = await fetch(`${backend}/api/reports/weekly/history?weeks=12`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(r.status).toBe(200);
  const body = await r.json();
  expect(body.data).toBeInstanceOf(Array);
});

test('shared endpoint 404s on a non-existent report id', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

  const r = await fetch(`${backend}/api/reports/weekly/9999999/shared`, {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(r.status).toBe(404);
});
