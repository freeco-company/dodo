import { chromium } from 'playwright';

const url = 'https://meal-api.js-store.com.tw/';
const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 414, height: 896 }, bypassCSP: true });
await ctx.route('**/*', (route) => route.continue({ headers: { ...route.request().headers(), 'cache-control': 'no-cache' } }));
const page = await ctx.newPage();

const errors = [];
const requests = [];

page.on('console', (msg) => {
  if (msg.type() === 'error' || msg.type() === 'warning') {
    console.log(`[${msg.type()}] ${msg.text()}`);
  }
});
page.on('pageerror', (err) => {
  console.log(`[PAGE-ERROR] ${err.message}`);
  errors.push(err.message);
});
page.on('requestfailed', (req) => {
  console.log(`[REQ-FAIL] ${req.method()} ${req.url()} — ${req.failure()?.errorText}`);
});
page.on('response', (resp) => {
  const status = resp.status();
  if (status >= 400) {
    console.log(`[HTTP ${status}] ${resp.request().method()} ${resp.url()}`);
  }
});

console.log('=== load page ===');
await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 }).catch((e) => console.log('goto:', e.message));

console.log('\n=== wait 3s ===');
await page.waitForTimeout(3000);

const state = await page.evaluate(() => {
  const sections = ['screen-welcome', 'screen-ceremony', 'screen-disclaimer', 'main', 'reward-modal', 'island-fullscreen'];
  const out = {};
  for (const id of sections) {
    const el = document.getElementById(id);
    out[id] = el ? { hidden: el.classList.contains('hidden'), display: getComputedStyle(el).display, classes: el.className } : 'NOT_FOUND';
  }
  out.body_classes = document.body.className;
  out.localStorage = JSON.stringify({ user: localStorage.getItem('doudou_user'), token: localStorage.getItem('doudou_token') ? 'PRESENT' : null });
  out.app_version = document.querySelector('script[src*="app.js"]')?.src;
  return out;
});

console.log('\n=== DOM state after 3s ===');
console.log(JSON.stringify(state, null, 2));

console.log('\n=== screenshot ===');
await page.screenshot({ path: '/tmp/meal-check.png', fullPage: false });
console.log('saved to /tmp/meal-check.png');

await browser.close();
console.log(`\n=== summary: ${errors.length} page errors ===`);
