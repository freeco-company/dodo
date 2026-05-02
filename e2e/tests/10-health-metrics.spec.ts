import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 10-health-metrics — SPEC-03 Phase 1/2 contract smoke.
 */

test('free user can sync free metrics; sleep type rejected', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };

  const sync = await fetch(`${backend}/api/health/sync`, {
    method: 'POST',
    headers,
    body: JSON.stringify({
      metrics: [
        { type: 'steps', value: 7500, unit: 'count', recorded_at: '2026-05-03T22:00:00+08:00' },
        { type: 'weight', value: 53.4, unit: 'kg', recorded_at: '2026-05-03T07:00:00+08:00' },
        { type: 'sleep_minutes', value: 432, unit: 'min', recorded_at: '2026-05-03T07:00:00+08:00' },
      ],
    }),
  });
  expect(sync.status).toBe(200);
  const body = await sync.json();
  expect(body.accepted).toBe(2);
  expect(body.rejected).toBe(1);
  expect(body.reasons.paid_type_for_free_user).toBe(1);

  const today = await fetch(`${backend}/api/health/today`, { headers });
  expect(today.status).toBe(200);
  const t = await today.json();
  expect(t.weight_kg).toBe(53.4);
  expect(t.sleep_locked).toBe(true);
});

test('history endpoint validates type and caps free history to 7 days', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };

  const bad = await fetch(`${backend}/api/health/history?type=blood_glucose`, { headers });
  expect(bad.status).toBe(422);

  const ok = await fetch(`${backend}/api/health/history?type=steps&days=30`, { headers });
  expect(ok.status).toBe(200);
  const body = await ok.json();
  expect(body.history_capped_days).toBe(7);
});
