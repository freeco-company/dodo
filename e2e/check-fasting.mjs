import { chromium } from 'playwright';

const url = process.env.MEAL_SMOKE_URL ?? 'https://meal-api.js-store.com.tw/';
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 414, height: 896 } });
const page = await ctx.newPage();

page.on('console', (m) => { if (m.type() === 'error') console.log('[err]', m.text()); });
page.on('pageerror', (e) => console.log('[page-error]', e.message));

await page.goto(url, { waitUntil: 'load' });
await page.waitForTimeout(500);

const all = await page.evaluate(() => {
  const out = {};
  document.querySelectorAll('[id^="tab-"]').forEach((el) => {
    out[el.id] = {
      hidden: el.classList.contains('hidden'),
      display: getComputedStyle(el).display,
    };
  });
  return out;
});
console.log('=== all tab-* sections on fresh load ===');
console.log(JSON.stringify(all, null, 2));

await browser.close();
