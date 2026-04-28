import { test, expect } from '@playwright/test';
import { pinApiBase } from '../helpers/fixtures';

/**
 * 01-onboarding — first-launch happy path.
 *
 * Flow:
 *   1. Land on / (welcome screen visible)
 *   2. Pick a non-default avatar (rabbit instead of seeded cat)
 *   3. Fill name + height + weight + target
 *   4. Submit registration form
 *   5. Wait for ceremony → main screen visible
 *
 * The ceremony screen has an animation, so we poll for #main losing
 * the `hidden` class with a generous timeout (10s).
 */

test('first-time user can register and reach main screen', async ({ page }) => {
  await pinApiBase(page);
  await page.goto('/');

  // Welcome screen visible
  await expect(page.locator('#screen-welcome')).toBeVisible();

  // Pick avatar = rabbit (cat is default-active in the mockup)
  await page.locator('button[data-animal="rabbit"]').click();

  // Fill form (the seeded values in HTML are already valid; we
  // overwrite name to make this run unique against a fresh DB).
  const stamp = Date.now();
  await page.locator('input[name="name"]').fill(`e2e-${stamp}`);
  await page.locator('input[name="height_cm"]').fill('168');
  await page.locator('input[name="current_weight_kg"]').fill('63.5');
  await page.locator('input[name="target_weight_kg"]').fill('58');

  // Submit — the form has a single "開始旅程 ✨" button.
  await page.locator('#reg-form button[type="submit"]').click();

  // Ceremony screen appears (it un-hides #screen-ceremony with the
  // "Pandora's box" reveal animation). Wait for the continue button
  // to become clickable — it's hidden until the box-open animation
  // completes (~3-5s).
  const ceremonyContinue = page.locator('#ceremony-continue');
  await expect(ceremonyContinue).toBeVisible({ timeout: 15_000 });
  await ceremonyContinue.click();

  // Now main mounts.
  await expect(page.locator('#main')).toBeVisible({ timeout: 10_000 });

  // Sanity: bottom nav is rendered (means SPA boot finished).
  await expect(page.locator('nav.tabs')).toBeVisible();
});
