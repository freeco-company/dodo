import { test, expect } from '@playwright/test';
import { uiRegister } from '../helpers/fixtures';

/**
 * 08-photo-ai-quota — SPEC-photo-ai-calorie-polish §5.1 contract.
 *
 *   - Free user: 4th /api/meals/scan in same day → 402 + PHOTO_AI_QUOTA_EXCEEDED
 *   - Paywall payload includes tier_required=paid + fallback_endpoint=/api/meals/text
 *   - bootstrap entitlements include photo_ai_quota_* fields
 */

const _PNG_1x1 =
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test('photo AI quota: 4th scan returns 402 with paywall payload', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  expect(token).toBeTruthy();
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

  // Burn 3 quota slots — each may 503 (ai-service down in CI) but the quota
  // counter still bumps because pre-flight check happens before AI call.
  for (let i = 0; i < 3; i++) {
    const r = await fetch(`${backend}/api/meals/scan`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        photo_base64: _PNG_1x1,
        content_type: 'image/png',
        meal_type: 'lunch',
      }),
    });
    // 200 (ai-service stubbed in spec) OR 503 (ai-service unreachable in CI)
    // — both consume a quota slot.
    expect([200, 503]).toContain(r.status);
  }

  // 4th call → 402 PHOTO_AI_QUOTA_EXCEEDED (pre-flight rejects before AI is even called).
  const fourth = await fetch(`${backend}/api/meals/scan`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({
      photo_base64: _PNG_1x1,
      content_type: 'image/png',
      meal_type: 'lunch',
    }),
  });
  expect(fourth.status).toBe(402);
  const body = await fourth.json();
  expect(body.error_code).toBe('PHOTO_AI_QUOTA_EXCEEDED');
  expect(body.paywall.tier_required).toBe('paid');
  expect(body.paywall.fallback_endpoint).toBe('/api/meals/text');
});

test('bootstrap entitlements expose photo_ai_quota_* fields', async ({ page }) => {
  await uiRegister(page);
  const token = await page.evaluate(() => localStorage.getItem('doudou_token'));
  const backend = process.env.DODO_BASE_URL ?? 'http://127.0.0.1:8000';

  const r = await fetch(`${backend}/api/bootstrap`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  expect(r.status).toBe(200);
  const body = await r.json();

  expect(body.entitlements).toBeTruthy();
  expect(body.entitlements.photo_ai_quota_total).toBe(3);
  expect(body.entitlements.photo_ai_quota_used).toBeGreaterThanOrEqual(0);
  expect(body.entitlements.photo_ai_quota_remaining).toBeLessThanOrEqual(3);
  expect(body.entitlements.photo_ai_quota_reset_at).toBeTruthy();
});
