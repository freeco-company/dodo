// Runtime config — resolves the API base URL.
//
// When served from the backend itself (dev: localhost, prod: same-origin web),
// use relative '/api'. When running inside a Capacitor iOS/Android shell the
// origin is capacitor://localhost so we MUST point at a deployed backend.
//
// To deploy: set window.DOUDOU_API_BASE below (before loading app.js) or
// replace the default at build time.

// Crisp customer-support widget — set CRISP_WEBSITE_ID in window or via build.
// Loads ONLY when an ID is provided; otherwise no extra script is fetched.
window.CRISP_WEBSITE_ID = window.CRISP_WEBSITE_ID || ''; // paste your Crisp ID here when ready
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
  const isLocalHost = location.hostname === 'localhost' || location.hostname === '127.0.0.1';

  // PRODUCTION API URL — update this when backend is deployed.
  // Example: https://doudou-api.fly.dev/api
  const PROD_API = 'https://REPLACE-ME.fly.dev/api';

  if (window.DOUDOU_API_BASE) {
    // Already set (e.g. by index.html for testing)
  } else if (isCapacitor) {
    window.DOUDOU_API_BASE = PROD_API;
  } else if (isLocalHost) {
    window.DOUDOU_API_BASE = '/api';
  } else {
    window.DOUDOU_API_BASE = '/api'; // same-origin web
  }
})();
