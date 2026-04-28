import { test } from '@playwright/test';

/**
 * 05-franchise-cta — TODO skeleton.
 *
 * ADR-003 §2.3: 愛用者→加盟轉換漏斗 — banner on Me tab + paywall hooks.
 * Backend contract is covered by FranchiseCtaSilenceTest +
 * AlignmentEndpointsTest (`/franchise/cta-view` + `/franchise/cta-click`).
 * The visibility logic depends on the lifecycle "loyalist" stage which
 * needs deterministic seed for e2e to be meaningful.
 *
 * Picked up after the lifecycle staging admin command lands.
 */
test.skip('TODO: loyalist stage user sees franchise banner on Me tab', () => { /* no-op */ });
test.skip('TODO: clicking banner fires /franchise/cta-click and opens consult flow', () => { /* no-op */ });
test.skip('TODO: silence toggle hides banner + persists across reload', () => { /* no-op */ });
