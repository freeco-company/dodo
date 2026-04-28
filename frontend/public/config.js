// Runtime config — resolves the API base URL.
//
// Resolution order (first non-empty wins):
//   1. window.DODO_API_BASE   (preferred, project-renamed 2026-04-28)
//   2. window.DOUDOU_API_BASE (legacy, kept for backward-compat with old index.html overrides)
//   3. Auto-pick based on host:
//      - Capacitor / file:// shell  → PROD_API constant below (prod backend)
//      - localhost / 127.0.0.1      → http://localhost:8765/api (Laravel `php artisan serve`)
//      - other web origin           → /api (assumes backend served same-origin)
//
// All call sites read window.DODO_API_BASE — app.js falls back to DOUDOU_API_BASE
// for now, both names point at the same value.

// Crisp customer-support widget — set CRISP_WEBSITE_ID in window or via build.
// Loads ONLY when an ID is provided; otherwise no extra script is fetched.
window.CRISP_WEBSITE_ID = window.CRISP_WEBSITE_ID || '';
(function loadCrisp() {
  if (!window.CRISP_WEBSITE_ID) return;
  window.$crisp = [];
  const s = document.createElement('script');
  s.src = 'https://client.crisp.chat/l.js';
  s.async = true;
  document.head.appendChild(s);
})();

(function () {
  const isCapacitor =
    window.Capacitor !== undefined ||
    location.protocol === 'capacitor:' ||
    location.protocol === 'file:';
  const isLocalHost =
    location.hostname === 'localhost' || location.hostname === '127.0.0.1';

  // PRODUCTION API URL — update this when backend is deployed.
  // Example: https://dodo-api.fly.dev/api
  const PROD_API = 'https://REPLACE-ME.fly.dev/api';

  // Allow ?api=http://host:port/api in the URL to override at runtime.
  // Persisted in sessionStorage so SPA reloads keep the override.
  try {
    const qs = new URLSearchParams(location.search);
    const qsApi = qs.get('api') || qs.get('DODO_API_BASE');
    if (qsApi) sessionStorage.setItem('DODO_API_BASE', qsApi);
    const stored = sessionStorage.getItem('DODO_API_BASE');
    if (stored && !window.DODO_API_BASE) window.DODO_API_BASE = stored;
  } catch (_e) { /* sessionStorage may be unavailable in file:// shells */ }

  // Prefer the new DODO_ prefix, fall back to the legacy DOUDOU_ override.
  let base = window.DODO_API_BASE || window.DOUDOU_API_BASE || '';

  if (!base) {
    if (isCapacitor) {
      base = PROD_API;
    } else if (isLocalHost) {
      // Default Laravel `php artisan serve --port=8765` from dodo/backend.
      base = 'http://localhost:8765/api';
    } else {
      base = '/api'; // same-origin web
    }
  }

  // Mirror onto both globals so any caller (old DOUDOU_API_BASE refs in
  // app.js or new DODO_API_BASE refs) sees the same value.
  window.DODO_API_BASE = base;
  window.DOUDOU_API_BASE = base;
})();
