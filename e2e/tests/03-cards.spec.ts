import { test } from '@playwright/test';

/**
 * 03-cards — TODO skeleton.
 *
 * Coverage gap: card draw flow + scene cards + event-offer banner.
 * Backend contract is covered in CardTest / CardEventTest /
 * CardSceneTest (PHPUnit). The UI flow is gated on stamina &
 * subscription tier, which makes deterministic e2e setup costly.
 *
 * Picked up in a follow-up ticket once card stamina seeding is
 * exposed via an admin/test-only artisan command.
 */
test.skip('TODO: card draw happy path → answer → assert collection grew', () => { /* no-op */ });
test.skip('TODO: event offer banner appears after server-pushed offer', () => { /* no-op */ });
test.skip('TODO: scene card draw on island hotspot', () => { /* no-op */ });
