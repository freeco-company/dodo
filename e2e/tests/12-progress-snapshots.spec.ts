import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 12-progress-snapshots — SPEC-05 Phase 1 contract smoke.
 */

test('free user is blocked with 402 PROGRESS_TIER_LOCKED', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };

  const r = await fetch(`${backend}/api/progress/snapshot`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ taken_at: '2026-05-03T07:00:00+08:00', weight_kg: 53.5 }),
  });
  expect(r.status).toBe(402);
  const body = await r.json();
  expect(body.error_code).toBe('PROGRESS_TIER_LOCKED');
  expect(body.paywall.tier_required).toBe('yearly');

  const tl = await fetch(`${backend}/api/progress/timeline`, { headers });
  expect(tl.status).toBe(402);
});
