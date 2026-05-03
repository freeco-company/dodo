import { chromium } from 'playwright';
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 414, height: 896 } });
await ctx.route('**/*', (route) => route.continue({ headers: { ...route.request().headers(), 'cache-control': 'no-cache' } }));
const page = await ctx.newPage();
await page.goto('https://meal-api.js-store.com.tw/', { waitUntil: 'load' });
await page.waitForTimeout(500);
const state = await page.evaluate(() => {
  const out = {};
  ['tab-home','tab-fasting','fasting-active','fasting-eating-window','fasting-inactive','home-fasting-widget'].forEach((id) => {
    const el = document.getElementById(id);
    out[id] = el ? { hidden: el.classList.contains('hidden'), display: getComputedStyle(el).display, width: el.getBoundingClientRect().width } : 'NOT_FOUND';
  });
  return out;
});
console.log(JSON.stringify(state, null, 2));
await browser.close();
