import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 14-fasting-v2 — SPEC-fasting-redesign-v2 contract smoke.
 *
 * - GET /fasting/current returns kind=fasting OR kind=eating_window OR null
 * - PATCH /fasting/start-time updates elapsed_minutes within 24h window
 */

test('current returns kind discriminator', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };

  // Fresh user — no session at all
  let r = await fetch(`${backend}/api/fasting/current`, { headers });
  expect(r.status).toBe(200);
  let body = await r.json();
  expect(body.snapshot).toBeNull();

  // Start a session
  r = await fetch(`${backend}/api/fasting/start`, {
    method: 'POST',
    headers: { ...headers, 'Content-Type': 'application/json' },
    body: JSON.stringify({ mode: '16:8' }),
  });
  expect(r.status).toBe(201);

  // Now should be kind=fasting
  r = await fetch(`${backend}/api/fasting/current`, { headers });
  body = await r.json();
  expect(body.snapshot.kind).toBe('fasting');
});

test('PATCH /fasting/start-time bumps elapsed when valid', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` };

  await fetch(`${backend}/api/fasting/start`, { method: 'POST', headers, body: JSON.stringify({ mode: '16:8' }) });

  const newStart = new Date(Date.now() - 3 * 3600 * 1000).toISOString();
  const r = await fetch(`${backend}/api/fasting/start-time`, { method: 'PATCH', headers, body: JSON.stringify({ started_at: newStart }) });
  expect(r.status).toBe(200);
  const body = await r.json();
  expect(body.snapshot.elapsed_minutes).toBeGreaterThanOrEqual(178);
  expect(body.snapshot.elapsed_minutes).toBeLessThanOrEqual(182);
});
