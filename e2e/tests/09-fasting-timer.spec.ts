import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 09-fasting-timer — SPEC-02 Phase 3 contract smoke.
 *
 *   - Free user can start a 16:8 session
 *   - GET /current returns the same session (with phase=digesting)
 *   - Starting a 2nd session 422s with FASTING_ALREADY_ACTIVE
 *   - 18:6 (paid mode) returns 402 FASTING_MODE_LOCKED for free users
 *   - Ending the session returns completed=false (target not met in test)
 */

test('free user can start, query, and end a 16:8 fasting session', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };

  // Start
  const start = await fetch(`${backend}/api/fasting/start`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ mode: '16:8' }),
  });
  expect(start.status).toBe(201);
  const startBody = await start.json();
  expect(startBody.session.mode).toBe('16:8');
  expect(startBody.session.target_duration_minutes).toBe(960);
  expect(startBody.snapshot.phase).toBe('digesting');

  // Current returns same active session
  const cur = await fetch(`${backend}/api/fasting/current`, { headers });
  expect(cur.status).toBe(200);
  const curBody = await cur.json();
  expect(curBody.snapshot).toBeTruthy();
  expect(curBody.snapshot.mode).toBe('16:8');

  // Second start → 422 conflict
  const dup = await fetch(`${backend}/api/fasting/start`, {
    method: 'POST',
    headers,
    body: JSON.stringify({ mode: '14:10' }),
  });
  expect(dup.status).toBe(422);
  const dupBody = await dup.json();
  expect(dupBody.error_code).toBe('FASTING_ALREADY_ACTIVE');

  // End — 16:8 target not yet met → completed=false
  const end = await fetch(`${backend}/api/fasting/end`, { method: 'POST', headers, body: '{}' });
  expect(end.status).toBe(200);
  const endBody = await end.json();
  expect(endBody.session.completed).toBe(false);
});

test('free user blocked from 18:6 with FASTING_MODE_LOCKED 402', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const r = await fetch(`${backend}/api/fasting/start`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ mode: '18:6' }),
  });
  expect(r.status).toBe(402);
  const body = await r.json();
  expect(body.error_code).toBe('FASTING_MODE_LOCKED');
  expect(body.paywall.reason).toBe('fasting_advanced_mode');
});
