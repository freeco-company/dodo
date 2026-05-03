import { test, expect } from '@playwright/test';

/**
 * 00-overlay-defaults — REGRESSION GUARD for the 2026-05-03 incident.
 *
 * Background: `#screen-ceremony` had `class="hidden ceremony-screen"` in HTML
 * but `.ceremony-screen { display: flex }` overrode Tailwind's `.hidden`,
 * leaving every fresh user staring at a closed Pandora's box from second 0
 * with no escape. PRs #131 / #132 added animation escape hatches that didn't
 * help because the user was never IN the ceremony — the overlay just never
 * hid. PR #133 was the actual fix: explicit `.ceremony-screen.hidden { display: none !important }`.
 *
 * This test asserts that EVERY full-screen overlay with the `hidden` class in
 * static HTML actually computes `display: none`. If a future overlay style is
 * added without the `.<class>.hidden` override, this test catches it before
 * a user ever loads the page.
 *
 * Runs with workflow_dispatch only until rabbit-button onboarding flow is
 * stable; gating-class part below uses no auth so it runs in pure isolation.
 */

const PROD = process.env.MEAL_SMOKE_URL ?? process.env.DODO_FRONTEND_URL ?? 'http://127.0.0.1:5173';

const overlaysThatShouldStartHidden = [
  '#screen-ceremony',     // SPEC-02 / onboarding ceremony — the original culprit
  '#screen-disclaimer',   // first-run disclaimer (when present)
  '#main',                // main app shell — only visible after auth
  '#reward-modal',        // daily gift / level-up / achievement reveal
  '#countdown-modal',     // gift countdown when already claimed
  '#island-fullscreen',   // island full-screen view
  '#paywall',             // SPEC-04 paid upgrade overlay
];

test.describe('full-screen overlays must respect .hidden class on first paint', () => {
  test.beforeEach(async ({ page }) => {
    // No auth — just open the URL fresh.
    await page.goto(PROD, { waitUntil: 'load' });
    // Give CSS a moment to apply but no JS time to mutate (~200ms is plenty).
    await page.waitForTimeout(200);
  });

  for (const sel of overlaysThatShouldStartHidden) {
    test(`${sel} starts with display:none on fresh page load`, async ({ page }) => {
      // Some overlays may not exist on the page yet (paywall is render-on-demand).
      const exists = await page.locator(sel).count();
      if (!exists) {
        test.skip(true, `${sel} not rendered on initial page load — skip`);
        return;
      }

      const state = await page.locator(sel).first().evaluate((el) => {
        const cs = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        return {
          hasHiddenClass: el.classList.contains('hidden'),
          computedDisplay: cs.display,
          opacity: parseFloat(cs.opacity),
          pointerEvents: cs.pointerEvents,
          // 「Effectively invisible」accepts either display:none OR
          // opacity:0+pointer-events:none (the .reward-modal pattern that
          // uses fade-in/out instead of show/hide).
          effectivelyInvisible:
            cs.display === 'none'
            || (parseFloat(cs.opacity) === 0 && cs.pointerEvents === 'none')
            || rect.width === 0 || rect.height === 0,
        };
      });

      // Only assert when the element ships with `hidden` class. If JS already
      // removed it (some overlays auto-show), that's a separate concern.
      if (state.hasHiddenClass) {
        expect(
          state.effectivelyInvisible,
          `${sel} has .hidden class but is visible to the user (display=${state.computedDisplay}, opacity=${state.opacity}, pointer-events=${state.pointerEvents}). ` +
            `Either add \`${sel}.hidden { display: none !important; }\` in style.css, ` +
            `or use the .reward-modal pattern (default opacity:0 + pointer-events:none, .shown class to reveal).`,
        ).toBe(true);
      }
    });
  }

  test('welcome screen IS visible on fresh page load (the page works)', async ({ page }) => {
    const welcome = page.locator('#screen-welcome');
    const state = await welcome.evaluate((el) => ({
      computedDisplay: getComputedStyle(el).display,
    }));
    expect(state.computedDisplay).not.toBe('none');
  });
});
