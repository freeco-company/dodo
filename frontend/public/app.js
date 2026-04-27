// Doudou web app — cat/rabbit/bear/hamster/fox + camera + calorie ring + stickiness.

const API = window.DOUDOU_API_BASE || '/api';
const state = {
  userId: localStorage.getItem('doudou_user') || null,
  token: localStorage.getItem('doudou_token') || null,
  animal: localStorage.getItem('doudou_animal') || 'cat',
  selectedFood: null,
  capturedBase64: null,
  lastAnimalMood: 'happy',
  lastLevel: 1,
  camStream: null,
};

const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

// Mascot naming — user sees their chosen animal's nickname everywhere.
const MASCOT_NAMES = {
  cat: '貓貓', rabbit: '兔兔', bear: '熊熊', hamster: '倉鼠', fox: '狐狸',
  shiba: '柴柴', dinosaur: '恐龍', penguin: '企鵝', tuxedo: '賓士',
};
const MASCOT_EMOJIS = {
  cat: '🐱', rabbit: '🐰', bear: '🐻', hamster: '🐹', fox: '🦊',
  shiba: '🐕', dinosaur: '🦖', penguin: '🐧', tuxedo: '🐈‍⬛',
};
function mascotName() { return MASCOT_NAMES[state.animal] || '夥伴'; }
function mascotEmoji() { return MASCOT_EMOJIS[state.animal] || '🐾'; }
// Safety net: older DB rows may still contain the literal "芽芽" baked into
// coach feedback. Rewrite to the currently-selected mascot name at display time.
function stripLegacyMascot(s) { return String(s || '').replace(/芽芽/g, mascotName()); }

// =========================================================
// Custom modal system — replaces native alert() / confirm() / prompt()
// so we get consistent styling, no browser chrome, and work in Capacitor.
// =========================================================
const UI = {
  alert(message, { title = '', okText = '知道了' } = {}) {
    return new Promise((resolve) => {
      const root = _ensureModalRoot();
      root.innerHTML = `
        <div class="ui-modal-backdrop"></div>
        <div class="ui-modal-sheet ui-modal-alert">
          ${title ? `<div class="ui-modal-title">${_escape(title)}</div>` : ''}
          <div class="ui-modal-body">${_escape(message)}</div>
          <div class="ui-modal-actions">
            <button class="btn-primary ui-modal-ok">${_escape(okText)}</button>
          </div>
        </div>`;
      const close = () => { root.classList.remove('shown'); setTimeout(() => root.innerHTML = '', 260); resolve(); };
      root.querySelector('.ui-modal-ok').addEventListener('click', close);
      root.querySelector('.ui-modal-backdrop').addEventListener('click', close);
      requestAnimationFrame(() => root.classList.add('shown'));
      window.sfx?.play('ui_open');
    });
  },

  confirm(message, { title = '', okText = '確定', cancelText = '取消', danger = false } = {}) {
    return new Promise((resolve) => {
      const root = _ensureModalRoot();
      root.innerHTML = `
        <div class="ui-modal-backdrop"></div>
        <div class="ui-modal-sheet ui-modal-confirm">
          ${title ? `<div class="ui-modal-title">${_escape(title)}</div>` : ''}
          <div class="ui-modal-body">${_escape(message)}</div>
          <div class="ui-modal-actions">
            <button class="btn-secondary ui-modal-cancel">${_escape(cancelText)}</button>
            <button class="${danger ? 'btn-danger' : 'btn-primary'} ui-modal-ok">${_escape(okText)}</button>
          </div>
        </div>`;
      const finish = (val) => { root.classList.remove('shown'); setTimeout(() => root.innerHTML = '', 260); resolve(val); };
      root.querySelector('.ui-modal-ok').addEventListener('click', () => finish(true));
      root.querySelector('.ui-modal-cancel').addEventListener('click', () => finish(false));
      root.querySelector('.ui-modal-backdrop').addEventListener('click', () => finish(false));
      requestAnimationFrame(() => root.classList.add('shown'));
      window.sfx?.play('ui_open');
    });
  },

  prompt(message, { title = '', placeholder = '', defaultValue = '', okText = '確定', cancelText = '取消', inputType = 'text' } = {}) {
    return new Promise((resolve) => {
      const root = _ensureModalRoot();
      root.innerHTML = `
        <div class="ui-modal-backdrop"></div>
        <div class="ui-modal-sheet ui-modal-prompt">
          ${title ? `<div class="ui-modal-title">${_escape(title)}</div>` : ''}
          <div class="ui-modal-body">${_escape(message)}</div>
          <input class="input ui-modal-input" type="${inputType}" placeholder="${_escape(placeholder)}" value="${_escape(defaultValue)}" />
          <div class="ui-modal-actions">
            <button class="btn-secondary ui-modal-cancel">${_escape(cancelText)}</button>
            <button class="btn-primary ui-modal-ok">${_escape(okText)}</button>
          </div>
        </div>`;
      const input = root.querySelector('.ui-modal-input');
      const finish = (val) => { root.classList.remove('shown'); setTimeout(() => root.innerHTML = '', 260); resolve(val); };
      root.querySelector('.ui-modal-ok').addEventListener('click', () => finish(input.value));
      root.querySelector('.ui-modal-cancel').addEventListener('click', () => finish(null));
      root.querySelector('.ui-modal-backdrop').addEventListener('click', () => finish(null));
      input.addEventListener('keydown', (e) => { if (e.key === 'Enter') finish(input.value); if (e.key === 'Escape') finish(null); });
      requestAnimationFrame(() => { root.classList.add('shown'); input.focus(); input.select(); });
      window.sfx?.play('ui_open');
    });
  },
};

// =========================================================
// JRPG-style dialog — dual portraits (NPC on left, mascot on right)
// with typewriter text + RPG-style bottom box.
// =========================================================
async function playDialog(lines, opts = {}) {
  if (!Array.isArray(lines) || lines.length === 0) return;
  const overlay = $('#dialog-overlay');
  const text = $('#dialog-text');
  const nextArrow = $('#dialog-next');
  const speakerLabel = $('#dialog-rpg-speaker');
  const charNpc = $('#dialog-char-npc');
  const charMascot = $('#dialog-char-mascot');
  const npc = opts.npc || { emoji: '🧑', name: 'NPC' };
  const backdrop = opts.backdrop || '';

  // Paint portraits — prefer a Fluent animal avatar (avoids the scary human emoji)
  const npcEl = $('#dialog-char-npc-emoji');
  if (opts.npcAnimal && window.animalImg) {
    npcEl.innerHTML = window.animalImg(opts.npcAnimal, 'npc-animal');
  } else {
    const npcIconName = (cfg('npc_icon_map', NPC_EMOJI_ICON_FALLBACK) || {})[npc.emoji];
    if (npcIconName && window.icon) {
      npcEl.innerHTML = window.icon(npcIconName, { size: 140 });
    } else {
      npcEl.textContent = npc.emoji;
    }
  }
  $('#dialog-char-npc-name').textContent = npc.name;
  $('#dialog-char-mascot-svg').innerHTML = renderCharacter({
    animal: state.animal, level: state.lastLevel || 1, mood: 'happy', outfit: 'none',
  });
  $('#dialog-char-mascot-name').textContent = mascotName();
  // Scene backdrop
  const bdEl = $('#dialog-scene-backdrop');
  bdEl.className = 'dialog-backdrop' + (backdrop ? ` bg-${backdrop}` : '');

  overlay.classList.remove('hidden');
  window.sfx?.play('ui_open');

  for (const line of lines) {
    // Hide both first, then show only the active one (robust against stuck state)
    charNpc.classList.remove('active');
    charMascot.classList.remove('active');
    charNpc.style.display = 'none';
    charMascot.style.display = 'none';
    if (line.speaker === 'npc') {
      charNpc.style.display = 'flex';
      charNpc.classList.add('active');
    } else if (line.speaker === 'mascot') {
      charMascot.style.display = 'flex';
      charMascot.classList.add('active');
    }
    overlay.classList.toggle('narrator-mode', line.speaker === 'narrator');

    // Speaker label
    speakerLabel.classList.toggle('narrator', line.speaker === 'narrator');
    if (line.speaker === 'npc') speakerLabel.textContent = npc.name;
    else if (line.speaker === 'mascot') speakerLabel.textContent = mascotName();
    else speakerLabel.textContent = '旁白';

    await typeLine(text, line.text, 22);
    nextArrow.classList.add('shown');
    await waitForDialogTap();
    nextArrow.classList.remove('shown');
  }

  overlay.classList.add('hidden');
  window.sfx?.play('ui_close');
}

// Walk-in animation: character slides in from left + door zoom + backdrop fade
function playWalkIntoStore(scene) {
  return new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'store-walk-overlay';
    // Prefer the store's own SVG illustration as the "building" you walk into
    const doorIconName = STORE_ICON_MAP[scene.key];
    const doorHtml = doorIconName && window.icon
      ? window.icon(doorIconName, { size: 280 })
      : `<span style="font-size:160px">${scene.emoji || '🚪'}</span>`;
    // Street scenery: sidewalk + trees/flowers around the storefront
    const sideDeco = window.icon
      ? `
        <div class="store-walk-tree left">${window.icon('tree', { size: 72 })}</div>
        <div class="store-walk-tree right">${window.icon('palm', { size: 72 })}</div>
        <div class="store-walk-flower f1">${window.icon('flower', { size: 28 })}</div>
        <div class="store-walk-flower f2">${window.icon('flower', { size: 28 })}</div>
        <div class="store-walk-flower f3">${window.icon('flower', { size: 28 })}</div>
      ` : '';
    overlay.innerHTML = `
      <div class="store-walk-bg" style="background: var(--walk-bg);"></div>
      <div class="store-walk-street"></div>
      <div class="store-walk-sidewalk"></div>
      ${sideDeco}
      <div class="store-walk-door">${doorHtml}</div>
      <div class="store-walk-label">進入 ${scene.name}...</div>
      <div class="store-walk-char">${renderCharacter({
        animal: state.animal, level: state.lastLevel || 1, mood: 'happy', outfit: 'none',
      })}</div>
    `;
    // Backdrop color from scene
    const bg = ({
      conv_store: 'linear-gradient(180deg, #B8D0B8, #4E6B50)',
      supermarket: 'linear-gradient(180deg, #E8B888, #704A2A)',
      fastfood: 'linear-gradient(180deg, #E8C048, #805010)',
      cafe: 'linear-gradient(180deg, #A88870, #301A10)',
      night_market: 'linear-gradient(180deg, #3A2040, #120A1A)',
      bubble_tea: 'linear-gradient(180deg, #F8C8A8, #802A18)',
      sushi: 'linear-gradient(180deg, #D8E5F0, #506880)',
      healthy: 'linear-gradient(180deg, #D0E8D0, #305030)',
      fp_shop: 'linear-gradient(180deg, #D8F0D8, #3A7A3A)',
      fp_base: 'linear-gradient(180deg, #C89820, #4A3800)',
    })[scene.backdrop] || 'linear-gradient(180deg, #C6AE80, #5B4530)';
    overlay.style.setProperty('--walk-bg', bg);
    overlay.querySelector('.store-walk-bg').style.background = bg;
    document.body.appendChild(overlay);
    window.sfx?.play('card_draw');
    setTimeout(() => {
      overlay.remove();
      resolve();
    }, 1400);
  });
}

function typeLine(el, fullText, msPerChar) {
  return new Promise((resolve) => {
    el.textContent = '';
    el.classList.add('typing');
    let i = 0;
    let skipped = false;
    const tick = () => {
      if (skipped) { el.textContent = fullText; finish(); return; }
      if (i >= fullText.length) { finish(); return; }
      el.textContent = fullText.slice(0, ++i);
      if (i % 2 === 0) window.sfx?.play('choice_hover');
      setTimeout(tick, msPerChar);
    };
    const skipListener = () => { skipped = true; };
    // Clicking during type finishes it immediately
    const overlay = document.getElementById('dialog-overlay');
    overlay.addEventListener('click', skipListener, { once: true });
    function finish() {
      el.classList.remove('typing');
      overlay.removeEventListener('click', skipListener);
      resolve();
    }
    tick();
  });
}

function waitForDialogTap() {
  return new Promise((resolve) => {
    const overlay = document.getElementById('dialog-overlay');
    const handler = () => {
      overlay.removeEventListener('click', handler);
      resolve();
    };
    // Small delay so the "tap to skip" during typing doesn't also trigger next
    setTimeout(() => overlay.addEventListener('click', handler, { once: true }), 60);
  });
}

function _ensureModalRoot() {
  let r = document.getElementById('ui-modal-root');
  if (!r) {
    r = document.createElement('div');
    r.id = 'ui-modal-root';
    r.className = 'ui-modal-root';
    document.body.appendChild(r);
  }
  return r;
}
function _escape(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c]));
}

async function api(method, path, body) {
  const headers = { 'content-type': 'application/json' };
  if (state.token) headers['authorization'] = `Bearer ${state.token}`;
  const res = await fetch(API + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error?.message || 'request failed');
  return json.data;
}

// Number tween (count-up animation) for key stats
function tweenNumber(el, to, { duration = 650, decimals = 0, suffix = '' } = {}) {
  if (!el) return;
  const from = Number(String(el.textContent).replace(/[^\d.-]/g, '')) || 0;
  if (from === to) { el.textContent = to.toFixed(decimals) + suffix; return; }
  const start = performance.now();
  const diff = to - from;
  function frame(now) {
    const t = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - t, 3); // ease-out cubic
    const v = from + diff * eased;
    el.textContent = (decimals ? v.toFixed(decimals) : Math.round(v)) + suffix;
    if (t < 1) requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
}

function toast(msg, opts = {}) {
  const root = $('#toast-root');
  const el = document.createElement('div');
  el.className = 'toast';
  el.textContent = msg;
  if (opts.emoji) el.textContent = opts.emoji + ' ' + msg;
  root.appendChild(el);
  setTimeout(() => el.remove(), 2700);
}

// === Confetti ===
function confetti(origin = null) {
  const root = $('#confetti-root');
  const colors = ['#F4C5A3', '#D4A5A5', '#A8B89A', '#F4D78A', '#B8D4C4', '#E89F7A'];
  for (let i = 0; i < 40; i++) {
    const p = document.createElement('div');
    p.className = 'confetti-piece';
    p.style.left = (origin ? origin.x : Math.random() * window.innerWidth) + 'px';
    p.style.top = (origin ? origin.y : -20) + 'px';
    p.style.background = colors[Math.floor(Math.random() * colors.length)];
    p.style.animationDelay = (Math.random() * 0.3) + 's';
    p.style.transform = `rotate(${Math.random() * 360}deg)`;
    root.appendChild(p);
    setTimeout(() => p.remove(), 2800);
  }
}

function spawnHeart(x, y) {
  const root = $('#confetti-root');
  const el = document.createElement('div');
  el.className = 'heart-particle';
  el.textContent = ['💕','💖','❤️','✨','🌸'][Math.floor(Math.random()*5)];
  el.style.left = x + 'px';
  el.style.top = y + 'px';
  el.style.setProperty('--dx', (Math.random() * 40 - 20) + 'px');
  root.appendChild(el);
  setTimeout(() => el.remove(), 1100);
}

// === Character rendering ===
let SPIRIT_CACHE = null;
async function ensureSpirits() {
  if (SPIRIT_CACHE) return SPIRIT_CACHE;
  try { SPIRIT_CACHE = await api('GET', '/lore/spirits'); } catch { SPIRIT_CACHE = []; }
  return SPIRIT_CACHE;
}
async function paintWelcome() {
  $('#welcome-char').innerHTML = renderCharacter({ animal: state.animal, level: 1, mood: 'happy' });
  $$('#species-picker .sp-char').forEach((el) => {
    el.innerHTML = renderCharacter({ animal: el.dataset.mini, level: 1, mood: 'happy', mini: true });
  });
  await paintSpiritLabel(state.animal);
}
async function paintSpiritLabel(animalKey) {
  const spirits = await ensureSpirits();
  const s = spirits.find((x) => x.animal_key === animalKey);
  if (!s) return;
  $('#welcome-spirit-label').innerHTML = `
    <div class="spirit-meta">${escapeHtml(s.element)} · ${escapeHtml(s.mythology)}</div>
    <div class="spirit-title">${escapeHtml(s.spirit_title)}</div>
    <div class="spirit-story">${escapeHtml(s.story)}</div>
    <div class="spirit-motto">「${escapeHtml(s.motto)}」</div>
  `;
}

function paintMainCharacter(level, mood, animal, outfit) {
  $('#char-stage').innerHTML = renderCharacter({ animal, level, mood, outfit });
}

// === Auth / init ===
async function afterRegister(data) {
  state.userId = data.user.id;
  state.token = data.user.api_token;
  state.animal = data.user.avatar_animal || 'cat';
  localStorage.setItem('doudou_user', state.userId);
  localStorage.setItem('doudou_token', state.token);
  localStorage.setItem('doudou_animal', state.animal);
  // Play box-opening ceremony first, then enter main app
  await runBoxCeremony(state.animal);
  $('#screen-welcome').classList.add('hidden');
  $('#screen-ceremony').classList.add('hidden');
  $('#main').classList.remove('hidden');
  toast(`TDEE ${data.targets.tdee} 卡 · 目標 ${data.targets.daily_calorie_target} 卡`, { emoji: '✨' });
  confetti();
  await loadDashboard();
  await hydrateBootstrap();
  await runFirstRunGates();
}

async function runBoxCeremony(animalKey) {
  const spirits = await ensureSpirits();
  const s = spirits.find((x) => x.animal_key === animalKey);
  $('#screen-welcome').classList.add('hidden');
  $('#screen-ceremony').classList.remove('hidden');
  const lidL = $('#box-lid-left');
  const lidR = $('#box-lid-right');
  const rays = $('#box-rays');
  const glow = $('#box-glow');
  const sparkles = $('#box-sparkles');
  const spirit = $('#ceremony-spirit');
  const text = $('#ceremony-text');
  const btn = $('#ceremony-continue');

  // reset
  [lidL, lidR].forEach((el) => el.classList.remove('opened'));
  rays.classList.remove('shown');
  glow.classList.remove('shown');
  sparkles.classList.remove('shown');
  spirit.classList.remove('shown');
  text.classList.remove('shown');
  btn.classList.remove('shown');
  btn.classList.add('hidden');
  $('#ceremony-box')?.classList.remove('faded');
  spirit.innerHTML = '';
  text.innerHTML = '';
  $('#box-particles').innerHTML = '';

  // Sequence
  await wait(400);
  window.sfx?.play('box_open');
  lidL.classList.add('opened');
  lidR.classList.add('opened');
  await wait(350);
  glow.classList.add('shown');
  await wait(150);
  rays.classList.add('shown');
  sparkles.classList.add('shown');
  spawnBoxParticles(24);
  await wait(450);
  spirit.innerHTML = renderCharacter({ animal: animalKey, level: 1, mood: 'cheering' });
  spirit.classList.add('shown');
  // Fade the box so the spirit takes the visual focus
  $('#ceremony-box')?.classList.add('faded');
  confetti();
  await wait(600);
  if (s) {
    text.innerHTML = `
      <div class="c-subtitle">${escapeHtml(s.element)} · ${escapeHtml(s.mythology)}</div>
      <div class="c-title">${escapeHtml(s.spirit_title)}</div>
      <div class="c-story">${escapeHtml(s.story)}</div>
      <div class="c-motto">「${escapeHtml(s.motto)}」</div>
    `;
  } else {
    text.innerHTML = `<div class="c-title">新夥伴出現了</div>`;
  }
  text.classList.add('shown');
  await wait(500);
  btn.classList.remove('hidden');
  btn.classList.add('shown');

  return new Promise((resolve) => {
    btn.addEventListener('click', () => resolve(), { once: true });
  });
}

function spawnBoxParticles(n = 20) {
  const root = $('#box-particles');
  if (!root) return;
  for (let i = 0; i < n; i++) {
    const el = document.createElement('div');
    el.className = 'particle';
    const angle = (Math.random() - 0.5) * Math.PI;      // wide upward cone
    const dist = 80 + Math.random() * 140;
    const midDist = dist * 0.4;
    const px = Math.round(Math.sin(angle) * midDist);
    const py = -Math.round(Math.abs(Math.cos(angle)) * midDist);
    const pxe = Math.round(Math.sin(angle) * dist);
    const pye = -Math.round(Math.abs(Math.cos(angle)) * dist + 40);
    el.style.setProperty('--px', px + 'px');
    el.style.setProperty('--py-mid', py + 'px');
    el.style.setProperty('--px-end', pxe + 'px');
    el.style.setProperty('--py', pye + 'px');
    el.style.animationDelay = (Math.random() * 0.8) + 's';
    el.style.animationDuration = (2.2 + Math.random() * 1.8) + 's';
    el.style.width = (3 + Math.random() * 4) + 'px';
    el.style.height = el.style.width;
    root.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }
  // continue spawning more waves while ceremony is showing
  setTimeout(() => {
    if (!$('#screen-ceremony').classList.contains('hidden')) spawnBoxParticles(18);
  }, 1400);
}

function wait(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function tryResume() {
  if (!state.userId) return false;
  try {
    await hydrateBootstrap();
    await loadDashboard();
    $('#screen-welcome').classList.add('hidden');
    $('#main').classList.remove('hidden');
    await runFirstRunGates();
    return true;
  } catch (err) {
    console.warn('[doudou] resume failed, clearing stale localStorage:', err);
    localStorage.removeItem('doudou_user');
    localStorage.removeItem('doudou_token');
    state.userId = null; state.token = null;
    // welcome is the default; don't re-toggle to avoid hiding live #main
    return false;
  }
}

// =========================================================
// Analytics — fire-and-forget event tracking. Server forwards to PostHog
// when POSTHOG_API_KEY is configured server-side; otherwise stays in DB
// for replay. Frontend never needs to know which provider is live.
// =========================================================
const ANALYTICS_QUEUE = [];
let analyticsFlushTimer = null;
function trackEvent(event, properties) {
  ANALYTICS_QUEUE.push({ user_id: state.userId || null, event, properties: properties || {} });
  if (!analyticsFlushTimer) {
    analyticsFlushTimer = setTimeout(flushAnalytics, 1500);
  }
}
async function flushAnalytics() {
  analyticsFlushTimer = null;
  const batch = ANALYTICS_QUEUE.splice(0, ANALYTICS_QUEUE.length);
  for (const e of batch) {
    try { await fetch(API + '/analytics/track', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(e) }); }
    catch { /* swallow — analytics must never break the app */ }
  }
}
window.trackEvent = trackEvent;

// =========================================================
// Full-screen paywall — shows pricing + trial state. One version (no A/B
// at 0-1000 user stage). Trigger via openPaywall('scan_quota_exhausted').
// =========================================================
let paywallCache = null;
async function openPaywall(trigger) {
  if (!state.userId) return;
  let view = paywallCache;
  try {
    view = await api('GET', `/paywall/${state.userId}?trigger=${encodeURIComponent(trigger || 'manual_open')}`);
    paywallCache = view;
  } catch (e) {
    console.warn('[paywall] fetch failed:', e.message);
    if (!view) return;
  }
  trackEvent('paywall_shown', { trigger });
  const c = view.content;
  const trialBanner = view.user?.on_trial
    ? `<div class="paywall-trial-banner">免費試用還剩 <b>${view.user.trial_days_left}</b> 天</div>`
    : view.user?.trial_state === 'expired'
    ? `<div class="paywall-trial-banner expired">試用已結束 — 訂閱繼續陪你</div>`
    : '';
  const tiersHtml = c.tiers.map((t) => `
    <button class="paywall-tier${t.badge ? ' featured' : ''}" data-tier="${t.key}" type="button">
      ${t.badge ? `<span class="paywall-tier-badge">${t.badge}</span>` : ''}
      <div class="paywall-tier-label">${t.label}</div>
      <div class="paywall-tier-price">NT$${t.price_twd}<small>${t.period_label}</small></div>
      <ul>${t.highlights.map((h) => `<li>${h}</li>`).join('')}</ul>
      <div class="paywall-tier-cta">${t.cta} →</div>
    </button>
  `).join('');
  const overlay = document.createElement('div');
  overlay.className = 'paywall-overlay';
  overlay.innerHTML = `
    <div class="paywall-backdrop"></div>
    <div class="paywall-panel">
      <button class="paywall-close" id="paywall-close" aria-label="關閉">×</button>
      ${trialBanner}
      <div class="paywall-hero">
        <div class="paywall-eyebrow">${c.hero.eyebrow}</div>
        <h2>${c.hero.title}</h2>
        <p>${c.hero.subtitle}</p>
      </div>
      <div class="paywall-benefits">
        ${c.benefits.map((b) => `
          <div class="paywall-benefit">
            <div class="paywall-benefit-icon">${b.icon}</div>
            <div><b>${b.title}</b><div class="paywall-benefit-sub">${b.sub}</div></div>
          </div>
        `).join('')}
      </div>
      <div class="paywall-tiers">${tiersHtml}</div>
      <div class="paywall-trust">${c.trust_strip.map((t) => `<span>✓ ${t}</span>`).join('')}</div>
      <p class="paywall-legal">${c.legal_footnote}</p>
    </div>
  `;
  document.body.appendChild(overlay);
  const close = (action = 'dismissed') => {
    api('POST', '/paywall/event', { user_id: state.userId, action, trigger }).catch(() => {});
    overlay.classList.add('closing');
    setTimeout(() => overlay.remove(), 280);
  };
  overlay.querySelector('#paywall-close').addEventListener('click', () => close('dismissed'));
  overlay.querySelector('.paywall-backdrop').addEventListener('click', () => close('dismissed'));
  overlay.querySelectorAll('.paywall-tier').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tier = btn.dataset.tier;
      api('POST', '/paywall/event', { user_id: state.userId, action: 'cta_clicked', tier, trigger }).catch(() => {});
      trackEvent('paywall_cta_clicked', { tier, trigger });
      // Hand off to native IAP via Capacitor (placeholder — real flow lands when IAP token is wired)
      if (window.purchaseSubscription) window.purchaseSubscription(tier);
      else toast('訂閱流程上線後可購買 — 試用期不受影響', { emoji: '🛒' });
      close('cta_clicked');
    });
  });
}
window.openPaywall = openPaywall;

// Hook 429 USAGE_LIMIT responses → auto-open paywall with the trigger reason
const _origApi = api;
window._apiOriginal = _origApi;
window.apiWithPaywall = async function(method, path, body) {
  try { return await _origApi(method, path, body); }
  catch (e) {
    const m = String(e.message || '');
    if (m.includes('USAGE_LIMIT') || m.includes('QUOTA_EXHAUSTED')) {
      openPaywall(m.includes('scan') ? 'scan_quota_exhausted' : 'island_quota_exhausted');
    }
    throw e;
  }
};

// =========================================================
// App-store rating prompt — backend decides timing; we ask Capacitor
// to fire native review or fall back to in-app modal.
// =========================================================
async function maybeShowRatingPrompt(trigger) {
  if (!state.userId) return;
  try {
    const r = await api('GET', `/rating-prompt/${state.userId}?trigger=${encodeURIComponent(trigger || 'app_open')}`);
    if (!r.should_show) return;
    api('POST', '/rating-prompt/event', { user_id: state.userId, action: 'shown' }).catch(() => {});
    trackEvent('rating_prompt_shown', { trigger });
    // Capacitor native review (when @capacitor/app-rate or similar is installed)
    if (window.Capacitor?.Plugins?.RateApp?.requestReview) {
      try { await window.Capacitor.Plugins.RateApp.requestReview(); }
      catch { showInAppRatingFallback(trigger); }
    } else {
      showInAppRatingFallback(trigger);
    }
  } catch (e) {
    console.warn('[rating] check failed:', e.message);
  }
}
function showInAppRatingFallback(trigger) {
  const overlay = document.createElement('div');
  overlay.className = 'first-run-overlay rating-fallback-overlay';
  overlay.innerHTML = `
    <div class="first-run-card" style="max-width:340px;text-align:center;">
      <div style="font-size:54px;margin-bottom:12px;">⭐</div>
      <h3 style="font-size:20px;font-weight:900;margin-bottom:8px;">喜歡豆豆嗎？</h3>
      <p style="font-size:14px;color:#6B5248;margin-bottom:18px;">給我們 5 顆星，讓更多人遇見豆豆 🫶</p>
      <button class="first-run-btn" id="rate-yes" style="margin-bottom:8px;">好啊，給五星 →</button>
      <button id="rate-later" style="background:transparent;border:none;color:#999;font-size:13px;cursor:pointer;padding:8px;">下次再說</button>
    </div>
  `;
  document.body.appendChild(overlay);
  overlay.querySelector('#rate-yes').addEventListener('click', () => {
    api('POST', '/rating-prompt/event', { user_id: state.userId, action: 'rated' }).catch(() => {});
    trackEvent('rating_prompt_rated', { trigger });
    const url = state.iosBundleId
      ? `https://apps.apple.com/app/id${state.iosBundleId}?action=write-review`
      : 'https://doudou.freeco.tw';
    window.open(url, '_blank');
    overlay.remove();
  });
  overlay.querySelector('#rate-later').addEventListener('click', () => {
    api('POST', '/rating-prompt/event', { user_id: state.userId, action: 'dismissed' }).catch(() => {});
    overlay.remove();
  });
}
window.maybeShowRatingPrompt = maybeShowRatingPrompt;

// =========================================================
// Referral panel — share invite code + show stats
// =========================================================
async function openReferralPanel() {
  if (!state.userId) return;
  let stats;
  try { stats = await api('GET', `/referrals/${state.userId}`); }
  catch (e) { toast('載入失敗：' + e.message); return; }
  trackEvent('referral_panel_opened');
  const overlay = document.createElement('div');
  overlay.className = 'first-run-overlay referral-overlay';
  overlay.innerHTML = `
    <div class="first-run-card" style="max-width:380px;">
      <div style="text-align:center;font-size:54px;margin-bottom:8px;">🎁</div>
      <h3 style="font-size:22px;font-weight:900;text-align:center;margin-bottom:8px;">邀請朋友</h3>
      <p style="font-size:14px;color:#6B5248;text-align:center;margin-bottom:20px;">朋友用你的邀請碼註冊，<b>雙方各得 7 天免費試用</b></p>
      <div style="background:#FFF5E8;border:2px dashed #E89F7A;border-radius:14px;padding:18px;text-align:center;margin-bottom:14px;">
        <div style="font-size:11px;color:#999;letter-spacing:2px;margin-bottom:4px;">你的邀請碼</div>
        <div id="ref-code" style="font-size:30px;font-weight:900;letter-spacing:4px;color:#E89F7A;">${stats.code}</div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
        <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:10px;text-align:center;">
          <div style="font-size:24px;font-weight:900;">${stats.invited_count}</div>
          <div style="font-size:11px;color:#999;">已邀請</div>
        </div>
        <div style="background:#fff;border:1px solid #eee;border-radius:10px;padding:10px;text-align:center;">
          <div style="font-size:24px;font-weight:900;">${stats.reward_days_earned}</div>
          <div style="font-size:11px;color:#999;">試用天數獎勵</div>
        </div>
      </div>
      <button class="first-run-btn" id="ref-share">分享給朋友 →</button>
      <button id="ref-close" style="display:block;width:100%;background:transparent;border:none;color:#999;font-size:13px;cursor:pointer;padding:14px 0 0;">關閉</button>
    </div>
  `;
  document.body.appendChild(overlay);
  overlay.querySelector('#ref-share').addEventListener('click', async () => {
    const shareUrl = `${location.origin}/?ref=${stats.code}`;
    const shareText = `我在用「豆豆 Doudou」減脂 App，輸入我的邀請碼 ${stats.code}，雙方各得 7 天免費試用 🫶`;
    trackEvent('referral_share_clicked');
    if (navigator.share) {
      try { await navigator.share({ title: '豆豆 Doudou', text: shareText, url: shareUrl }); }
      catch { /* user cancelled */ }
    } else {
      try { await navigator.clipboard.writeText(`${shareText}\n${shareUrl}`); toast('已複製到剪貼簿', { emoji: '📋' }); }
      catch { toast('請手動複製：' + stats.code); }
    }
  });
  overlay.querySelector('#ref-close').addEventListener('click', () => overlay.remove());
}
window.openReferralPanel = openReferralPanel;

// =========================================================
// Account deletion (Apple Store guideline 5.1.1(v) compliance)
// =========================================================
async function requestAccountDeletion() {
  if (!state.userId) return;
  const confirmed = await new Promise((resolve) => {
    const overlay = document.createElement('div');
    overlay.className = 'first-run-overlay';
    overlay.innerHTML = `
      <div class="first-run-card" style="max-width:380px;">
        <div style="font-size:54px;text-align:center;margin-bottom:12px;">⚠️</div>
        <h3 style="font-size:20px;font-weight:900;text-align:center;margin-bottom:10px;">確定要刪除帳號嗎？</h3>
        <p style="font-size:14px;color:#6B5248;line-height:1.7;margin-bottom:16px;">
          帳號會在 <b>7 天</b> 後從系統永久清除，包括所有記錄、照片、訂閱資料。<br/>
          7 天內可以從信箱裡的連結復原。
        </p>
        <button id="del-yes" style="width:100%;background:#E07060;color:#fff;border:none;padding:12px;border-radius:999px;font-weight:800;cursor:pointer;margin-bottom:8px;">確定刪除</button>
        <button id="del-no" style="width:100%;background:transparent;border:1px solid #ccc;padding:12px;border-radius:999px;cursor:pointer;">取消</button>
      </div>
    `;
    document.body.appendChild(overlay);
    overlay.querySelector('#del-yes').addEventListener('click', () => { overlay.remove(); resolve(true); });
    overlay.querySelector('#del-no').addEventListener('click', () => { overlay.remove(); resolve(false); });
  });
  if (!confirmed) return;
  try {
    const r = await api('POST', '/account/delete-request', { user_id: state.userId });
    trackEvent('account_deletion_requested');
    toast(`帳號將於 ${r.hard_delete_after.slice(0, 10)} 永久刪除。已寄出復原連結到信箱。`, { emoji: '✅' });
  } catch (e) {
    toast('刪除請求失敗：' + e.message);
  }
}
window.requestAccountDeletion = requestAccountDeletion;

// --- Push token registration — call this from the Capacitor PushNotifications
//     'registration' listener with the device token. Web / dev can skip.
window.registerDevicePushToken = async function(platform, token, deviceInfo) {
  if (!state.userId || !token) return;
  try {
    await api('POST', '/push/register', { user_id: state.userId, platform, token, device_info: deviceInfo });
  } catch (e) {
    console.warn('[push] register failed:', e.message);
  }
};

// --- Bootstrap: one call at boot hydrates every client-facing config value ---
// Anything that used to be a hard-coded const (GOAL_CHEERS, STORE_NPC_ANIMAL,
// INTENT_ICON_MAP, etc.) now lives in state.config, populated from /api/bootstrap.
// Server bumps content_version when admin edits content — client can poll or
// re-hydrate on next app resume.
const BOOTSTRAP_CACHE_KEY = 'doudou.bootstrap.v1';
async function hydrateBootstrap() {
  const path = state.userId ? `/bootstrap/${state.userId}` : '/bootstrap';
  try {
    const data = await api('GET', path);
    state.bootstrap = data;
    state.config = data.config || {};
    state.contentVersion = data.content_version;
    if (data.settings) {
      state.settings = data.settings;
      localStorage.setItem(SETTINGS_CACHE_KEY, JSON.stringify(data.settings));
      if (Number.isFinite(Number(data.settings.daily_water_goal_ml))) {
        localStorage.setItem('doudou.waterTargetMl', String(data.settings.daily_water_goal_ml));
      }
    }
    localStorage.setItem(BOOTSTRAP_CACHE_KEY, JSON.stringify(data));
    return data;
  } catch (e) {
    console.warn('[bootstrap] online hydrate failed, using cache:', e.message);
    try {
      const raw = localStorage.getItem(BOOTSTRAP_CACHE_KEY);
      if (raw) {
        const cached = JSON.parse(raw);
        state.bootstrap = cached;
        state.config = cached.config || {};
        state.contentVersion = cached.content_version;
        if (cached.settings) state.settings = cached.settings;
      }
    } catch {}
    return state.bootstrap || null;
  }
}
// Convenience getter: `cfg('goal_cheers.water')` walks the config tree.
function cfg(path, fallback = null) {
  const c = state.config || {};
  const parts = String(path).split('.');
  let cur = c;
  for (const p of parts) {
    if (cur == null || typeof cur !== 'object') return fallback;
    cur = cur[p];
  }
  return cur == null ? fallback : cur;
}

// --- Settings sync: server is source of truth; localStorage is a cache ---
const SETTINGS_CACHE_KEY = 'doudou.settings';
async function syncSettingsFromServer() {
  if (!state.userId) return null;
  try {
    const s = await api('GET', `/user/${state.userId}/settings`);
    state.settings = s;
    localStorage.setItem(SETTINGS_CACHE_KEY, JSON.stringify(s));
    // Hydrate water target cache used by legacy code path
    if (Number.isFinite(Number(s.daily_water_goal_ml))) {
      localStorage.setItem('doudou.waterTargetMl', String(s.daily_water_goal_ml));
    }
    return s;
  } catch (e) {
    console.warn('[settings] sync failed, falling back to cache:', e.message);
    try {
      const raw = localStorage.getItem(SETTINGS_CACHE_KEY);
      if (raw) state.settings = JSON.parse(raw);
    } catch {}
    return state.settings || null;
  }
}
async function patchSettings(patch) {
  if (!state.userId) return;
  try {
    const s = await api('PATCH', `/user/${state.userId}/settings`, patch);
    state.settings = { ...(state.settings || {}), ...(s || {}) };
    localStorage.setItem(SETTINGS_CACHE_KEY, JSON.stringify(state.settings));
    return s;
  } catch (e) {
    console.warn('[settings] patch failed:', e.message);
    throw e;
  }
}

// --- First-run gates: disclaimer before onboarding, onboarding before island ---
async function runFirstRunGates() {
  const s = state.settings || {};
  if (!s.disclaimer_ack_at) {
    await showDisclaimerModal();
    try { await patchSettings({ disclaimer_ack: true }); } catch {}
  }
  if (!s.onboarded_at) {
    await showOnboarding();
    try { await patchSettings({ onboarded: true }); } catch {}
  }
}

// === Dashboard ===
// Generic: swap any element with data-icon="name" to its SVG (idempotent)
function swapDataIcons(root = document) {
  if (!window.icon) return;
  root.querySelectorAll('[data-icon]').forEach((el) => {
    if (el.dataset.iconRendered === '1') return;
    const name = el.dataset.icon;
    const size = Number(el.dataset.iconSize || 28);
    const cls = el.dataset.iconCls || '';
    const svg = window.icon(name, { size, cls });
    if (svg) {
      el.innerHTML = svg;
      el.dataset.iconRendered = '1';
    }
  });
}

// Paint all custom SVG icons used across the home UI (idempotent)
function paintCustomIcons() {
  if (!window.icon) return;
  swapDataIcons();
  const set = (id, name, opts) => {
    const el = document.getElementById(id);
    if (el && !el.innerHTML.includes('<svg')) el.innerHTML = window.icon(name, opts);
  };
  // Care row buttons
  set('care-icon-water1', 'water', { size: 34, cls: 'bounce' });
  set('care-icon-water2', 'water_double', { size: 34, cls: 'bounce' });
  set('care-icon-exercise', 'dumbbell', { size: 34, cls: 'wiggle' });
  set('care-icon-scale', 'scale', { size: 34, cls: 'pulse' });
  // Goal bars + streak chip
  set('goal-icon-water', 'water', { size: 18 });
  set('goal-icon-exercise', 'exercise', { size: 18 });
  set('care-icon-streak', 'fire', { size: 16, cls: 'flame' });
  // Home tiles
  set('tile-icon-cards', 'card', { size: 42, cls: 'pulse' });
  set('tile-icon-island', 'island', { size: 42, cls: 'float' });
  set('tile-icon-scan', 'camera', { size: 42, cls: 'wiggle' });
  // Quest title icon
  const qtIcon = document.querySelector('.quest-title > span:first-child');
  if (qtIcon && qtIcon.textContent.trim() === '🎯') qtIcon.innerHTML = window.icon('target', { size: 20 });
  // Journey icon in header
  const jiIcon = document.querySelector('.journey-icon');
  if (jiIcon && jiIcon.textContent.trim() === '🗺️') jiIcon.innerHTML = window.icon('map', { size: 22 });
}

function paintMascotChrome() {
  const m = mascotName();
  const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
  set('scan-hint', `對著食物拍一張，${m}幫你打分數跟算卡路里`);
  set('sees-badge', `只有${m}知道`);
  set('letter-header', `${m}給你的信`);
  set('letter-empty-hint', `至少 3 天有紀錄${m}才能寫有內容的週報`);
  set('me-universe-title', `${m}的家 ✨`);
  set('wardrobe-sub', `解鎖更多裝扮，幫${m}換上你最愛的造型`);
  set('report-sub', `${m}寫給你的一封信，每週一更新`);
  const chatInput = document.getElementById('chat-input');
  if (chatInput) chatInput.placeholder = `跟${m}說說今天...`;
}

async function loadDashboard() {
  const d = await api('GET', `/user/${state.userId}/dashboard`);
  paintMascotChrome();
  paintCustomIcons();
  refreshEventBanner();
  // Brute-force ensure tab-home is visible (some browsers leave the entry animation stuck)
  ['tab-home','tab-island','tab-scan','tab-chat','tab-calendar','tab-wardrobe','tab-pokedex','tab-achievements','tab-report','tab-cards-codex'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) {
      el.style.opacity = '1';
      el.style.transform = 'none';
      el.style.animation = 'none';
      el.style.visibility = 'visible';
    }
  });
  const level = d.doudou.level;
  const animal = d.user.avatar_animal || state.animal;
  state.animal = animal;
  state.lastAnimalMood = d.doudou.mood;

  // Detect level up since last render
  if (level > state.lastLevel) {
    confetti();
    toast(`升級到 LV.${level} · ${d.doudou.level_name}！`, { emoji: '🎉' });
  }
  state.lastLevel = level;

  paintMainCharacter(level, d.doudou.mood, animal, d.user.equipped_outfit);
  $('#char-speech').textContent = d.doudou.mood_phrase;

  $('#m-level').textContent = level;
  $('#m-level-name').textContent = d.doudou.level_name;
  $('#m-xp-label').textContent = d.doudou.xp;
  $('#m-xp-next').textContent = d.doudou.xp_next_level || '已滿';
  $('#m-xp-fill').style.width = Math.round((d.doudou.xp_progress || 0) * 100) + '%';

  // Next evolution milestone hint
  const evolveAt = [5, 10, 15, 20, 30, 50, 100];
  const nextEvo = evolveAt.find((lv) => lv > level);
  const evoEl = $('#m-evolution-hint');
  if (evoEl) {
    if (nextEvo) evoEl.innerHTML = `下次進化：LV.<b>${nextEvo}</b> · 還有 ${nextEvo - level} 個等級`;
    else evoEl.textContent = '已到頂階 · 傳說等級 ✨';
  }

  // Calorie ring
  const tgt = d.user.daily_calorie_target;
  const consumed = d.today.total_calories;
  const pct = Math.min(1, consumed / tgt);
  const circ = 2 * Math.PI * 42;
  $('#ring-fill').setAttribute('stroke-dasharray', circ);
  $('#ring-fill').setAttribute('stroke-dashoffset', circ * (1 - pct));
  // Color shift near/over limit
  const ring = $('#ring-fill');
  if (pct >= 1.1) ring.style.stroke = '#D4A5A5';
  else if (pct >= 0.95) ring.style.stroke = '#E89F7A';
  else ring.style.stroke = '';

  tweenNumber($('#m-calories'), consumed);
  $('#m-target').textContent = tgt;
  tweenNumber($('#m-remaining'), d.today.remaining_calories, { suffix: ' 卡' });
  tweenNumber($('#m-protein'), Math.round(d.today.total_protein_g));
  tweenNumber($('#m-carbs'), Math.round(d.today.total_carbs_g ?? 0));
  tweenNumber($('#m-fat'), Math.round(d.today.total_fat_g ?? 0));

  $('#m-streak').textContent = d.doudou.streak;
  // Tamagotchi care — goal bars with progress
  const waterMl = d.today.water_ml ?? 0;
  const exerciseMin = d.today.exercise_minutes ?? 0;
  const waterTarget = getWaterTarget();
  const exerciseTarget = 30;
  const wEl = $('#care-stat-water'); if (wEl) wEl.textContent = waterMl;
  const wTEl = $('#care-target-water'); if (wTEl) wTEl.textContent = waterTarget;
  const eEl = $('#care-stat-exercise'); if (eEl) eEl.textContent = exerciseMin;
  const eTEl = $('#care-target-exercise'); if (eTEl) eTEl.textContent = exerciseTarget;
  const waterPct = Math.min(200, Math.round((waterMl / waterTarget) * 100));
  const exPct = Math.min(200, Math.round((exerciseMin / exerciseTarget) * 100));
  const wFill = $('#care-fill-water');
  const eFill = $('#care-fill-exercise');
  const wPctEl = $('#care-pct-water');
  const ePctEl = $('#care-pct-exercise');
  if (wFill) {
    wFill.style.width = Math.min(100, waterPct) + '%';
    wFill.classList.toggle('over', waterPct > 150);
  }
  if (wPctEl) {
    wPctEl.textContent = waterPct + '%';
    wPctEl.classList.toggle('hit', waterPct >= 100 && waterPct <= 150);
    wPctEl.classList.toggle('over', waterPct > 150);
  }
  if (eFill) {
    eFill.style.width = Math.min(100, exPct) + '%';
    eFill.classList.toggle('over', exPct > 200);
  }
  if (ePctEl) {
    ePctEl.textContent = exPct + '%';
    ePctEl.classList.toggle('hit', exPct >= 100);
  }
  // Celebrate once per day when each goal reaches 100%
  if (waterPct >= 100) showGoalCelebration('water');
  if (exPct >= 100) showGoalCelebration('exercise');
  const st = $('#care-stat-streak'); if (st) st.textContent = d.doudou.streak ?? 0;
  $('#m-shields').textContent = d.doudou.streak_shields;
  $('#m-today-score').textContent = d.today.total_score;
  const scoreEl = $('#m-today-score').parentElement.querySelector('.text-xl');
  const emojiMap = { cheering: '🎉', happy: '😊', proud: '🏆', content: '🙂', worried: '😟', sad: '🥺', missing_you: '🥺', sleeping: '😴' };
  scoreEl.textContent = emojiMap[d.doudou.mood] || '⭐';

  $('#m-friendship-num').textContent = d.doudou.friendship;
  $('#m-friendship-fill').style.width = Math.min(100, (d.doudou.friendship / 500) * 100) + '%';

  // Refresh gift state (show "!" badge or countdown)
  refreshGiftState();

  // Tasks
  $('#m-tasks').innerHTML = d.tasks.map((t) => `
    <div class="task-row ${t.done ? 'done' : ''}">
      <span>${t.done ? '✓' : '○'} ${t.title}</span>
      <span class="task-xp ${t.done ? 'done' : ''}">+${t.xp}</span>
    </div>`).join('');

  // Meals timeline
  const mealListEl = $('#m-meals-list');
  mealListEl.dataset.count = String(d.today.meals.length);
  mealListEl.innerHTML = d.today.meals.length ? d.today.meals.map((m) => `
    <div class="flex items-start gap-3">
      <div class="score-pill ${m.meal_score >= 80 ? '' : m.meal_score >= 60 ? 'hot' : 'cold'}">${m.meal_score ?? '-'}</div>
      <div class="flex-1">
        <div class="font-semibold text-ink">${m.food_name || '未知'} <span class="text-[10px] text-muted">· ${mealTypeLabel(m.meal_type)}</span></div>
        <div class="text-xs text-muted">${m.calories} 卡</div>
        <div class="text-xs mt-1" style="color: var(--ink-soft)">${stripLegacyMascot(m.coach_response || '')}</div>
      </div>
    </div>`).join('') : `
    <div class="empty-state">
      <div class="es-emoji">🍽️</div>
      <div class="es-title">今天還沒記錄餐食</div>
      <div class="es-hint">點上方 📷 拍照或搜尋食物<br/>紀錄一餐就開始累積分數跟 XP</div>
    </div>`;

  // Achievements
  $('#m-achievements').innerHTML = d.achievements.length
    ? d.achievements.map((a) => `<span class="badge-chip">🏅 ${a.achievement_name}</span>`).join('')
    : '<span class="text-muted">還沒有徽章，加油！</span>';

  // Gift button state
  // We don't have a "gift claimed today" flag from dashboard; a 2nd click returns claimed:false which we surface.

  // Auto-trigger suggest (now a no-op since home was redesigned) + action card
  loadSuggestions();
  refreshHomeCardsCta();
  // 21-day journey + daily quests
  paintJourneyAndQuests();
}

let _lastKnownJourneyDay = null;
async function paintJourneyAndQuests() {
  if (!state.userId) return;
  try {
    const [journey, quests] = await Promise.all([
      api('GET', `/journey/${state.userId}`),
      api('GET', `/quests/today/${state.userId}`),
    ]);
    // Detect advance since last paint → celebrate with animation/toast/sound
    const advanced = _lastKnownJourneyDay != null && journey.day > _lastKnownJourneyDay;
    _lastKnownJourneyDay = journey.day;
    renderJourney(journey, { animateAdvance: advanced });
    renderQuests(quests);
    if (advanced) {
      // Character bubble reaction + sound + toast
      const speech = document.getElementById('char-speech');
      if (speech) {
        speech.textContent = `Day ${journey.day}！繼續加油 🗺️`;
        speech.classList.remove('pulse-in'); void speech.offsetWidth; speech.classList.add('pulse-in');
      }
      toast(`🗺️ 旅程 Day ${journey.day}`);
      window.sfx?.play('xp');
      // Burst around character
      const stage = document.getElementById('char-stage');
      if (stage) {
        const rect = stage.getBoundingClientRect();
        for (let i = 0; i < 5; i++) {
          const el = document.createElement('div');
          el.className = 'care-burst';
          el.textContent = ['✨', '🌟', '🎯', '🐾', '💫'][i];
          el.style.setProperty('--dx', ((i - 2) * 24) + 'px');
          el.style.left = (rect.left + rect.width / 2) + 'px';
          el.style.top = (rect.top + rect.height * 0.4) + 'px';
          el.style.animationDelay = (i * 0.06) + 's';
          document.body.appendChild(el);
          setTimeout(() => el.remove(), 1400);
        }
      }
      if (journey.milestones.some((m) => m.day === journey.day)) {
        const crossed = journey.milestones.find((m) => m.day === journey.day);
        setTimeout(() => {
          toast(`${crossed.reward_emoji} ${crossed.label}！+${crossed.reward_xp} XP`, { emoji: '🎉' });
          window.sfx?.play('level_up');
          confetti();
        }, 400);
        // Story moment dialog (Day 3/7/14/21)
        setTimeout(() => maybePlayMilestoneStory(journey), 1200);
      }
    }
  } catch (e) { /* silent */ }
}

function renderJourney(j, opts = {}) {
  $('#journey-cycle').textContent = `第 ${j.cycle} 輪`;
  $('#journey-day').textContent = j.day;
  const subtitle = $('#journey-subtitle');
  const nextEl = $('#journey-next');
  const hintEl = $('#journey-today-hint');

  const milestoneIconMap = { 3: 'sprout', 7: 'fire', 14: 'gem', 21: 'crown' };
  const sm = (emoji) => window.icon ? window.icon(milestoneIconMap[j.next_milestone?.day] || 'sparkle', { size: 16 }) : emoji;
  if (j.next_milestone) {
    nextEl.innerHTML = `${sm(j.next_milestone.reward_emoji)} 還有 <b>${j.days_to_next_milestone}</b> 天 → <b>${j.next_milestone.label}</b>（+${j.next_milestone.reward_xp} XP）`;
  } else {
    nextEl.innerHTML = `${window.icon ? window.icon('crown', { size: 16 }) : '👑'} 本輪最終里程碑達成！下週開始第 ${j.cycle + 1} 輪`;
  }

  if (j.advanced_today) {
    hintEl.textContent = '✓ 今天已往前一格 · 明天繼續';
    hintEl.classList.add('done');
  } else {
    hintEl.textContent = '記一餐 / 喝 500ml / 運動 15 分 / 答對卡 → 今天就能前進一格';
    hintEl.classList.remove('done');
  }

  // Build 21 cells (horizontal scroll, auto-scroll to current)
  const board = $('#journey-board');
  board.innerHTML = '';
  const milestones = new Set(j.milestones.map((m) => m.day));
  const milestoneMeta = Object.fromEntries(j.milestones.map((m) => [m.day, m]));
  for (let d = 1; d <= j.total_days; d++) {
    const cell = document.createElement('div');
    const classes = ['journey-cell'];
    const isMilestone = milestones.has(d);
    if (isMilestone) classes.push('milestone');
    if (d < j.day) classes.push('done');
    if (isMilestone && d <= j.day) classes.push('done');
    if (d === j.day) classes.push('current');
    cell.className = classes.join(' ');
    // Milestone → custom SVG icon
    const milestoneIcon = {
      3: 'sprout', 7: 'fire', 14: 'gem', 21: 'crown',
    };
    if (d === j.day) {
      cell.innerHTML = `<div class="journey-cell-char">${renderCharacter({
        animal: state.animal, mini: true,
      })}</div>`;
    } else if (isMilestone) {
      const iconName = milestoneIcon[d];
      cell.innerHTML = iconName && window.icon
        ? window.icon(iconName, { size: 26, cls: d <= j.day ? 'pulse' : '' })
        : milestoneMeta[d].reward_emoji;
    } else if (d < j.day) {
      cell.innerHTML = window.icon ? window.icon('check', { size: 18 }) : '✓';
    } else {
      cell.textContent = String(d);
    }
    if (isMilestone) cell.title = `Day ${d}: ${milestoneMeta[d].label}`;
    if (d === j.day && opts.animateAdvance) cell.classList.add('just-advanced');
    board.appendChild(cell);
  }
  // Auto-scroll to current cell
  setTimeout(() => {
    const cur = board.querySelector('.current');
    if (cur) cur.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
  }, 50);
}

function renderQuests(q) {
  const list = $('#quest-list');
  list.innerHTML = '';
  const done = q.quests.filter((x) => x.completed).length;
  const label = $('#quest-progress-label');
  label.textContent = `${done} / ${q.quests.length} 完成`;
  label.classList.toggle('all-done', q.all_completed);

  for (const qt of q.quests) {
    const pct = Math.min(100, Math.round((qt.progress / qt.target) * 100));
    const row = document.createElement('div');
    row.className = `quest-row${qt.completed ? ' done' : ''}${qt.rarity === 'rare' ? ' rare' : ''}`;
    // Map quest emoji → custom SVG icon
    const questIconMap = {
      '🍱': 'meal_box', '🍽️': 'meal_box',
      '💧': 'water', '💦': 'water_double',
      '🏃': 'exercise', '💪': 'exercise',
      '🏪': 'store_conv', '🗺️': 'map',
      '🎴': 'card', '🎭': 'card',
      '✨': 'sparkle', '🎯': 'target',
    };
    const qIcon = questIconMap[qt.emoji];
    const qIconHtml = qIcon && window.icon
      ? window.icon(qIcon, { size: 28, cls: 'pulse' })
      : qt.emoji;
    row.innerHTML = `
      <div class="quest-emoji">${qIconHtml}</div>
      <div class="quest-body">
        <div class="quest-label">${escapeHtml(qt.label)}</div>
        <div class="quest-bar"><div class="quest-bar-fill" style="width:${pct}%"></div></div>
        <div class="quest-progress-text">${qt.progress} / ${qt.target}</div>
      </div>
      <div class="quest-reward">+${qt.reward_xp} XP</div>
    `;
    list.appendChild(row);
  }
}

function mealTypeLabel(t) {
  return { breakfast: '早餐', lunch: '午餐', dinner: '晚餐', snack: '點心' }[t] || t;
}

// === Tabs ===
// Map secondary tabs (only reachable from "我的") back to "me" for nav highlighting
const SECONDARY_TABS = new Set(['wardrobe', 'pokedex', 'achievements', 'report', 'cards-codex', 'calendar']);
const ALL_TABS = ['home','island','scan','chat','calendar','me','wardrobe','pokedex','achievements','report','cards-codex'];

function switchTab(tab) {
  const navHighlight = SECONDARY_TABS.has(tab) ? 'me' : tab;
  $$('.tab').forEach((b) => b.classList.toggle('active', b.dataset.tab === navHighlight));
  ALL_TABS.forEach((t) => {
    const el = $('#tab-' + t);
    if (!el) return;
    const wantVisible = (t === tab);
    el.classList.toggle('hidden', !wantVisible);
    if (wantVisible) {
      el.classList.remove('tab-anim');
      void el.offsetWidth;
      el.classList.add('tab-anim');
    }
  });
  if (tab === 'home') { loadDashboard(); loadSuggestions(); }
  if (tab === 'pokedex') loadPokedex();
  if (tab === 'chat') loadChatStarters();
  if (tab === 'calendar') loadCalendar();
  if (tab === 'wardrobe') loadWardrobe();
  if (tab === 'achievements') loadAchievements();
  if (tab === 'cards-codex') loadCardsCodex();
  if (tab === 'me') loadTierInfo();
  if (tab === 'report') loadWeekly();
  if (tab !== 'scan') stopCamera();
  window.scrollTo({ top: 0, behavior: 'instant' });
}

// === Camera ===
async function startCamera() {
  try {
    state.camStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment' }, audio: false,
    });
    const v = $('#camera-video');
    v.srcObject = state.camStream;
    $('#camera-wrap').classList.remove('hidden');
    $('#preview-wrap').classList.add('hidden');
    $('#btn-start-cam').classList.add('hidden');
    $('#btn-capture').classList.remove('hidden');
    $('#btn-retake').classList.add('hidden');
  } catch (err) {
    toast('無法開啟相機：' + err.message, { emoji: '⚠️' });
  }
}

function stopCamera() {
  if (state.camStream) {
    state.camStream.getTracks().forEach((t) => t.stop());
    state.camStream = null;
  }
}

function capturePhoto() {
  const v = $('#camera-video');
  const c = $('#camera-canvas');
  const w = Math.min(1024, v.videoWidth || 640);
  const h = Math.round((v.videoHeight || 480) * (w / (v.videoWidth || 640)));
  c.width = w; c.height = h;
  const ctx = c.getContext('2d');
  ctx.drawImage(v, 0, 0, w, h);
  const dataUrl = c.toDataURL('image/jpeg', 0.82);
  state.capturedBase64 = dataUrl.split(',')[1];
  stopCamera();
  $('#camera-wrap').classList.add('hidden');
  $('#preview-wrap').classList.remove('hidden');
  $('#preview-img').src = dataUrl;
  $('#btn-capture').classList.add('hidden');
  $('#btn-retake').classList.remove('hidden');
  $('#btn-log-meal').disabled = false;
  $('#btn-log-meal').textContent = `請${mascotName()}分析 🔎`;
  state.selectedFood = null;
}

function handleFilePicked(file) {
  const reader = new FileReader();
  reader.onload = (e) => {
    stopCamera();
    $('#camera-wrap').classList.add('hidden');
    $('#preview-wrap').classList.remove('hidden');
    $('#preview-img').src = e.target.result;
    state.capturedBase64 = e.target.result.split(',')[1];
    $('#btn-start-cam').classList.remove('hidden');
    $('#btn-capture').classList.add('hidden');
    $('#btn-retake').classList.remove('hidden');
    $('#btn-log-meal').disabled = false;
    $('#btn-log-meal').textContent = `請${mascotName()}分析 🔎`;
    state.selectedFood = null;
  };
  reader.readAsDataURL(file);
}

function resetCapture() {
  state.capturedBase64 = null;
  $('#preview-wrap').classList.add('hidden');
  $('#btn-retake').classList.add('hidden');
  $('#btn-start-cam').classList.remove('hidden');
  $('#btn-capture').classList.add('hidden');
  $('#btn-log-meal').disabled = true;
  $('#btn-log-meal').textContent = '請先拍照或選擇食物';
  $('#scan-result').classList.add('hidden');
}

// === Food search ===
async function searchFoods(q) {
  if (!q.trim()) { $('#food-results').innerHTML = ''; return; }
  const foods = await api('GET', `/foods/search?q=${encodeURIComponent(q)}`);
  if (!foods.length) { $('#food-results').innerHTML = '<div class="text-xs text-muted p-2">找不到～試試其他關鍵字</div>'; return; }
  $('#food-results').innerHTML = foods.map((f) => `
    <button type="button" data-name="${f.name_zh}" class="food-item w-full">
      <div class="food-title">${f.name_zh}</div>
      <div class="food-sub">${f.calories} 卡 · ${f.serving_description} · 蛋白 ${f.protein_g}g</div>
    </button>
  `).join('');
  $$('#food-results .food-item').forEach((el) => el.addEventListener('click', () => {
    $$('#food-results .food-item').forEach((e) => e.classList.remove('selected'));
    el.classList.add('selected');
    state.selectedFood = { name: el.dataset.name };
    state.capturedBase64 = null;
    $('#btn-log-meal').disabled = false;
    $('#btn-log-meal').textContent = `記錄「${el.dataset.name}」`;
  }));
}

// === Log meal ===
async function logMeal() {
  const meal_type = $('#log-meal-type').value;
  const body = { user_id: state.userId, meal_type };
  if (state.capturedBase64) {
    body.photo_base64 = state.capturedBase64;
  } else if (state.selectedFood) {
    body.food_name = state.selectedFood.name;
  } else {
    return;
  }
  const endpoint = state.capturedBase64 ? '/meals/scan' : '/meals/text';
  $('#btn-log-meal').disabled = true;
  $('#btn-log-meal').textContent = '分析中...';
  try {
    const r = await api('POST', endpoint, body);
    showScanResult(r);
    // Queue up full Pandora-box ceremonies for all meaningful unlocks
    const unlocks = collectUnlockRewards(r);
    for (const u of unlocks) enqueueReward(u);
    if (!unlocks.length && r.meal.meal_score >= 80) confetti();
    window.sfx?.play('meal_logged');
    await loadDashboard();
    // stamina bonus notice — record a meal → +1 card draw
    setTimeout(() => {
      toast('抽卡體力 +1 🎴', { emoji: '⚡' });
      window.sfx?.play('notify');
    }, 600);
  } catch (e) {
    toast('失敗：' + e.message, { emoji: '⚠️' });
  } finally {
    $('#btn-log-meal').disabled = false;
    $('#btn-log-meal').textContent = '再記錄一餐';
  }
}

// Queue of unlock rewards to show in sequence (box-opening modals)
const rewardQueue = [];
let rewardQueueBusy = false;

function enqueueReward(reward) {
  rewardQueue.push(reward);
  runRewardQueue();
}
async function runRewardQueue() {
  if (rewardQueueBusy) return;
  rewardQueueBusy = true;
  while (rewardQueue.length > 0) {
    const rw = rewardQueue.shift();
    await openRewardModalAwait(rw);
    await wait(200);
  }
  rewardQueueBusy = false;
}
function openRewardModalAwait(reward) {
  return new Promise(async (resolve) => {
    await openRewardModal(reward);
    const btn = $('#reward-close');
    const handler = () => {
      btn.removeEventListener('click', handler);
      resolve();
    };
    btn.addEventListener('click', handler);
  });
}

// Build reward-reveal payloads from logMeal result
function collectUnlockRewards(r) {
  const list = [];
  if (r.leveled_up) {
    list.push({
      emoji: '🌟',
      title: `升級 LV.${r.level_after}`,
      subtitle: `${mascotName()}更有精神了！每一次升級都是你自律的成果。`,
    });
  }
  if (r.pokedex && r.pokedex.new_shiny) {
    list.push({
      emoji: '🌟',
      title: '閃光食物',
      subtitle: '這餐拿到 90 分以上，這道食物升級成閃光版了 ✨',
    });
  }
  if (r.new_achievements && r.new_achievements.length) {
    for (const a of r.new_achievements) {
      list.push({ emoji: '🏅', title: `成就解鎖：${a.name}`, subtitle: a.description });
      trackEvent('achievement_unlocked', { key: a.key, name: a.name });
    }
    // First achievement of the session is a wow moment — try the rating prompt
    setTimeout(() => maybeShowRatingPrompt('achievement_unlocked'), 4000);
  }
  if (r.new_outfits && r.new_outfits.length) {
    const outfitNames = { scarf: '溫暖圍巾', glasses: '圓框眼鏡', headphones: '玫瑰耳機',
      straw_hat: '草帽', chef_hat: '主廚帽', angel_wings: '天使翅膀', devil_horns: '小惡魔角', halo: '光環' };
    for (const k of r.new_outfits) {
      list.push({ emoji: '👗', title: `新時裝：${outfitNames[k] || k}`, subtitle: '到衣櫃換裝看看 →' });
    }
  }
  return list;
}

function showScanResult(r) {
  const m = r.meal;
  const badges = [];
  // Low confidence warning (Demo stub mode can't really analyze photos)
  const lowConf = r.meal.ai_confidence != null && r.meal.ai_confidence < 0.55;
  const lowConfBanner = lowConf
    ? `<div class="low-conf-banner">
        <b>📸 辨識信心不足</b><br/>
        Demo 模式還無法真實分析照片（需要接 Claude Vision API）。<br/>
        請點「手動修改」或用下方搜尋重新選擇正確食物～
      </div>`
    : '';
  if (r.pokedex.is_new) badges.push('<span class="badge-chip">📖 新食物</span>');
  if (r.pokedex.new_shiny) badges.push('<span class="badge-chip">🌟 閃光！</span>');
  if (r.leveled_up) badges.push(`<span class="badge-chip">🎉 升級 LV.${r.level_after}</span>`);
  if (r.combos && r.combos.length) r.combos.forEach((c) => badges.push(`<span class="badge-chip">🧩 ${c.name}</span>`));
  if (r.new_achievements.length) r.new_achievements.forEach((a) => badges.push(`<span class="badge-chip">🏅 ${a.name}</span>`));
  if (r.new_outfits && r.new_outfits.length) r.new_outfits.forEach((k) => badges.push(`<span class="badge-chip">👗 新衣服解鎖</span>`));

  const scoreClass = m.meal_score >= 80 ? '' : m.meal_score >= 60 ? 'hot' : 'cold';
  $('#scan-result').innerHTML = `
    ${lowConfBanner}
    <div class="flex items-center gap-3 mb-3">
      <div class="score-pill ${scoreClass}" style="width:56px;height:56px;font-size:20px;">${m.meal_score}</div>
      <div class="flex-1">
        <div class="font-bold">${m.food_name}</div>
        <div class="text-xs text-muted">${m.calories} 卡 · 蛋白 ${m.protein_g}g · 碳水 ${m.carbs_g}g · 脂肪 ${m.fat_g}g</div>
      </div>
      <div class="text-right text-xs">
        <div class="text-muted">+XP</div>
        <div class="num font-black text-lg" style="color: var(--gold)">${r.xp_gained}</div>
      </div>
    </div>
    <div class="flex flex-wrap gap-2 mb-3">${badges.join('')}</div>
    <div class="text-sm p-3 rounded-xl" style="background: var(--cream);">${stripLegacyMascot(r.coach_response)}</div>
    <div class="mt-3 text-xs text-muted">
      辨識不正確？<button type="button" id="btn-correct" class="underline" style="color: var(--peach-deep)">手動修改</button>
    </div>
  `;
  $('#scan-result').classList.remove('hidden');
  $('#btn-correct')?.addEventListener('click', () => openCorrectionDialog(m.id));
}

async function openCorrectionDialog(mealId) {
  const name = await UI.prompt('正確的食物名稱？', { title: '🔧 修正餐食', placeholder: '例如：雞腿便當' });
  if (!name) return;
  try {
    await api('PUT', `/meals/${mealId}/correct`, { food_name: name });
    toast(`已更新～${mascotName()}學到了一課 📝`);
    loadDashboard();
  } catch (e) {
    UI.alert(e.message || '更新失敗', { title: '⚠️' });
  }
}

// === Quick actions (now also trigger Tamagotchi-style character reactions) ===
const _actionGuard = { lastCallAt: {} };
function _throttled(key, ms = 600) {
  const now = Date.now();
  const last = _actionGuard.lastCallAt[key] || 0;
  if (now - last < ms) return true;
  _actionGuard.lastCallAt[key] = now;
  return false;
}

async function quickWater(ml) {
  if (_throttled('water', 500)) return;
  try {
    const r = await api('POST', '/checkin/water', { user_id: state.userId, ml });
    reactCharacter('water', ml);
    if (r.capped) toast('今日水量已達上限（5000ml）', { emoji: '⚠️' });
    await loadDashboard();
  } catch (e) {
    UI.alert(e.message || '記錄失敗');
  }
}
async function quickExercise(minutes) {
  if (_throttled('exercise', 500)) return;
  try {
    const r = await api('POST', '/checkin/exercise', { user_id: state.userId, minutes });
    reactCharacter('exercise', minutes);
    if (r.capped) toast('今日運動已達上限（300 分）', { emoji: '⚠️' });
    await loadDashboard();
  } catch (e) {
    UI.alert(e.message || '記錄失敗');
  }
}

// Edit modal — lets user directly set today's total (fix mistakes).
async function editWaterTotal(current) {
  const v = await UI.prompt(`今天已喝 ${current}ml — 輸入正確數字（0-5000）`, {
    title: '💧 修正水量',
    inputType: 'number',
    defaultValue: String(current),
    placeholder: '例如 2000',
  });
  if (v === null) return;
  const ml = Number(v);
  if (!Number.isFinite(ml) || ml < 0 || ml > 5000) { UI.alert('請輸入 0 到 5000 之間的數字'); return; }
  try {
    await api('POST', '/checkin/water/set', { user_id: state.userId, ml });
    toast(`水量已更新為 ${ml}ml`);
    await loadDashboard();
  } catch (e) { UI.alert(e.message || '更新失敗'); }
}
async function editExerciseTotal(current) {
  const v = await UI.prompt(`今天已運動 ${current} 分鐘 — 輸入正確數字（0-300）`, {
    title: '🏃 修正運動時間',
    inputType: 'number',
    defaultValue: String(current),
    placeholder: '例如 30',
  });
  if (v === null) return;
  const minutes = Number(v);
  if (!Number.isFinite(minutes) || minutes < 0 || minutes > 300) { UI.alert('請輸入 0 到 300 之間的數字'); return; }
  try {
    await api('POST', '/checkin/exercise/set', { user_id: state.userId, minutes });
    toast(`運動時間已更新為 ${minutes} 分`);
    await loadDashboard();
  } catch (e) { UI.alert(e.message || '更新失敗'); }
}

// Tamagotchi feedback — floating emojis near character + speech bubble update.
function reactCharacter(kind, amount) {
  const stage = document.getElementById('char-stage');
  const speech = document.getElementById('char-speech');
  if (!stage) return;
  const rect = stage.getBoundingClientRect();
  const baseX = rect.left + rect.width / 2;
  const baseY = rect.top + rect.height * 0.4;

  const map = {
    water: { emojis: ['💧', '💦', '💧'], speeches: ['咕嚕咕嚕～好清涼 ✨', '謝謝水水！', '水水補充中 💧'], sound: 'notify' },
    exercise: { emojis: ['💨', '🏃', '💪'], speeches: ['一起動動身體！', '熱血燃燒中 🔥', '運動達人！'], sound: 'xp' },
    meal: { emojis: ['🍽️', '🍱', '💕'], speeches: ['等等再回來告訴我吃了什麼', '期待今天的餐點！'], sound: 'meal_logged' },
    weight: { emojis: ['⚖️', '📊', '✨'], speeches: ['數字只是參考，繼續加油！', '每天量都很棒！'], sound: 'ui_tap' },
    correct: { emojis: ['✨', '🎉', '💖'], speeches: ['答對了超棒！'], sound: 'correct' },
  };
  const cfg = map[kind] || map.water;

  // Spawn 3 floating emojis
  for (let i = 0; i < 3; i++) {
    const el = document.createElement('div');
    el.className = 'care-burst';
    el.textContent = cfg.emojis[i % cfg.emojis.length];
    const dx = (i - 1) * 28 + (Math.random() * 16 - 8);
    el.style.setProperty('--dx', dx + 'px');
    el.style.left = baseX + 'px';
    el.style.top = baseY + 'px';
    el.style.animationDelay = (i * 0.08) + 's';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 1400);
  }

  // Update speech bubble
  if (speech) {
    const phrase = cfg.speeches[Math.floor(Math.random() * cfg.speeches.length)];
    speech.textContent = phrase;
    speech.classList.remove('pulse-in');
    void speech.offsetWidth;
    speech.classList.add('pulse-in');
  }

  // Character wiggle
  const wrap = document.querySelector('#char-stage .char-wrap') || document.getElementById('char-stage');
  if (wrap) { wrap.classList.remove('wiggle'); void wrap.offsetWidth; wrap.classList.add('wiggle'); }

  // Sound
  window.sfx?.play(cfg.sound || 'ui_tap');

  // Subtle toast (backup for when scrolling past the character)
  const label = kind === 'water' ? `+${amount}ml` : kind === 'exercise' ? `+${amount} 分` : '';
  if (label) toast(label, { emoji: cfg.emojis[0] });
}
async function promptWeight() {
  const w = await UI.prompt('輸入今天的體重 (kg)：', { title: '⚖️ 體重紀錄', inputType: 'number', placeholder: '62.5' });
  if (!w) return;
  const r = await api('POST', '/checkin/weight', { user_id: state.userId, weight_kg: Number(w) });
  toast(r.xp_gained ? `${r.weight_kg}kg · +${r.xp_gained}XP` : `${r.weight_kg}kg`, { emoji: '⚖️' });
  await loadDashboard();
}

// === Chat ===
async function sendChat() {
  const input = $('#chat-input');
  const text = input.value.trim();
  if (!text) return;
  input.value = '';
  appendBubble('user', text);
  const r = await api('POST', '/chat/message', { user_id: state.userId, text });
  appendBubble('bot', r.reply);
  if (r.safety_triggered) toast('已切換安全模式', { emoji: '🛟' });
}
function appendBubble(role, text) {
  const c = $('#chat-messages');
  const el = document.createElement('div');
  el.className = 'msg ' + role;
  el.innerHTML = `<div class="bubble">${escapeHtml(text)}</div>`;
  c.appendChild(el);
  c.scrollTop = c.scrollHeight;
}
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// === Pokedex ===
// Specific food-name → icon mapping (takes precedence over category)
const FOOD_ICON = {
  '珍珠奶茶':'🧋','奶茶':'🧋','紅茶拿鐵':'🧋','鐵觀音拿鐵':'🧋','抹茶拿鐵':'🍵','青茶':'🍵','四季春':'🍵','冬瓜茶':'🧋','多多綠茶':'🧋','檸檬紅茶':'🍋','鮮奶茶':'🧋','檸檬多多':'🍋','仙草凍飲':'🧋','芋頭鮮奶':'🧋','黑糖珍珠鮮奶':'🧋',
  '雞腿便當':'🍱','排骨便當':'🍱','滷排骨便當':'🍱','雞排便當':'🍱','焢肉便當':'🍱','池上便當':'🍱','素食便當':'🥗','魚排便當':'🍱','烤鯖魚便當':'🐟','三寶飯':'🍱',
  '白飯':'🍚','糙米飯':'🍚','滷肉飯':'🍚','雞肉飯':'🍚','炒飯':'🍛',
  '牛肉麵':'🍜','乾麵':'🍜','陽春麵':'🍜','餛飩麵':'🍜','炒麵':'🍝','乾拌麵':'🍜','水餃':'🥟',
  '鹽酥雞':'🍗','蚵仔煎':'🥘','臭豆腐':'🍲','大腸麵線':'🍜','胡椒餅':'🥟','蔥抓餅':'🥞','烤肉串':'🍢','刈包':'🥟','蘿蔔糕':'🧆','花枝羹':'🍲',
  '茶葉蛋':'🥚','鮪魚御飯糰':'🍙','梅子御飯糰':'🍙','雞胸肉沙拉':'🥗','無糖豆漿':'🥛','地瓜':'🍠','舒肥雞胸':'🍗','茶碗蒸':'🍮','水煮蛋':'🥚','希臘優格':'🥣','燕麥飲':'🥛','超商三明治':'🥪','雞肉捲':'🌯','黑咖啡':'☕',
  '蛋餅':'🥞','火腿蛋吐司':'🥪','鐵板麵':'🍜','饅頭夾蛋':'🥯','燒餅油條':'🥖','蛋吐司':'🍞','鮪魚蛋餅':'🥞','起司蛋餅':'🥞','豆漿':'🥛','熱狗麵包':'🌭','薯餅':'🍟',
  '大麥克':'🍔','麥香雞':'🍔','麥克雞塊 6 塊':'🍗','薯條中份':'🍟','肯德基原味蛋塔':'🥧','肯德基炸雞腿':'🍗','Subway 火雞肉':'🥪','Subway 燻雞':'🥪','漢堡王華堡':'🍔','摩斯米漢堡':'🍔',
  '燙青菜':'🥬','花椰菜':'🥦','高麗菜':'🥬','芭樂':'🍐','奇異果':'🥝','葡萄':'🍇','西瓜':'🍉','木瓜':'🥭','番茄':'🍅','小黃瓜':'🥒','生菜沙拉':'🥗','菠菜':'🥬','蘋果':'🍎','香蕉':'🍌',
  '雞胸肉':'🍗','鮭魚':'🐟','鯖魚':'🐟','豆腐':'🧈','蝦仁':'🦐','牛排':'🥩','豬排':'🍖','雞蛋':'🥚','毛豆':'🫛','雞翅':'🍗',
  '豆花':'🍮','紅豆湯':'🍲','鳳梨酥':'🥮','蛋糕一塊':'🍰','布丁':'🍮','冰淇淋 (一球)':'🍦','巧克力餅乾':'🍪','蛋塔':'🥧','芋圓':'🍡','麻糬':'🍡',
  '味噌湯':'🍜','玉米濃湯':'🥣','酸辣湯':'🥣','蛤蜊湯':'🥣','排骨湯':'🥣',
  '關東煮白蘿蔔':'🍢','關東煮魚豆腐':'🍢','關東煮高麗菜卷':'🍢','御便當日式豬排':'🍱',
};
// Food emoji -> SVG icon name; unknowns emit the raw emoji (graceful fallback)
// Offline fallback — server `food_icon_map` config takes precedence at runtime
const FOOD_EMOJI_TO_ICON = {
  '🧋': 'bubble_tea', '🍵': 'tea', '☕': 'coffee', '🥛': 'milk', '🥤': 'soda', '🥥': 'coconut_drink',
  '🍱': 'meal_box', '🍚': 'rice', '🍛': 'rice', '🍙': 'rice_ball',
  '🍜': 'noodle', '🍝': 'noodle', '🥟': 'dumpling',
  '🍗': 'chicken', '🐟': 'sushi', '🥘': 'hot_pot', '🍲': 'hot_pot', '🍢': 'hot_pot',
  '🥚': 'egg', '🍠': 'sweet_potato', '🍎': 'apple', '🍌': 'banana',
  '🥗': 'salad', '🥬': 'salad', '🥦': 'salad',
  '🥣': 'soup_bowl', '🥪': 'sandwich', '🌯': 'sandwich', '🍞': 'sandwich',
  '🍟': 'fries', '🍦': 'ice_cream', '🍰': 'cake', '🥮': 'cake', '🥧': 'cake',
  '🍷': 'wine', '🍺': 'wine',
  '💧': 'water',
};
function toFoodIconHtml(emoji, size = 32) {
  const name = (cfg('food_icon_map', FOOD_EMOJI_TO_ICON) || {})[emoji];
  if (name && window.icon) return window.icon(name, { size });
  return emoji; // graceful fallback for items we haven't drawn yet
}
function foodIconFor(d) {
  let e;
  if (FOOD_ICON[d.name_zh]) e = FOOD_ICON[d.name_zh];
  else {
    const cat = (d.category || '').toLowerCase();
    if (cat.includes('便當')) e = '🍱';
    else if (cat.includes('手搖')) e = '🧋';
    else if (cat.includes('夜市')) e = '🍢';
    else if (cat.includes('超商')) e = '🍙';
    else if (cat.includes('早餐')) e = '🥐';
    else if (cat.includes('連鎖速食')) e = '🍔';
    else if (cat.includes('蔬菜')) e = '🥬';
    else if (cat.includes('水果')) e = '🍎';
    else if (cat.includes('甜點')) e = '🍰';
    else if (cat.includes('湯品')) e = '🥣';
    else if (cat.includes('蛋白質')) e = '🍗';
    else if (cat.includes('飯食')) e = '🍚';
    else if (cat.includes('麵食')) e = '🍜';
    else e = elementEmoji(d.element);
  }
  return toFoodIconHtml(e, 40);
}
function elementEmoji(e) {
  return { protein: '🍖', carb: '🍚', veggie: '🥬', fat: '🥓', sweet: '🍬', drink: '🥤', neutral: '🍱' }[e] || '🍱';
}

async function loadPokedex() {
  const list = await api('GET', `/pokedex/${state.userId}`);
  const totalDb = 120; // 食物資料庫規模（seed）
  const shinyCount = list.filter((d) => d.is_shiny).length;
  $('#pokedex-total').textContent = `${list.length} / ${totalDb}`;
  $('#pokedex-shiny').textContent = shinyCount;

  // Tip card only when user has some items (explanatory hint above grid)
  const intro = list.length > 0
    ? `<div class="pokedex-intro">
        📖 收集 <b>${list.length}</b> 種食物囉～單餐 <b>90 分</b>以上會升級成閃光版 🌟
      </div>` : '';

  $('#pokedex-list').innerHTML = intro + (list.length ? list.map((d) => `
    <div class="poke-card ${d.is_shiny ? 'shiny' : ''}">
      <div class="text-2xl">${foodIconFor(d)}</div>
      <div class="poke-name">${d.name_zh}</div>
      <div class="poke-meta">x${d.times_eaten} · 最佳 ${d.best_score ?? '-'}</div>
    </div>
  `).join('') : `
    <div class="col-span-3 empty-state">
      <div class="es-emoji">📖</div>
      <div class="es-title">圖鑑還是空白</div>
      <div class="es-hint">記錄第一餐就會解鎖第一格 ✨</div>
    </div>`);
}

// === Suggest next meal ===
const MEAL_TYPE_LABEL = { breakfast: '早餐', lunch: '午餐', dinner: '晚餐', snack: '點心' };
async function loadSuggestions() {
  // Legacy passive-suggestion card was removed from the home page in favor of
  // the action-oriented smart-action + tiles. Kept this function as a no-op so
  // existing call sites (tab switch, refresh btn) don't break.
  if (!$('#suggest-card')) return;
  // (original code below still runs if suggest-card exists, e.g. for embeds/tests)
  const thinking = $('#suggest-thinking');
  const intro = $('#suggest-intro');
  const list = $('#suggest-list');
  const outro = $('#suggest-outro');
  const sig = $('#suggest-signature');
  if (thinking) thinking.classList.remove('hidden');
  if (intro) intro.textContent = '';
  if (list) list.innerHTML = '';
  if (outro) outro.textContent = '';
  if (sig) sig.textContent = '';
  const minDelay = new Promise((r) => setTimeout(r, 600));
  try {
    const [r] = await Promise.all([
      api('GET', `/suggest/next-meal/${state.userId}`),
      minDelay,
    ]);
    const k = r.knowledge;
    if (thinking) {
      $('#suggest-thinking-text').textContent = k?.thinking_phrase || '正在幫你想…';
      thinking.classList.add('hidden');
    }
    // Header
    $('#suggest-meal-label').textContent = k?.scenario_label || (MEAL_TYPE_LABEL[r.meal_type] + ' 建議');
    $('#suggest-emoji').textContent = k?.context_emoji || '🐾';
    $('#suggest-budget-line').textContent = k
      ? `今天還有 ${k.user_state.remaining_calories} 卡 · 這餐建議 ${Math.round(k.user_state.remaining_calories * 0.4)} 卡內`
      : (r.tip || '');
    // Intro / outro / signature
    if (intro) intro.textContent = k?.intro || r.tip || '';
    if (outro) outro.textContent = k?.outro || '';
    if (sig) sig.textContent = k?.signature || '';

    // Picks (knowledge format) — fall back to old suggestions shape if missing
    const picks = k?.picks || r.suggestions.map((s) => ({
      name: s.name, cal: s.calories, protein: s.protein_g, tag: s.brand || '', why: s.reason, cal_after: 0, too_much: false,
    }));
    list.innerHTML = picks.map((p) => `
      <button class="suggest-item ${p.too_much ? 'over' : ''}" data-name="${escapeHtml(p.name)}" data-meal-type="${r.meal_type}">
        ${p.tag ? `<div class="si-tag">${escapeHtml(p.tag)}</div>` : ''}
        <div class="si-name">${escapeHtml(p.name)}</div>
        <div class="si-meta">
          <span class="si-cal">${p.cal}<span class="si-unit"> 卡</span></span>
          <span class="si-prot">蛋白 ${p.protein}g</span>
        </div>
        <div class="si-after ${p.too_much ? 'over' : ''}">
          ${p.too_much
            ? `⚠️ 吃這個會超標 ${Math.abs(p.cal_after)} 卡`
            : `吃完還剩 <b>${p.cal_after}</b> 卡`}
        </div>
        <div class="si-why">${escapeHtml(p.why)}</div>
        ${p.warning ? `<div class="si-warning">⚠️ ${escapeHtml(p.warning)}</div>` : ''}
      </button>
    `).join('');
    $$('.suggest-item').forEach((el) => el.addEventListener('click', async () => {
      el.classList.add('is-loading');
      try {
        const result = await api('POST', '/meals/text', {
          user_id: state.userId,
          food_name: el.dataset.name,
          meal_type: el.dataset.mealType,
        });
        toast(`記錄完成 · ${result.meal.meal_score} 分 · +${result.xp_gained} XP`, { emoji: '✅' });
        const unlocks = collectUnlockRewards(result);
        for (const u of unlocks) enqueueReward(u);
        await loadDashboard();
        await loadSuggestions();
      } catch (e) {
        toast(e.message, { emoji: '⏱' });
      } finally {
        el.classList.remove('is-loading');
      }
    }));
  } catch (e) {
    if (thinking) thinking.classList.add('hidden');
  }
}

// === Report ===
async function loadWeekly() {
  const today = new Date().toISOString().slice(0, 10);
  const r = await api('GET', `/reports/weekly/${state.userId}/${today}`);

  // Hero
  $('#rh-dates').textContent = `${r.week_start.slice(5)} – ${r.week_end.slice(5)}`;
  $('#rh-avg').textContent = r.avg_score.toFixed(1);
  $('#rh-perfect').textContent = r.perfect_days;
  const wc = r.weight_change;
  $('#rh-weight').textContent = wc === 0 ? '持平' : `${wc > 0 ? '+' : ''}${wc} kg`;
  const trend = r.avg_score > r.prev_avg_score ? '↑ 比上週進步' : r.avg_score < r.prev_avg_score ? '↓ 比上週略降' : '— 與上週持平';
  $('#rh-trend').textContent = trend + `　紀錄 ${r.logged_days} 天 · 共 ${r.meals_total} 餐`;

  // 7-day chart
  const labels = ['一','二','三','四','五','六','日'];
  $('#report-chart').innerHTML = r.daily_scores.map((s, i) => {
    const h = s > 0 ? Math.max(8, (s / 100) * 100) : 0;
    const perfect = s >= 80;
    const empty = !r.daily_has_log[i];
    return `
      <div class="chart-col">
        <div class="chart-value">${s || '—'}</div>
        <div class="chart-bar-wrap">
          <div class="chart-bar ${perfect ? 'perfect' : ''} ${empty ? 'empty' : ''}" style="height: ${empty ? 8 : h}%;"></div>
        </div>
        <div class="chart-label">${labels[i]}</div>
      </div>
    `;
  }).join('');

  // Top foods
  if (r.top_foods.length === 0) {
    $('#report-top-foods').classList.add('hidden');
  } else {
    $('#report-top-foods').classList.remove('hidden');
    $('#report-foods-list').innerHTML = r.top_foods.map((f, i) => `
      <div class="food-row">
        <div class="food-row-num">${i + 1}</div>
        <div class="food-row-name">${escapeHtml(f.name)}</div>
        <div class="food-row-count">${f.count} 次</div>
      </div>
    `).join('');
  }

  // Unlocks
  const unlocks = r.new_achievements_this_week;
  if (unlocks.length === 0) {
    $('#report-unlocks-wrap').classList.add('hidden');
  } else {
    $('#report-unlocks-wrap').classList.remove('hidden');
    $('#report-unlocks').innerHTML = unlocks.map((a) => `
      <div class="flex items-center gap-3">
        <div class="ac-icon" style="width:36px;height:36px;font-size:20px;">${ACH_ICON[a.key] || '🏅'}</div>
        <div class="text-sm font-semibold">${escapeHtml(a.name)}</div>
      </div>
    `).join('');
  }

  // Letter
  $('#report-letter-body').textContent = r.letter;

  // Empty state
  if (!r.has_enough_data) {
    $('#report-empty').classList.remove('hidden');
    $('#report-letter-card').style.opacity = '0.6';
  } else {
    $('#report-empty').classList.add('hidden');
    $('#report-letter-card').style.opacity = '1';
  }
}

// === Chat starters ===
let chatStartersLoaded = false;
async function loadChatStarters() {
  if (chatStartersLoaded) return;
  chatStartersLoaded = true;
  try {
    const s = await api('GET', `/chat/starters/${state.userId}`);
    if ($('#chat-messages').children.length === 0) {
      appendBubble('bot', s.welcome);
    }
    renderStarters(s.starters);
    renderSeesYou(s.sees_you);
  } catch {}
}
function renderSeesYou(sy) {
  if (!sy || !sy.facts) return;
  const dismissed = localStorage.getItem('doudou_sees_dismissed');
  const panel = $('#sees-you-panel');
  if (dismissed) { panel.classList.add('hidden'); return; }
  panel.classList.remove('hidden');
  $('#sees-facts').innerHTML = sy.facts.map((f) => `<span class="sees-fact-chip">✓ ${escapeHtml(f)}</span>`).join('');
  $('#sees-diffs').innerHTML = sy.differences.map((d) => `<li>${escapeHtml(d)}</li>`).join('');
  panel.querySelector('.sees-close')?.addEventListener('click', () => {
    panel.classList.add('hidden');
    localStorage.setItem('doudou_sees_dismissed', '1');
  }, { once: true });
}
function renderStarters(list) {
  const wrap = $('#chat-starters');
  wrap.innerHTML = list.map((s) => `
    <button class="starter-chip" data-text="${escapeHtml(s.text)}"><span>${s.emoji}</span><span>${escapeHtml(s.text)}</span></button>
  `).join('');
  wrap.querySelectorAll('.starter-chip').forEach((el) => {
    el.addEventListener('click', async () => {
      const txt = el.dataset.text;
      $('#chat-input').value = txt;
      await sendChat();
      // After using a starter, hide them until next reload
      wrap.innerHTML = '';
    });
  });
}

// === Calendar ===
async function loadCalendar() {
  const data = await api('GET', `/calendar/${state.userId}?days=35`);
  $('#cal-streak').textContent = data.stats.current_streak;
  $('#cal-perfect').textContent = data.stats.perfect_days;
  $('#calendar-grid').innerHTML = data.cells.map((c) => {
    const day = Number(c.date.slice(-2));
    return `<div class="cal-cell cal-t${c.tier} ${c.is_today ? 'today' : ''}"
      title="${c.date}: ${c.meals_logged ? c.score + ' 分' : '未紀錄'}"
      data-day="${day}" data-date="${c.date}" data-score="${c.score}" data-meals="${c.meals_logged}" data-tier="${c.tier}"></div>`;
  }).join('');
  $$('#calendar-grid .cal-cell').forEach((el) => el.addEventListener('click', () => openCalDayModal(el.dataset)));
}

function openCalDayModal(d) {
  const tierTxt = ['未紀錄','一般','不錯','很棒','完美 ✨'][d.tier] || '';
  const scoreTxt = d.meals > 0 ? `<div class="cd-timer num-pop" style="font-size:36px;">${d.score}<span style="font-size:16px;"> 分</span></div>` : `<div class="cd-timer" style="font-size:20px;color:var(--muted);">這天沒有紀錄</div>`;
  const m = $('#countdown-modal');
  m.querySelector('.reward-content').innerHTML = `
    <div class="cd-icon">📅</div>
    <div class="cd-title">${d.date}</div>
    <div class="cd-sub">${tierTxt}</div>
    ${scoreTxt}
    <div class="text-sm text-muted mb-4">${d.meals > 0 ? `紀錄了 ${d.meals} 餐` : '加油～今天開始也可以'}</div>
    <button id="cal-close" class="btn-primary">關閉</button>
  `;
  m.classList.remove('hidden');
  void m.offsetWidth;
  m.classList.add('shown');
  m.querySelector('#cal-close').addEventListener('click', () => {
    m.classList.remove('shown');
    setTimeout(() => m.classList.add('hidden'), 300);
  }, { once: true });
}

// === Wardrobe ===
let wardrobeEquippedKey = 'none'; // track real equipped to revert preview
async function loadWardrobe() {
  const data = await api('GET', `/outfits/${state.userId}`);
  wardrobeEquippedKey = data.equipped;
  $('#wardrobe-list').innerHTML = data.outfits.map((o) => `
    <button class="wardrobe-item ${o.unlocked ? '' : 'locked'} ${o.equipped ? 'equipped' : ''}" data-key="${o.key}" data-unlocked="${o.unlocked ? '1' : '0'}">
      <div class="w-emoji">${o.emoji}</div>
      <div class="w-name">${o.name}</div>
      <div class="w-hint">${o.unlocked ? (o.equipped ? '使用中' : o.description) : o.unlock_hint}</div>
    </button>
  `).join('');
  $$('.wardrobe-item').forEach((el) => el.addEventListener('click', async () => {
    const isLocked = el.dataset.unlocked === '0';
    const key = el.dataset.key;
    if (isLocked) {
      // Preview only — don't equip, just re-render character with this outfit
      previewOutfit(key);
      toast(`試穿中 · ${el.querySelector('.w-name').textContent}（鎖住的）`, { emoji: '👀' });
      // highlight current preview card
      $$('.wardrobe-item').forEach((e) => e.classList.remove('previewing'));
      el.classList.add('previewing');
      return;
    }
    try {
      await api('POST', `/outfits/${state.userId}/equip`, { outfit_key: key });
      toast('換裝成功！', { emoji: '👗' });
      await loadWardrobe();
      await loadDashboard();
    } catch (e) { toast(e.message || '換裝失敗'); }
  }));
}
function previewOutfit(outfit) {
  // Re-render top character with this outfit without saving to backend
  const level = Number($('#m-level').textContent || '1');
  const animal = state.animal;
  const mood = state.lastAnimalMood || 'happy';
  $('#char-stage').innerHTML = renderCharacter({ animal, level, mood, outfit });
}

// === Achievements ===
const ACH_ICON = {
  first_meal:        '🍽️',
  streak_3:          '⚡',
  streak_7:          '🔥',
  streak_14:         '💎',
  streak_30:         '🌙',
  level_10:          '🌸',
  level_20:          '🌺',
  level_50:          '🏆',
  foodie_10:         '📖',
  foodie_50:         '🗺️',
  perfect_day:       '✨',
  perfect_week:      '💫',
  weight_goal_1kg:   '📉',
  weight_goal_5kg:   '🎯',
};
async function loadAchievements() {
  const data = await api('GET', `/achievements/${state.userId}/all`);
  $('#ach-done').textContent = data.unlocked;
  $('#ach-total').textContent = data.total;
  const el = $('#ach-list');
  el.classList.remove('space-y-2');
  el.classList.add('ach-grid');
  el.innerHTML = data.achievements.map((a) => {
    const icon = ACH_ICON[a.key] || (a.unlocked ? '🏅' : '🔒');
    return `
      <div class="ach-card ${a.unlocked ? 'unlocked' : 'locked'}">
        <div class="ac-icon">${icon}</div>
        <div class="ac-name">${a.name}</div>
        <div class="ac-desc">${a.description}</div>
      </div>`;
  }).join('');
}

// === Pet (tap character) ===
async function petCharacter(ev) {
  if (!state.userId) return;
  window.sfx?.play('pet');
  const rect = ev.currentTarget.getBoundingClientRect();
  const x = ev.clientX || (rect.left + rect.width / 2);
  const y = ev.clientY || (rect.top + rect.height / 2);
  spawnHeart(x, y);
  const wrap = $('#char-stage .char-wrap');
  if (wrap) { wrap.classList.remove('wiggle'); void wrap.offsetWidth; wrap.classList.add('wiggle'); }
  try {
    const r = await api('POST', '/interact/pet', { user_id: state.userId });
    $('#char-speech').textContent = r.message;
    if (!r.capped) {
      $('#m-friendship-num').textContent = r.friendship;
      $('#m-friendship-fill').style.width = Math.min(100, (r.friendship / 500) * 100) + '%';
    }
  } catch {}
}

// === Daily Pandora Gift ===
let giftCountdownTimer = null;
function fmtCountdown(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}
function startGiftCountdown(targetMs) {
  if (giftCountdownTimer) clearInterval(giftCountdownTimer);
  const btnCd = $('#gift-countdown');
  const modalCd = $('#cd-timer');
  const end = Date.now() + targetMs;
  const tick = () => {
    const remaining = end - Date.now();
    if (remaining <= 0) {
      clearInterval(giftCountdownTimer);
      refreshGiftState();
      return;
    }
    const txt = fmtCountdown(remaining);
    btnCd.textContent = txt;
    if (modalCd) modalCd.textContent = txt;
  };
  tick();
  giftCountdownTimer = setInterval(tick, 1000);
}
async function refreshGiftState() {
  try {
    const s = await api('GET', `/interact/gift/${state.userId}/status`);
    const btn = $('#gift-btn');
    const cd = $('#gift-countdown');
    if (s.can_open) {
      btn.classList.remove('claimed');
      cd.classList.add('hidden');
      if (giftCountdownTimer) clearInterval(giftCountdownTimer);
    } else {
      btn.classList.add('claimed');
      cd.classList.remove('hidden');
      startGiftCountdown(s.next_available_in_ms);
    }
  } catch {}
}

async function claimGift() {
  try {
    const r = await api('POST', '/interact/gift', { user_id: state.userId });
    if (r.claimed && r.reward) {
      openRewardModal(r.reward);
      startGiftCountdown(r.next_available_in_ms);
      $('#gift-btn').classList.add('claimed');
      $('#gift-countdown').classList.remove('hidden');
      await loadDashboard();
    } else {
      showCountdownModal(r.next_available_in_ms);
      startGiftCountdown(r.next_available_in_ms);
      $('#gift-btn').classList.add('claimed');
      $('#gift-countdown').classList.remove('hidden');
    }
  } catch (e) { toast('失敗：' + e.message); }
}

// Open reward reveal modal (also reusable for level-up / achievement / outfit unlock)
async function openRewardModal(reward) {
  const modal = $('#reward-modal');
  const lidL = $('#rw-lid-left');
  const lidR = $('#rw-lid-right');
  const rays = $('#rw-rays');
  const glow = $('#rw-glow');
  const reveal = $('#reward-reveal');
  const closeBtn = $('#reward-close');
  // reset
  lidL.classList.remove('opened'); lidR.classList.remove('opened');
  rays.classList.remove('shown'); glow.classList.remove('shown');
  reveal.classList.remove('shown'); closeBtn.classList.remove('shown');
  $('#rw-emoji').textContent = reward.emoji || '✨';
  $('#rw-title').textContent = reward.title || '驚喜';
  $('#rw-subtitle').textContent = reward.subtitle || '';
  modal.classList.remove('hidden');
  void modal.offsetWidth;
  modal.classList.add('shown');

  await wait(400);
  // Sound: box_open on lid opening; then themed sound on reveal based on reward type
  window.sfx?.play('box_open');
  lidL.classList.add('opened');
  lidR.classList.add('opened');
  await wait(350);
  glow.classList.add('shown');
  rays.classList.add('shown');
  spawnParticlesInto('#rw-particles', 24);
  await wait(300);
  reveal.classList.add('shown');
  // Themed reveal sound
  if (reward.type === 'level_up') window.sfx?.play('level_up');
  else if (reward.type === 'achievement') window.sfx?.play('achievement');
  else window.sfx?.play('xp');
  confetti();
  await wait(500);
  closeBtn.classList.add('shown');
}

function showCountdownModal(ms) {
  const m = $('#countdown-modal');
  $('#cd-timer').textContent = fmtCountdown(ms);
  m.classList.remove('hidden');
  void m.offsetWidth;
  m.classList.add('shown');
}

function spawnParticlesInto(selector, n = 20) {
  const root = document.querySelector(selector);
  if (!root) return;
  root.innerHTML = '';
  for (let i = 0; i < n; i++) {
    const el = document.createElement('div');
    el.className = 'particle';
    const angle = (Math.random() - 0.5) * Math.PI;
    const dist = 80 + Math.random() * 140;
    const midDist = dist * 0.4;
    el.style.setProperty('--px', Math.round(Math.sin(angle) * midDist) + 'px');
    el.style.setProperty('--py-mid', -Math.round(Math.abs(Math.cos(angle)) * midDist) + 'px');
    el.style.setProperty('--px-end', Math.round(Math.sin(angle) * dist) + 'px');
    el.style.setProperty('--py', (-Math.round(Math.abs(Math.cos(angle)) * dist + 40)) + 'px');
    el.style.animationDelay = (Math.random() * 0.8) + 's';
    el.style.animationDuration = (2.2 + Math.random() * 1.8) + 's';
    el.style.width = (3 + Math.random() * 4) + 'px';
    el.style.height = el.style.width;
    root.appendChild(el);
    setTimeout(() => el.remove(), 3500);
  }
}

// === Init ===
async function init() {
  paintWelcome();
  // Swap [data-icon] elements to custom SVG immediately on load (tab nav, paywall, etc.)
  if (window.icon) swapDataIcons();

  // species picker
  $$('#species-picker .sp-btn').forEach((b) => b.addEventListener('click', async () => {
    $$('#species-picker .sp-btn').forEach((e) => e.classList.remove('active'));
    b.classList.add('active');
    state.animal = b.dataset.animal;
    $('input[name=avatar_animal]').value = state.animal;
    $('#welcome-char').innerHTML = renderCharacter({ animal: state.animal, level: 1, mood: 'happy' });
    await paintSpiritLabel(state.animal);
  }));

  if (await tryResume()) { /* resumed */ }

  function setFieldError(name, msg) {
    const input = document.querySelector(`#reg-form [name="${name}"]`);
    if (!input) return;
    const field = input.closest('.field');
    const errEl = document.querySelector(`.field-err[data-for="${name}"]`);
    if (msg) { field?.classList.add('invalid'); if (errEl) errEl.textContent = msg; }
    else { field?.classList.remove('invalid'); if (errEl) errEl.textContent = ''; }
  }
  function validateRegForm(fd) {
    let firstBad = null;
    ['name', 'height_cm', 'current_weight_kg', 'target_weight_kg'].forEach((n) => setFieldError(n, ''));
    const name = (fd.name || '').trim();
    if (name.length < 2) { setFieldError('name', '名字至少 2 個字'); firstBad = firstBad || 'name'; }
    else if (name.length > 20) { setFieldError('name', '名字最多 20 個字'); firstBad = firstBad || 'name'; }
    const h = Number(fd.height_cm);
    if (!Number.isFinite(h)) { setFieldError('height_cm', '請輸入身高'); firstBad = firstBad || 'height_cm'; }
    else if (h < 100) { setFieldError('height_cm', '身高不能小於 100 公分'); firstBad = firstBad || 'height_cm'; }
    else if (h > 250) { setFieldError('height_cm', '身高不能大於 250 公分'); firstBad = firstBad || 'height_cm'; }
    const w = Number(fd.current_weight_kg);
    if (!Number.isFinite(w)) { setFieldError('current_weight_kg', '請輸入體重'); firstBad = firstBad || 'current_weight_kg'; }
    else if (w < 30) { setFieldError('current_weight_kg', '體重不能小於 30 公斤'); firstBad = firstBad || 'current_weight_kg'; }
    else if (w > 250) { setFieldError('current_weight_kg', '體重不能大於 250 公斤'); firstBad = firstBad || 'current_weight_kg'; }
    if (fd.target_weight_kg) {
      const t = Number(fd.target_weight_kg);
      if (t < 30 || t > 250) { setFieldError('target_weight_kg', '目標體重 30-250 公斤之間'); firstBad = firstBad || 'target_weight_kg'; }
      else if (t > w + 5) { setFieldError('target_weight_kg', '目標體重不能比現在多太多'); firstBad = firstBad || 'target_weight_kg'; }
    }
    return firstBad;
  }

  $('#reg-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = Object.fromEntries(new FormData(e.target));
    const bad = validateRegForm(fd);
    if (bad) {
      const el = document.querySelector(`#reg-form [name="${bad}"]`);
      el?.focus();
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    const payload = {
      name: fd.name.trim(),
      height_cm: Number(fd.height_cm),
      current_weight_kg: Number(fd.current_weight_kg),
      target_weight_kg: fd.target_weight_kg ? Number(fd.target_weight_kg) : undefined,
      activity_level: fd.activity_level,
      gender: fd.gender,
      avatar_animal: state.animal,
    };
    // Pull pending invite code (set when arriving via /?ref=XXXX)
    const pendingRef = sessionStorage.getItem('doudou.pendingRefCode');
    if (pendingRef) payload.referral_code = pendingRef;
    try {
      const r = await api('POST', '/auth/register', payload);
      sessionStorage.removeItem('doudou.pendingRefCode');
      if (r.referral_applied) {
        toast(`邀請碼成功！多送 7 天試用 🎁`, { emoji: '✨' });
      }
      await afterRegister(r);
    } catch (e) { toast(e.message || '註冊失敗', { emoji: '⚠️' }); }
  });
  // Live clear errors as user types
  $$('#reg-form input').forEach((inp) => {
    inp.addEventListener('input', () => setFieldError(inp.name, ''));
  });

  $$('.tab').forEach((b) => b.addEventListener('click', () => {
    if (!b.dataset.tab) return; // e.g. nav-cards opens a modal instead
    // 🏝️ Island tab intercepts to open the fullscreen overlay instead of a regular tab
    if (b.dataset.tab === 'island') { openIsland(); return; }
    switchTab(b.dataset.tab);
  }));
  $$('.me-entry').forEach((b) => b.addEventListener('click', () => switchTab(b.dataset.go)));

  // Character tap-to-pet
  $('#char-stage').addEventListener('click', petCharacter);

  // Gift
  $('#gift-btn').addEventListener('click', claimGift);
  $('#reward-close')?.addEventListener('click', () => {
    const m = $('#reward-modal');
    m.classList.remove('shown');
    setTimeout(() => m.classList.add('hidden'), 350);
  });
  $('#countdown-close')?.addEventListener('click', () => {
    const m = $('#countdown-modal');
    m.classList.remove('shown');
    setTimeout(() => m.classList.add('hidden'), 350);
  });

  // Camera
  $('#btn-start-cam').addEventListener('click', startCamera);
  $('#btn-capture').addEventListener('click', capturePhoto);
  $('#btn-retake').addEventListener('click', () => { resetCapture(); startCamera(); });
  $('#file-input').addEventListener('change', (e) => { const f = e.target.files?.[0]; if (f) handleFilePicked(f); });

  // Search
  let tmr;
  $('#food-search').addEventListener('input', (e) => { clearTimeout(tmr); tmr = setTimeout(() => searchFoods(e.target.value), 200); });

  // Log meal
  $('#btn-log-meal').addEventListener('click', logMeal);

  // Chat
  $('#chat-send').addEventListener('click', sendChat);
  const chatInput = $('#chat-input');
  let composing = false;
  chatInput.addEventListener('compositionstart', () => { composing = true; });
  chatInput.addEventListener('compositionend', () => { composing = false; });
  chatInput.addEventListener('keydown', (e) => {
    // IME 中文選字時 Enter 會有 isComposing=true 或 keyCode=229，不送出
    if (e.key !== 'Enter') return;
    if (composing || e.isComposing || e.keyCode === 229) return;
    e.preventDefault();
    sendChat();
  });

  // Refresh suggestions button
  $('#btn-refresh-suggest')?.addEventListener('click', loadSuggestions);

  window.quickWater = quickWater;
  window.quickExercise = quickExercise;
  window.promptWeight = promptWeight;

  // --- Card game wiring ---
  $('#nav-cards')?.addEventListener('click', () => openCardModal());
  // Tamagotchi care buttons + goal bar edit clicks
  document.querySelectorAll('.care-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const kind = btn.dataset.care;
      const amount = Number(btn.dataset.amount || 0);
      if (kind === 'water') { await quickWater(amount || 250); return; }
      if (kind === 'exercise') { await quickExercise(amount || 15); return; }
      if (kind === 'weight') { await promptWeight(); return; }
    });
  });
  // Tap the goal bar to edit (fix mistakes)
  $('#care-goal-water')?.addEventListener('click', () => {
    const cur = Number($('#care-stat-water')?.textContent || 0);
    editWaterTotal(cur);
  });
  $('#care-goal-exercise')?.addEventListener('click', () => {
    const cur = Number($('#care-stat-exercise')?.textContent || 0);
    editExerciseTotal(cur);
  });

  $('#smart-action')?.addEventListener('click', handleSmartAction);
  $('#home-tile-cards')?.addEventListener('click', () => openCardModal());
  $('#home-tile-island')?.addEventListener('click', () => openIsland());
  $('#home-tile-scan')?.addEventListener('click', () => switchTab('scan'));
  $('#event-banner')?.addEventListener('click', () => openEventCard());
  $('#event-banner-close')?.addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!eventBannerState.offer) return;
    const ok = await UI.confirm('確定跳過這次事件嗎？今天不會再出現', {
      title: '跳過事件', okText: '跳過', cancelText: '再想想',
    });
    if (!ok) return;
    try {
      await api('POST', '/cards/event-skip', {
        user_id: state.userId, offer_id: eventBannerState.offer.id,
      });
      $('#event-banner-wrap').classList.add('hidden');
      if (eventBannerState.countdownTimer) {
        clearInterval(eventBannerState.countdownTimer);
        eventBannerState.countdownTimer = null;
      }
      eventBannerState.offer = null;
      toast('已跳過這次事件');
    } catch (e) { /* ignore */ }
  });
  $('#card-modal-close')?.addEventListener('click', closeCardModal);
  $('#card-nostamina-close')?.addEventListener('click', closeCardModal);
  $('#card-reveal-close')?.addEventListener('click', closeCardModal);
  $('#card-reveal-go-codex')?.addEventListener('click', (e) => {
    e.preventDefault();
    closeCardModal();
    switchTab('cards-codex');
  });
  $('#card-draw-again')?.addEventListener('click', () => startCardDraw());
  $$('#card-modal .choice-card').forEach((btn) => btn.addEventListener('click', (e) => {
    const idx = Number(e.currentTarget.dataset.idx);
    selectChoice(idx);
  }));
  refreshCardStaminaDot();

  // Choice card hover tick (desktop) + taps = ui_tap
  $$('#card-modal .choice-card').forEach((btn) => {
    btn.addEventListener('mouseenter', () => {
      if (btn.disabled) return;
      window.sfx?.play('choice_hover');
    });
  });

  // Sound toggles
  $('#sfx-toggle-btn')?.addEventListener('click', toggleSfx);
  $('#sfx-global-toggle')?.addEventListener('click', toggleSfx);
  syncSfxToggleUI();
  // Show floating toggle once user has interacted with the app (avoid noise on first load)
  setTimeout(() => $('#sfx-global-toggle')?.classList.add('shown'), 2500);

  // Universal click feedback — every button / clickable gets a subtle pop.
  // Exclusions: elements inside modals with their own themed sounds, mute
  // toggles themselves, and disabled controls.
  document.addEventListener('click', (e) => {
    const t = e.target.closest(
      'button, [role="button"], a, label.cursor-pointer, .sp-btn, .me-entry, .qa-btn, .tab, .codex-filter, .hc-row, .codex-card, .food-result'
    );
    if (!t) return;
    if (t.disabled || t.getAttribute('aria-disabled') === 'true') return;
    // Skip elements that already play themed sounds.
    if (t.closest('#card-modal')) return;       // card modal has its own palette
    if (t.closest('#card-review-modal')) return; // review modal: card_flip + ui_close
    if (t.id === 'sfx-toggle-btn') return;       // the toggle plays its own confirm
    if (t.id === 'sfx-global-toggle') return;
    if (t.classList.contains('choice-card')) return;
    // Anchors without href and labels that only wrap inputs shouldn't beep twice.
    if (t.tagName === 'A' && !t.getAttribute('href')) return;
    window.sfx?.play('ui_tap');
  }, true);

  // Codex filters + close
  $$('.codex-filter').forEach((b) => b.addEventListener('click', () => {
    $$('.codex-filter').forEach((x) => x.classList.toggle('active', x === b));
    codexState.filter = b.dataset.filter;
    renderCodex();
  }));
  $('#card-review-close')?.addEventListener('click', closeCardReview);
  $('#card-review-modal')?.querySelector('.card-review-backdrop')?.addEventListener('click', closeCardReview);

  // Island fullscreen overlay
  $('#island-close')?.addEventListener('click', closeIsland);
  $('#store-back')?.addEventListener('click', backToIslandMap);
  $('#store-recs-back')?.addEventListener('click', backToIntents);

  // Paywall modal
  $('#paywall-close')?.addEventListener('click', closePaywall);
  $('#paywall-go-fp')?.addEventListener('click', () => {
    const url = islandState.entitlements?.fp_web_signup_url || 'https://pandora.js-store.com.tw/join';
    window.open(url, '_blank');
  });
  $('#paywall-sub-monthly')?.addEventListener('click', () => handleSubscribe('app_monthly'));
  $('#paywall-sub-yearly')?.addEventListener('click', () => handleSubscribe('app_yearly'));
  $('#paywall-code-btn')?.addEventListener('click', handlePaywallRedeem);

  // Tier redeem
  $('#tier-redeem-btn')?.addEventListener('click', redeemTierCode);

  // Re-open the disclaimer modal from the Me tab footer
  $('#me-show-disclaimer')?.addEventListener('click', () => { showDisclaimerModal().catch(() => {}); });

  // Me-tab actions: referral / upgrade / delete account
  $('#me-referral')?.addEventListener('click', () => openReferralPanel());
  $('#me-upgrade')?.addEventListener('click',  () => openPaywall('manual_open'));
  $('#me-delete-account')?.addEventListener('click', () => requestAccountDeletion());

  // Capture invite code from URL (?ref=XXXX) on first load and stash for register
  try {
    const refFromUrl = new URLSearchParams(location.search).get('ref');
    if (refFromUrl) sessionStorage.setItem('doudou.pendingRefCode', refFromUrl);
  } catch {}

  // Water target editor (tab-me)
  const waterInput = $('#settings-water-target');
  if (waterInput) waterInput.value = String(getWaterTarget());
  $('#settings-water-save')?.addEventListener('click', () => {
    const v = setWaterTarget($('#settings-water-target')?.value);
    $('#settings-water-target').value = String(v);
    const wTEl = $('#care-target-water'); if (wTEl) wTEl.textContent = v;
    toast(`喝水目標更新為 ${v} ml`, { emoji: '💧' });
    loadDashboard();
  });

  // Demo persona quick-register (only shows if ?demo=1 in URL)
  if (new URLSearchParams(location.search).has('demo')) {
    const dev = $('#dev-personas');
    if (dev) dev.style.display = 'block';
    $('#demo-public')?.addEventListener('click', (e) => { e.preventDefault(); demoRegister('public'); });
    $('#demo-retail')?.addEventListener('click', (e) => { e.preventDefault(); demoRegister('retail'); });
    $('#demo-franchise')?.addEventListener('click', (e) => { e.preventDefault(); demoRegister('franchise'); });
  }
}

async function demoRegister(persona) {
  localStorage.removeItem('doudou_user');
  localStorage.removeItem('doudou_token');
  const body = {
    name: persona === 'franchise' ? '加盟測試 Emma' : persona === 'retail' ? '零售測試 Amy' : '公開測試 Zoe',
    height_cm: 162,
    current_weight_kg: 60,
    target_weight_kg: 55,
    gender: 'female',
    activity_level: 'light',
    avatar_animal: persona === 'franchise' ? 'penguin' : persona === 'retail' ? 'rabbit' : 'cat',
    avatar_color: 'peach',
  };
  if (persona === 'retail') body.fp_ref_code = 'JR-DEMO-' + Date.now().toString(36).toUpperCase();
  if (persona === 'franchise') body.fp_ref_code = 'FP-DEMO-' + Date.now().toString(36).toUpperCase();
  try {
    const r = await api('POST', '/auth/register', body);
    state.userId = r.user.id;
    state.token = r.user.api_token;
    state.animal = r.user.avatar_animal;
    localStorage.setItem('doudou_user', state.userId);
    localStorage.setItem('doudou_token', state.token);
    localStorage.setItem('doudou_animal', state.animal);
    location.search = '?demo=1';
  } catch (e) {
    UI.alert('Demo register failed: ' + e.message);
  }
}

// =========================================================
// Card game — draw, reveal, select, answer animations
// =========================================================

const cardState = {
  playId: null,
  card: null,
  locked: false,
};

async function refreshCardStaminaDot() {
  if (!state.userId) return;
  try {
    const s = await api('GET', `/cards/stamina/${state.userId}`);
    const dot = $('#nav-cards-dot');
    if (dot) dot.classList.toggle('hidden', !(s.remaining > 0));
  } catch (e) { /* ignore */ }
}

const eventBannerState = { offer: null, card: null, countdownTimer: null };

async function refreshEventBanner() {
  if (!state.userId) return;
  try {
    const r = await api('GET', `/cards/event-offer/${state.userId}`);
    const wrap = $('#event-banner-wrap');
    if (!wrap) return;
    if (r.has_offer && r.card) {
      eventBannerState.offer = r.offer;
      eventBannerState.card = r.card;
      wrap.classList.remove('hidden');
      $('#event-banner-title').textContent = r.card.question;
      startEventCountdown(r.offer.expires_at);
    } else {
      eventBannerState.offer = null;
      eventBannerState.card = null;
      wrap.classList.add('hidden');
      if (eventBannerState.countdownTimer) {
        clearInterval(eventBannerState.countdownTimer);
        eventBannerState.countdownTimer = null;
      }
    }
  } catch (e) { /* silent */ }
}

function startEventCountdown(expiresAt) {
  const el = $('#event-banner-ttl');
  if (!el) return;
  if (eventBannerState.countdownTimer) clearInterval(eventBannerState.countdownTimer);
  const end = Date.parse(expiresAt);
  const tick = () => {
    const ms = end - Date.now();
    if (ms <= 0) {
      el.textContent = '已過期';
      clearInterval(eventBannerState.countdownTimer);
      eventBannerState.countdownTimer = null;
      $('#event-banner-wrap').classList.add('hidden');
      return;
    }
    const mins = Math.floor(ms / 60000);
    const secs = Math.floor((ms % 60000) / 1000);
    el.textContent = mins >= 60
      ? `${Math.floor(mins / 60)}h ${mins % 60}m`
      : `${mins}m ${String(secs).padStart(2, '0')}s`;
  };
  tick();
  eventBannerState.countdownTimer = setInterval(tick, 1000);
}

async function openEventCard() {
  if (!eventBannerState.offer) return;
  const offerId = eventBannerState.offer.id;
  if (!state.userId) return;
  window.sfx?.play('ui_open');
  const modal = $('#card-modal');
  modal.classList.remove('hidden');
  $('#card-reveal').classList.add('hidden');
  $('#card-no-stamina').classList.add('hidden');
  $('#card-stage').classList.remove('hidden');
  $('#card-loading').classList.remove('hidden');
  resetCardStage();
  cardState.locked = true;
  let data;
  try {
    data = await api('POST', '/cards/event-draw', { user_id: state.userId, offer_id: offerId });
  } catch (e) {
    toast(e.message || '事件載入失敗');
    closeCardModal();
    return;
  }
  cardState.playId = data.play_id;
  cardState.card = data;
  updateStaminaDisplay(data.stamina);

  const front = $('#card-front');
  front.classList.remove('rarity-common', 'rarity-rare', 'rarity-legendary');
  front.classList.add(`rarity-${data.rarity}`, 'is-event');
  $('#card-emoji').textContent = data.emoji || '⚡';
  $('#card-category').textContent = '⚡ 突發事件';
  $('#card-question').textContent = data.question;
  $('#card-hint').textContent = data.hint || '';
  $('#card-rarity-badge').textContent = rarityLabel(data.rarity);
  $('#card-new-badge').classList.remove('hidden');
  $('#card-new-badge').textContent = 'EVENT ⚡';

  const choiceBtns = $$('#choice-fan .choice-card');
  choiceBtns.forEach((btn, i) => {
    const ch = data.choices[i];
    btn.querySelector('.choice-text').textContent = ch ? ch.text : '';
    btn.style.display = ch ? '' : 'none';
  });

  $('#card-loading').classList.add('hidden');
  const flipper = $('#card-flipper');
  flipper.classList.add('entering');
  window.sfx?.play('card_draw');
  setTimeout(() => window.sfx?.play('card_flip'), 470);
  if (data.rarity === 'legendary') setTimeout(() => window.sfx?.play('legendary'), 800);

  setTimeout(() => {
    $('#choice-fan').classList.add('opened');
    cardState.locked = false;
  }, 900);
}

async function refreshHomeCardsCta() {
  if (!state.userId) return;
  try {
    const [stamina, ent] = await Promise.all([
      api('GET', `/cards/stamina/${state.userId}`),
      api('GET', `/entitlements/${state.userId}`).catch(() => null),
    ]);
    // Card tile badge = remaining stamina
    const cardsBadge = $('#home-tile-cards-badge');
    if (cardsBadge) {
      cardsBadge.textContent = stamina.remaining;
      cardsBadge.classList.toggle('empty', stamina.remaining <= 0);
    }
    // Island tile badge = visits remaining (or ∞ for unlimited)
    const islandBadge = $('#home-tile-island-badge');
    if (islandBadge && ent) {
      if (ent.unlimited_island) {
        islandBadge.textContent = '∞';
        islandBadge.classList.add('gold');
        islandBadge.classList.remove('empty');
      } else {
        islandBadge.textContent = String(ent.island_quota_remaining);
        islandBadge.classList.toggle('empty', ent.island_quota_remaining <= 0);
        islandBadge.classList.remove('gold');
      }
    }
    // Sync nav dot
    const dot = $('#nav-cards-dot');
    if (dot) dot.classList.toggle('hidden', !(stamina.remaining > 0));
    // Paint smart action
    paintSmartAction(stamina, ent);
  } catch (e) { /* ignore */ }
}

// Context-aware "what should I do next?" action card.
function paintSmartAction(stamina, ent) {
  const titleEl = $('#smart-action-title');
  const subEl = $('#smart-action-sub');
  const emojiEl = $('#smart-action-emoji');
  const btn = $('#smart-action');
  if (!titleEl || !btn) return;

  // Pull today's meal context from the currently-rendered dashboard DOM
  const mealsList = document.getElementById('m-meals-list');
  const mealCount = mealsList ? Number(mealsList.dataset.count || 0) : 0;
  const remaining = Number(($('#m-remaining')?.textContent || '0').replace(/[^\d-]/g, '')) || 0;
  const target = Number(($('#m-target')?.textContent || '0').replace(/[^\d]/g, '')) || 1800;
  const used = Math.max(0, target - remaining);
  const pct = target > 0 ? (used / target) * 100 : 0;

  const hour = new Date().getHours();
  const userName = mascotName();

  let emoji = '✨', title = '', sub = '', action = 'cards';

  // Time + state logic
  if (hour >= 5 && hour < 10 && mealCount === 0) {
    emoji = '☀️';
    title = `早安！還沒記早餐，先去超商挑一份`;
    sub = '點我 → 全家 / 7-11 的早餐推薦';
    action = 'island:seven_eleven';
  } else if (hour >= 11 && hour < 14 && mealCount < 2) {
    emoji = '🍱';
    title = `中午了，剩 ${remaining} 卡找午餐`;
    sub = '到島嶼挑組合 · 或直接記錄一餐';
    action = 'island';
  } else if (hour >= 14 && hour < 17 && stamina?.remaining > 0) {
    emoji = '🎴';
    title = `下午稍微動腦，抽張知識卡`;
    sub = `剩 ${stamina.remaining} 張免費卡，答對領 XP`;
    action = 'cards';
  } else if (hour >= 17 && hour < 21 && mealCount < 3) {
    emoji = '🌆';
    title = `晚餐時間，剩 ${remaining} 卡可規劃`;
    sub = '島嶼有晚餐建議，或直接記錄';
    action = 'island';
  } else if (hour >= 21 || hour < 5) {
    if (mealCount === 0) {
      emoji = '🌙';
      title = '還沒記任何餐？補一下保住連續紀錄';
      sub = '點我記錄一餐';
      action = 'scan';
    } else {
      emoji = '🌙';
      title = '今天辛苦了，看看今日成績';
      sub = `已記 ${mealCount} 餐 · 熱量使用 ${Math.round(pct)}%`;
      action = 'report';
    }
  } else if (remaining < 200 && mealCount >= 3) {
    emoji = '✅';
    title = '今天熱量控制得很棒！';
    sub = '看看你的打卡日曆進度 📅';
    action = 'calendar';
  } else if (stamina?.remaining > 0) {
    emoji = '🎴';
    title = `抽張今日知識卡？`;
    sub = `還有 ${stamina.remaining} 張免費卡`;
    action = 'cards';
  } else {
    emoji = '🏝️';
    title = '到島嶼走走看看？';
    sub = '有真實店家推薦，看你今天想買什麼';
    action = 'island';
  }

  // Map context emoji → custom SVG icon
  const smartIconMap = {
    '☀️': 'sun', '🌆': 'sunset', '🌙': 'moon_sleep',
    '🍱': 'meal_box', '🎴': 'card', '✅': 'check', '🏝️': 'island',
  };
  const iconName = smartIconMap[emoji];
  if (iconName && window.icon) {
    emojiEl.innerHTML = window.icon(iconName, { size: 44, cls: 'pulse' });
  } else {
    emojiEl.textContent = emoji;
  }
  titleEl.textContent = title;
  subEl.textContent = sub;
  btn.dataset.action = action;
}

function handleSmartAction() {
  const action = $('#smart-action')?.dataset.action || 'cards';
  if (action === 'cards') { openCardModal(); return; }
  if (action === 'island') { openIsland(); return; }
  if (action.startsWith('island:')) {
    // Open island and auto-enter specific store
    openIsland();
    // Let island load, then auto-click the store
    const storeKey = action.split(':')[1];
    setTimeout(() => {
      const scene = islandState.data?.scenes.find((s) => s.key === storeKey);
      if (scene) enterStore(scene);
    }, 500);
    return;
  }
  if (action === 'scan') { switchTab('scan'); return; }
  if (action === 'report') { switchTab('report'); return; }
  if (action === 'calendar') { switchTab('calendar'); return; }
}

async function openCardModal() {
  if (!state.userId) { toast('請先登入再抽卡喔～'); return; }
  window.sfx?.play('ui_open');
  const modal = $('#card-modal');
  modal.classList.remove('hidden');
  // reset panes
  $('#card-reveal').classList.add('hidden');
  $('#card-no-stamina').classList.add('hidden');
  $('#card-stage').classList.remove('hidden');
  $('#card-loading').classList.remove('hidden');
  await startCardDraw();
}

function closeCardModal() {
  window.sfx?.play('ui_close');
  const modal = $('#card-modal');
  modal.classList.add('hidden');
  cardState.playId = null;
  cardState.card = null;
  cardState.locked = false;
  resetCardStage();
  refreshCardStaminaDot();
  // refresh dashboard to reflect XP
  loadDashboard();
}

function resetCardStage() {
  $('.card-modal-content')?.classList.remove('revealing');
  $('#card-front')?.classList.remove('is-event');
  $('#card-new-badge').textContent = 'NEW ✨';
  const fan = $('#choice-fan');
  fan.classList.remove('opened');
  $$('#choice-fan .choice-card').forEach((c) => {
    c.classList.remove('selected', 'correct', 'wrong', 'faded');
    c.disabled = false;
  });
  const flipper = $('#card-flipper');
  flipper.classList.remove('entering');
  flipper.style.transform = '';
  $('#card-burst').innerHTML = '';
  $('#card-combo').classList.remove('show');
  $('#card-new-badge').classList.add('hidden');
}

async function startCardDraw() {
  resetCardStage();
  $('#card-reveal').classList.add('hidden');
  $('#card-stage').classList.remove('hidden');
  $('#card-loading').classList.remove('hidden');
  $('#card-no-stamina').classList.add('hidden');
  cardState.locked = true;

  let data;
  try {
    data = await api('POST', '/cards/draw', { user_id: state.userId });
  } catch (e) {
    const msg = String(e.message || '');
    $('#card-loading').classList.add('hidden');
    if (/NO_STAMINA|體力|今天的卡抽完/.test(msg)) {
      showNoStamina();
    } else if (/NO_CARDS_AVAILABLE|今天的新卡/.test(msg)) {
      $('#card-no-stamina').classList.remove('hidden');
      $('#card-no-stamina .text-lg').textContent = '今天的卡都抽過了 🎴';
    } else {
      toast(msg || '抽卡失敗');
      closeCardModal();
    }
    cardState.locked = false;
    return;
  }

  cardState.playId = data.play_id;
  cardState.card = data;
  updateStaminaDisplay(data.stamina);

  // paint front
  const front = $('#card-front');
  front.classList.remove('rarity-common', 'rarity-rare', 'rarity-legendary');
  front.classList.add(`rarity-${data.rarity}`);
  $('#card-emoji').textContent = data.emoji || '🎴';
  $('#card-category').textContent = categoryLabel(data.category);
  $('#card-question').textContent = data.question;
  $('#card-hint').textContent = data.hint || '';
  $('#card-rarity-badge').textContent = rarityLabel(data.rarity);
  $('#card-new-badge').classList.toggle('hidden', !data.is_new);

  // paint choices
  const choiceBtns = $$('#choice-fan .choice-card');
  choiceBtns.forEach((btn, i) => {
    const ch = data.choices[i];
    btn.querySelector('.choice-text').textContent = ch ? ch.text : '';
    btn.style.display = ch ? '' : 'none';
  });

  $('#card-loading').classList.add('hidden');

  // play card flight + flip
  const flipper = $('#card-flipper');
  flipper.classList.add('entering');
  window.sfx?.play('card_draw');
  // The flip happens ~55% into the 850ms fly-in
  setTimeout(() => window.sfx?.play('card_flip'), 470);
  // Legendary card gets an extra kira-kira
  if (data.rarity === 'legendary') {
    setTimeout(() => window.sfx?.play('legendary'), 800);
  }

  // after flight+flip, open fan
  setTimeout(() => {
    $('#choice-fan').classList.add('opened');
    cardState.locked = false;
  }, 900);
}

function updateStaminaDisplay(stamina) {
  if (!stamina) return;
  $('#card-stamina-text').textContent = `${stamina.remaining} / ${stamina.max}`;
}

function showNoStamina() {
  $('#card-stage').classList.add('hidden');
  $('#card-no-stamina').classList.remove('hidden');
  // set reset time
  try {
    api('GET', `/cards/stamina/${state.userId}`).then((s) => {
      const d = new Date(s.resets_at);
      const h = String(d.getHours()).padStart(2, '0');
      const m = String(d.getMinutes()).padStart(2, '0');
      $('#card-reset-time').textContent = `${h}:${m}`;
      updateStaminaDisplay(s);
    });
  } catch (e) { /* ignore */ }
}

async function selectChoice(idx) {
  if (cardState.locked || !cardState.playId) return;
  cardState.locked = true;
  window.sfx?.play('choice_select');

  const btns = $$('#choice-fan .choice-card');
  btns.forEach((b, i) => {
    b.disabled = true;
    if (i === idx) b.classList.add('selected');
    else b.classList.add('faded');
  });

  let result;
  try {
    result = await api('POST', '/cards/answer', {
      user_id: state.userId,
      play_id: cardState.playId,
      choice_idx: idx,
    });
  } catch (e) {
    toast(e.message || '答題失敗');
    cardState.locked = false;
    return;
  }

  // Animate answer feedback on chosen card
  const chosen = btns[idx];
  if (result.card_type === 'knowledge') {
    if (result.correct) {
      chosen.classList.add('correct');
      spawnBurst(18);
      window.sfx?.play('correct');
      if (navigator.vibrate) navigator.vibrate([30, 40, 30]);
    } else {
      chosen.classList.add('wrong');
      window.sfx?.play('wrong');
      if (result.reveal_correct_idx != null && btns[result.reveal_correct_idx]) {
        setTimeout(() => {
          btns[result.reveal_correct_idx].classList.remove('faded');
          btns[result.reveal_correct_idx].classList.add('correct');
          window.sfx?.play('choice_hover');
        }, 400);
      }
      if (navigator.vibrate) navigator.vibrate(60);
    }
  } else {
    // scenario: neutral glow on chosen
    chosen.classList.add('correct');
    spawnBurst(12);
    window.sfx?.play('xp');
  }

  // Combo overlay
  if (result.combo_bonus_triggered && result.combo_count >= 3) {
    $('#card-combo-num').textContent = `×${result.combo_count}`;
    $('#card-combo').classList.add('show');
    setTimeout(() => window.sfx?.play('combo'), 150);
    setTimeout(() => $('#card-combo').classList.remove('show'), 1700);
  }

  updateStaminaDisplay(result.stamina);

  // Show reveal panel after a short delay
  setTimeout(() => showReveal(result), result.correct === false ? 1400 : 1100);
}

function showReveal(result) {
  const panel = $('#card-reveal');
  $('.card-modal-content')?.classList.add('revealing');
  const resultEl = $('#card-reveal-result');
  resultEl.classList.remove('correct', 'wrong', 'neutral');
  if (result.card_type === 'knowledge') {
    if (result.correct) {
      resultEl.textContent = result.first_solve ? '首次答對！✨' : '答對了！🎯';
      resultEl.classList.add('correct');
    } else {
      resultEl.textContent = '差一點！';
      resultEl.classList.add('wrong');
    }
  } else {
    resultEl.textContent = '你的選擇 💭';
    resultEl.classList.add('neutral');
  }

  $('#card-reveal-feedback').textContent = result.feedback || '';
  $('#card-reveal-explain').textContent = result.explain || '';
  const xpLine = `+${result.xp_gained} XP`;
  const xpNum = $('#xp-burst-num');
  xpNum.textContent = xpLine;
  // retrigger xp pop animation
  xpNum.style.animation = 'none'; void xpNum.offsetWidth;
  xpNum.style.animation = '';

  const breakdown = (result.xp_breakdown || []).map((b) => `${b.label} +${b.amount}`).join('　·　');
  $('#xp-breakdown').textContent = breakdown;

  panel.classList.remove('hidden');
  // XP ding slightly after reveal appears
  setTimeout(() => window.sfx?.play('xp'), 220);

  // Level-up / achievements → chain into existing reward queue after user clicks 收下
  if (result.leveled_up) {
    setTimeout(() => window.sfx?.play('level_up'), 700);
    enqueueReward({ type: 'level_up', level: result.level_after, title: `LV.${result.level_after} 升級了！`, subtitle: '達到新階段 ✨', emoji: '🎉' });
  }
  for (const a of (result.new_achievements || [])) {
    setTimeout(() => window.sfx?.play('achievement'), 900);
    enqueueReward({ type: 'achievement', title: `新成就：${a.name}`, subtitle: a.description, emoji: '🏅' });
  }

  // Disable "再來一張" if no stamina left
  const again = $('#card-draw-again');
  if (result.stamina && result.stamina.remaining <= 0) {
    again.textContent = '體力用完了';
    again.disabled = true;
    again.style.opacity = .5;
  } else {
    again.textContent = `再來一張 🎴 (剩 ${result.stamina?.remaining ?? '?'})`;
    again.disabled = false;
    again.style.opacity = 1;
  }
}

function spawnBurst(n) {
  const burst = $('#card-burst');
  burst.innerHTML = '';
  const colors = ['#FFD56E', '#FFE8A0', '#F9C4B0', '#E89383', '#B8C9A0'];
  for (let i = 0; i < n; i++) {
    const p = document.createElement('div');
    p.className = 'burst-particle';
    const angle = (Math.PI * 2 * i) / n + Math.random() * 0.3;
    const dist = 120 + Math.random() * 80;
    p.style.setProperty('--bx', `${Math.cos(angle) * dist}px`);
    p.style.setProperty('--by', `${Math.sin(angle) * dist}px`);
    p.style.background = colors[i % colors.length];
    p.style.animationDelay = `${Math.random() * 0.15}s`;
    burst.appendChild(p);
  }
  setTimeout(() => burst.innerHTML = '', 1200);
}

function rarityLabel(r) {
  return r === 'legendary' ? '傳說' : r === 'rare' ? '稀有' : '普通';
}

// Derive a food emoji from an item name (for rec cards visuals).
// Falls back to 🍽️ if no keyword matches.
function emojiForItem(name) {
  const s = String(name || '');
  const map = [
    ['茶葉蛋|雞蛋|水煮蛋|蛋 ', '🥚'], ['滿福堡|漢堡', '🍔'],
    ['豆漿|鮮奶|牛奶|優酪|拿鐵', '🥛'], ['優格', '🍶'],
    ['地瓜|番薯', '🍠'], ['香蕉', '🍌'], ['莓果|蔓越莓', '🫐'],
    ['蘋果', '🍎'], ['芭樂', '🥭'],
    ['雞胸|雞腿|雞肉|舒肥|炸雞', '🍗'], ['雞排', '🍗'],
    ['牛肉|牛排', '🥩'], ['鮭魚|鯖魚|生魚片|握壽司|壽司', '🍣'],
    ['沙拉|生菜|菜', '🥗'], ['青花菜|花椰菜', '🥦'],
    ['飯糰|御飯糰|白飯|糙米|米飯', '🍙'], ['便當|餐盒', '🍱'],
    ['關東煮|煮物|滷味', '🍢'], ['豆腐|味噌|茶碗蒸', '🍥'],
    ['湯', '🥣'], ['咖啡|美式', '☕'], ['茶', '🍵'],
    ['手搖|飲料|珍奶|奶茶|綠茶|紅茶', '🧋'], ['可樂|汽水', '🥤'],
    ['薯條|炸物|天婦羅', '🍟'], ['蛋塔', '🥧'],
    ['霜淇淋|冰淇淋|冰|雪花', '🍦'], ['布丁|蛋糕|甜點|司康', '🍰'],
    ['三明治|吐司|麵包|司康', '🥪'],
    ['餃子|餛飩', '🥟'], ['麵|拉麵|烏龍', '🍜'],
    ['火鍋|麻辣', '🍲'], ['蚵仔煎', '🍳'],
    ['堅果|核桃', '🥜'], ['酪梨', '🥑'], ['燕麥', '🌾'],
    ['毛豆|豆子', '🌿'], ['水', '💧'],
    ['纖飄|纖纖|爆纖|水光|植萃|厚焙', '💊'],
  ];
  let found = '🍽️';
  for (const [pattern, emoji] of map) {
    if (new RegExp(pattern).test(s)) { found = emoji; break; }
  }
  return toFoodIconHtml(found, 32);
}

function toggleSfx() {
  if (!window.sfx) return;
  const nowMuted = window.sfx.toggle();
  syncSfxToggleUI();
  if (!nowMuted) {
    // Play a short confirm sound so user hears it's on
    window.sfx.play('ui_tap');
    toast('音效開啟 🔊');
  } else {
    toast('音效關閉 🔇');
  }
}

function syncSfxToggleUI() {
  const muted = window.sfx?.isMuted?.() ?? false;
  const iconOn = '🔊';
  const iconOff = '🔇';
  const a = $('#sfx-toggle-btn');
  const b = $('#sfx-global-toggle');
  if (a) { a.textContent = muted ? iconOff : iconOn; a.classList.toggle('muted', muted); }
  if (b) { b.textContent = muted ? iconOff : iconOn; b.classList.toggle('muted', muted); }
}

// =========================================================
// Cards Codex (圖鑑)
// =========================================================

const codexState = { data: null, filter: 'all' };

async function loadCardsCodex() {
  if (!state.userId) return;
  try {
    const data = await api('GET', `/cards/collection/${state.userId}`);
    codexState.data = data;
    renderCodex();
  } catch (e) {
    console.error('loadCardsCodex', e);
  }
}

function renderCodex() {
  const d = codexState.data;
  if (!d) return;
  $('#codex-collected').textContent = d.collected;
  $('#codex-total').textContent = d.total;
  const pct = d.total ? Math.round((d.collected / d.total) * 100) : 0;
  $('#codex-progress-fill').style.width = pct + '%';
  $('#codex-common').textContent = `${d.by_rarity.common.collected}/${d.by_rarity.common.total}`;
  $('#codex-rare').textContent = `${d.by_rarity.rare.collected}/${d.by_rarity.rare.total}`;
  $('#codex-legendary').textContent = `${d.by_rarity.legendary.collected}/${d.by_rarity.legendary.total}`;
  if (d.event_total > 0) {
    $('#codex-event-chip').classList.remove('hidden');
    $('#codex-event').textContent = `${d.event_collected}/${d.event_total}`;
  }

  const grid = $('#codex-grid');
  const empty = $('#codex-empty');
  grid.innerHTML = '';

  const filter = codexState.filter;
  const filteredCollected = d.collected_cards.filter((c) => {
    if (filter === 'all') return true;
    if (filter === 'event') return c.is_event;
    return c.type === filter;
  });
  const filteredLocked = d.locked_cards.filter((c) => {
    if (filter === 'all') return true;
    if (filter === 'event') return c.is_event;
    return c.type === filter;
  });

  if (filteredCollected.length === 0 && filteredLocked.length === 0) {
    empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden');

  for (const c of filteredCollected) {
    const el = document.createElement('button');
    el.className = `codex-card rarity-${c.rarity}${c.is_event ? ' is-event' : ''}`;
    el.innerHTML = `
      ${c.stats.mastered ? '<div class="codex-card-mastered">✓</div>' : ''}
      <div class="codex-card-emoji">${c.emoji}</div>
      <div class="codex-card-cat">${categoryLabel(c.category)}</div>
      <div class="codex-card-q">${escapeHtml(c.question)}</div>
    `;
    el.addEventListener('click', () => openCardReview(c));
    grid.appendChild(el);
  }

  for (const c of filteredLocked) {
    const isFpLocked = c.tier_required === 'fp_franchise' || c.tier_required === 'retail' || c.tier_required === 'fp_lifetime';
    const el = document.createElement(isFpLocked ? 'button' : 'div');
    const cls = [`codex-card`, `rarity-${c.rarity}`];
    if (c.is_event) cls.push('is-event');
    if (isFpLocked) cls.push('fp-locked');
    else cls.push('locked');
    el.className = cls.join(' ');
    if (isFpLocked) {
      el.innerHTML = `
        <div class="codex-card-emoji">🔒</div>
        <div class="codex-card-cat">${categoryLabel(c.category)}</div>
        <div class="codex-card-q">FP 會員專屬</div>
        <div class="codex-card-fp-cta">加入解鎖 →</div>
      `;
      el.addEventListener('click', () => window.open('https://pandora.js-store.com.tw/join', '_blank'));
    } else {
      el.innerHTML = `
        <div class="codex-card-emoji">❔</div>
        <div class="codex-card-cat">${categoryLabel(c.category)}</div>
        <div class="codex-card-q"></div>
      `;
    }
    grid.appendChild(el);
  }
}

function openCardReview(card) {
  window.sfx?.play('card_flip');
  const modal = $('#card-review-modal');
  const rarity = $('#review-rarity-badge');
  rarity.className = `card-review-rarity rarity-${card.rarity}${card.is_event ? ' is-event' : ''}`;
  rarity.textContent = rarityLabel(card.rarity) + (card.is_event ? ' · 事件' : '');
  $('#review-emoji').textContent = card.emoji;
  $('#review-category').textContent = categoryLabel(card.category);
  $('#review-question').textContent = card.question;

  const choicesEl = $('#review-choices');
  choicesEl.innerHTML = '';
  card.choices.forEach((ch, i) => {
    const div = document.createElement('div');
    const isCorrect = ch.correct === true;
    const isUserPick = card.stats.last_chosen_idx === i;
    const cls = ['review-choice'];
    if (isCorrect) cls.push('correct');
    if (isUserPick) cls.push('user-picked');
    div.className = cls.join(' ');
    div.innerHTML = `
      ${escapeHtml(ch.text)}
      ${ch.feedback ? `<div class="review-choice-fb">${escapeHtml(ch.feedback)}</div>` : ''}
    `;
    choicesEl.appendChild(div);
  });

  $('#review-explain').textContent = card.explain || '';
  const labelEl = $('#review-explain-label');
  if (labelEl) labelEl.textContent = `📚 ${mascotName()}筆記`;
  const d = new Date(card.stats.last_seen_at);
  const dateStr = `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
  const timesPart = card.type === 'knowledge'
    ? `答對 ${card.stats.times_correct} / ${card.stats.times_seen} 次`
    : `回答過 ${card.stats.times_seen} 次`;
  $('#review-meta').textContent = `${timesPart} · 最後一次 ${dateStr}`;

  modal.classList.remove('hidden');
}

function closeCardReview() {
  window.sfx?.play('ui_close');
  $('#card-review-modal').classList.add('hidden');
}
function categoryLabel(c) {
  return ({
    basics: '營養基礎',
    weightloss: '減脂原理',
    exercise: '運動心法',
    mindset: '心魔攻略',
    social: '社交情境',
    emotion: '情緒課題',
    temptation: '日常誘惑',
    store: '店家情境',
    fp_recipe: 'FP 食譜',
  })[c] || c;
}

// =========================================================
// Knowledge Island — map + scenes
// =========================================================

// Knowledge Island state — now a fullscreen overlay, not a tab.
const islandState = {
  data: null,              // /island/scenes response
  entitlements: null,      // /entitlements response
  currentStore: null,      // store object from scenes
  currentStoreData: null,  // /island/store/:key/:userId response
};

// =========================================================
// First-run disclaimer modal — "This is not medical advice"
// Required before any in-app recommendations reach the user.
// =========================================================
function showDisclaimerModal() {
  return new Promise((resolve) => {
    const d = cfg('disclaimer') || {};
    const title = d.title || '歡迎你，先跟你說一下';
    const bodyHtml = d.body_html || '<p>本服務非醫療建議。</p>';
    const ack = d.ack_button || '我了解了，開始使用';
    const termsLabel = d.footer_terms_label || '服務條款與隱私權政策';
    const overlay = document.createElement('div');
    overlay.className = 'first-run-overlay disclaimer-overlay';
    overlay.innerHTML = `
      <div class="first-run-card">
        <div class="first-run-icon">🛡️</div>
        <div class="first-run-title">${title}</div>
        <div class="first-run-body">
          ${bodyHtml}
          <p class="first-run-foot">點下方代表你已閱讀並同意 <a href="#" id="disclaimer-terms-link">${termsLabel}</a>。</p>
        </div>
        <button class="first-run-btn" type="button" id="disclaimer-ack-btn">${ack}</button>
      </div>
    `;
    document.body.appendChild(overlay);
    overlay.querySelector('#disclaimer-terms-link')?.addEventListener('click', (e) => {
      e.preventDefault();
      toast('服務條款頁尚未上線，稍後補上', { emoji: '📄' });
    });
    overlay.querySelector('#disclaimer-ack-btn').addEventListener('click', () => {
      overlay.classList.add('closing');
      setTimeout(() => { overlay.remove(); resolve(); }, 280);
    });
  });
}

// =========================================================
// First-run onboarding — 3 steps: meet mascot → set goal → show island
// =========================================================
function showOnboarding() {
  return new Promise((resolve) => {
    const animalName = (window.animalList && state.animal) ? state.animal : 'cat';
    // Pull step definitions from server config; render icons client-side.
    const serverSteps = cfg('onboarding.steps', []);
    const steps = serverSteps.map((s) => {
      const title = s.title_template
        ? String(s.title_template).replace(/\{mascot\}/g, mascotName())
        : (s.title || '');
      let icon = '';
      let iconClass = '';
      if (s.icon_kind === 'animal') {
        icon = window.animalImg ? window.animalImg(animalName) : '🐰';
        iconClass = 'animal';
      } else if (s.icon_kind === 'svg' && s.icon_name) {
        icon = window.icon ? window.icon(s.icon_name, { size: 96 }) : '✨';
        iconClass = 'svg';
      } else if (s.icon_emoji) {
        icon = s.icon_emoji;
      }
      return { title, body: s.body_html || '', icon, iconClass, cta: s.cta || '繼續' };
    });
    if (steps.length === 0) { resolve(); return; }
    let idx = 0;
    const overlay = document.createElement('div');
    overlay.className = 'first-run-overlay onboarding-overlay';
    const render = () => {
      const s = steps[idx];
      overlay.innerHTML = `
        <div class="first-run-card onboarding-card">
          <div class="onboarding-progress">${steps.map((_, i) => `<span class="${i === idx ? 'active' : (i < idx ? 'done' : '')}"></span>`).join('')}</div>
          <div class="first-run-icon onboarding-icon ${s.iconClass}">${s.icon}</div>
          <div class="first-run-title">${s.title}</div>
          <div class="first-run-body"><p>${s.body}</p></div>
          <button class="first-run-btn" type="button" id="onb-next">${s.cta} →</button>
        </div>
      `;
      overlay.querySelector('#onb-next').addEventListener('click', () => {
        if (idx < steps.length - 1) { idx++; render(); }
        else { overlay.classList.add('closing'); setTimeout(() => { overlay.remove(); resolve(); }, 280); }
      });
    };
    document.body.appendChild(overlay);
    render();
  });
}

// =========================================================
// Client error reporter — batches errors to /api/client-errors.
// When SENTRY_DSN is configured at build time, swap this for Sentry SDK init.
// =========================================================
(function installClientErrorReporter() {
  const INFLIGHT = new Set();
  const report = async (payload) => {
    const sig = `${payload.message}::${(payload.stack || '').slice(0, 60)}`;
    if (INFLIGHT.has(sig)) return;       // de-dupe identical errors per session
    INFLIGHT.add(sig);
    try {
      await fetch(API + '/client-errors', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          ...payload,
          user_id: state.userId || undefined,
          url: location.href,
          user_agent: navigator.userAgent,
        }),
      });
    } catch {
      // Swallow — reporter must never itself throw
    }
  };
  window.addEventListener('error', (e) => {
    if (!e || !e.message) return;
    report({ message: String(e.message), stack: e.error?.stack });
  });
  window.addEventListener('unhandledrejection', (e) => {
    const r = e.reason;
    const msg = (r && (r.message || String(r))) || 'unhandledrejection';
    report({ message: msg, stack: r?.stack });
  });
})();

// ===== Daily goal celebration — fullscreen "goal achieved" with randomized hype =====
// Runtime pulls cfg('goal_cheers.water' / '.exercise') first; these const arrays
// are offline fallbacks (used on first launch before bootstrap + when server unreachable).
const GOAL_CHEERS_FALLBACK = {
  water: [
    { title: '水水達標！', sub: '身體的每一滴你都照顧到了，繼續保持 ✨' },
    { title: '喝爆！', sub: '3000ml 一口氣搞定，今天的皮膚會感謝你 💧' },
    { title: '超水人', sub: '連細胞都在跳舞，真的太會了。' },
    { title: '滿水位！', sub: '身體重開機完成。代謝 UP、精神 UP！' },
    { title: '水之王者', sub: '這麼穩的喝水節奏，是養生界的頂流。' },
    { title: '補水滿分', sub: '今天這樣，明天起床你會發現氣色更好。' },
  ],
  exercise: [
    { title: '動起來了！', sub: '每天 30 分，堅持的人最迷人。繼續！' },
    { title: '汗水達標', sub: '身體感謝你、心情也會謝謝你。' },
    { title: '運動魂上線', sub: '肌肉有在聽，明天會比今天更強。' },
    { title: '燃脂完成', sub: '這樣練下去，體態變化真的是看得見的。' },
    { title: '活力全開', sub: '動得開心比什麼都重要，今天做到了！' },
    { title: '堅持的人', sub: '你不是在運動，你是在幫未來的自己存錢。' },
  ],
};
// Track which goals have been celebrated today so we don't fire repeatedly
function goalCelebrateKey(goal) {
  const today = new Date().toISOString().slice(0, 10);
  return `doudou.goalCelebrate.${goal}.${today}`;
}
function hasCelebratedGoal(goal) {
  return localStorage.getItem(goalCelebrateKey(goal)) === '1';
}
function markGoalCelebrated(goal) {
  localStorage.setItem(goalCelebrateKey(goal), '1');
}
function showGoalCelebration(goal) {
  if (hasCelebratedGoal(goal)) return;
  markGoalCelebrated(goal);
  const pool = cfg(`goal_cheers.${goal}`, GOAL_CHEERS_FALLBACK[goal]) || [];
  if (pool.length === 0) return;
  const pick = pool[Math.floor(Math.random() * pool.length)];
  const iconHtml = goal === 'water'
    ? (window.icon ? window.icon('water_double', { size: 84 }) : '💧')
    : (window.icon ? window.icon('dumbbell',     { size: 84 }) : '💪');
  const overlay = document.createElement('div');
  overlay.className = 'goal-celebrate-overlay';
  overlay.innerHTML = `
    <div class="goal-celebrate-card">
      <div class="goal-celebrate-icon">${iconHtml}</div>
      <div class="goal-celebrate-eyebrow">每日目標達成</div>
      <div class="goal-celebrate-title">${pick.title}</div>
      <div class="goal-celebrate-sub">${pick.sub}</div>
      <button class="goal-celebrate-btn" type="button">繼續加油 →</button>
    </div>
  `;
  document.body.appendChild(overlay);
  window.sfx?.play('level_up');
  const close = () => {
    overlay.style.animation = 'goal-fade-in .3s reverse forwards';
    setTimeout(() => overlay.remove(), 320);
  };
  overlay.querySelector('.goal-celebrate-btn').addEventListener('click', close);
  // Auto-dismiss after 5 seconds if user doesn't click
  setTimeout(close, 5000);
}

// User-editable daily water target (ml). Default 3000, clamp 500..6000.
function getWaterTarget() {
  const fromSettings = Number(state.settings?.daily_water_goal_ml);
  if (Number.isFinite(fromSettings) && fromSettings >= 500 && fromSettings <= 6000) return fromSettings;
  const raw = Number(localStorage.getItem('doudou.waterTargetMl'));
  if (Number.isFinite(raw) && raw >= 500 && raw <= 6000) return raw;
  return 3000;
}
function setWaterTarget(ml) {
  const n = Math.max(500, Math.min(6000, Math.round(Number(ml) || 0)));
  localStorage.setItem('doudou.waterTargetMl', String(n));
  if (state.settings) state.settings.daily_water_goal_ml = n;
  // Fire-and-forget server sync (don't block UI)
  patchSettings({ daily_water_goal_ml: n }).catch(() => {});
  return n;
}

// NPC emoji -> SVG icon name (for dialog overlay portraits)
// Server config `npc_icon_map` takes precedence when present.
const NPC_EMOJI_ICON_FALLBACK = {
  '🧑': 'npc_clerk',
  '🧑‍💼': 'npc_clerk',
  '🧑‍🍳': 'npc_chef',
  '👨‍🍳': 'npc_chef',
  '👩‍🎨': 'npc_artist',
  '👧': 'npc_girl',
  '👩‍🔬': 'npc_scientist',
  '👨‍🔬': 'npc_scientist',
  '👴': 'npc_elder',
  '👵': 'npc_elder',
  '🗺️': 'map',
};

// Module-level: store key -> SVG icon name (in icons.js)
const STORE_ICON_MAP = {
  familymart: 'store_conv', seven_eleven: 'store_conv',
  pxmart: 'store_supermarket',
  mcdonalds: 'store_fastfood', kfc: 'store_fastfood',
  starbucks: 'store_cafe',
  night_market: 'store_night_market',
  bubble_tea: 'store_bubble',
  sushi_box: 'store_sushi',
  healthy_box: 'store_healthy',
  fp_shop: 'store_fp',
  fp_base: 'store_fp_base',
};

// Intent emoji -> SVG icon name (paintIntents uses this; unknowns fallback to emoji)
const INTENT_ICON_MAP = {
  '☕': 'coffee',
  '🍳': 'egg',
  '🎉': 'sparkle',
  '🍲': 'hot_pot',
  '🥗': 'salad',
  '🌙': 'moon_sleep',
  '🍣': 'sushi',
  '🍱': 'meal_box',
  '🍔': 'store_fastfood',
  '🌅': 'sunset',
  '🧋': 'bubble_tea',
  '🏮': 'lantern',
  '🥐': 'sandwich',
  '🍧': 'ice_cream',
  '📋': 'prep',
  '🎓': 'lightbulb',
  '📣': 'phone',
};

function openPaywall(context = 'quota') {
  const title = $('#paywall-hero-title');
  const sub = $('#paywall-hero-sub');
  if (context === 'general' && title && sub) {
    title.textContent = '解鎖 FP 完整體驗 ✨';
    sub.textContent = '選一個適合你的方案';
  } else if (title && sub) {
    title.textContent = '本月島嶼試用結束';
    sub.textContent = '下個月 1 號重置 3 次 · 或直接解鎖';
  }
  $('#paywall-modal').classList.remove('hidden');
  window.sfx?.play('notify');
}
function closePaywall() {
  $('#paywall-modal').classList.add('hidden');
}

async function handleSubscribe(type) {
  if (!state.userId) return;
  try {
    await api('POST', '/subscribe/mock', { user_id: state.userId, type });
    window.sfx?.play('level_up');
    toast(type === 'app_monthly' ? '已訂閱 App 月費！🎉' : '已訂閱 App 年費！🎉');
    closePaywall();
    // Refresh entitlements so island works again
    if ($('#island-fullscreen') && !$('#island-fullscreen').classList.contains('hidden')) {
      try {
        islandState.entitlements = await api('GET', `/entitlements/${state.userId}`);
        renderIslandMap();
      } catch {}
    }
    loadTierInfo();
  } catch (e) {
    toast('訂閱失敗：' + (e.message || ''));
  }
}

async function handlePaywallRedeem() {
  const input = $('#paywall-code-input');
  const code = input.value.trim();
  if (!code) { toast('輸入代碼再兌換'); return; }
  try {
    const r = await api('POST', '/tier/redeem', { user_id: state.userId, ref_code: code });
    window.sfx?.play('level_up');
    toast('升級成功！✨');
    input.value = '';
    closePaywall();
    islandState.entitlements = await api('GET', `/entitlements/${state.userId}`);
    renderIslandMap();
    loadTierInfo();
  } catch (e) {
    toast(e.message || '兌換失敗');
  }
}

async function openIsland() {
  if (!state.userId) return;
  try {
    const [scenes, ent] = await Promise.all([
      api('GET', `/island/scenes/${state.userId}`),
      api('GET', `/entitlements/${state.userId}`),
    ]);
    islandState.data = scenes;
    islandState.entitlements = ent;
    renderIslandMap();
    paintIslandDecorations();
    $('#island-fullscreen').classList.remove('hidden');
    document.body.classList.add('island-mode');
    applyTimeOfDay();
    setupIslandPan();
    window.sfx?.play('ui_open');
  } catch (e) {
    console.error(e);
    toast('島嶼載入失敗');
  }
}

// Replace the emoji deco nodes (tree / flower / rock) with SVG illustrations
function paintIslandDecorations() {
  if (!window.icon) return;
  const world = $('#island-world');
  if (!world || world.dataset.decosPainted === '1') return;
  world.dataset.decosPainted = '1';

  // Tree-ish: alternate palm / tree so it feels lush
  const trees = world.querySelectorAll('.island-deco-tree');
  trees.forEach((el, idx) => {
    el.innerHTML = window.icon(idx % 2 === 0 ? 'palm' : 'tree', { size: 64 });
  });
  world.querySelectorAll('.island-deco-flower').forEach((el) => {
    el.innerHTML = window.icon('flower', { size: 32 });
  });
  world.querySelectorAll('.island-deco-rock').forEach((el) => {
    el.innerHTML = window.icon('rock', { size: 40 });
  });
}

// 2D drag-to-pan controller for the island world map
function setupIslandPan() {
  const vp = $('#island-world-viewport');
  const world = $('#island-world');
  if (!vp || !world || vp.dataset.panSetup === '1') return;
  vp.dataset.panSetup = '1';
  const WORLD_W = 1300, WORLD_H = 950;

  // Center on familymart initially
  const viewW = vp.clientWidth;
  const viewH = vp.clientHeight;
  let panX = Math.max(viewW - WORLD_W, Math.min(0, -200 + viewW / 2));
  let panY = Math.max(viewH - WORLD_H, Math.min(0, -430 + viewH / 2));
  const apply = () => { world.style.transform = `translate(${panX}px, ${panY}px)`; };
  apply();

  let dragging = false;
  let sx = 0, sy = 0, spX = 0, spY = 0;
  let moved = false;
  let pointerId = null;

  const onDown = (e) => {
    // Always clear stale "moved" state so a fresh tap isn't swallowed
    moved = false;
    // Don't start drag if user tapped a building
    if (e.target.closest('.island-store-pin')) return;
    dragging = true;
    sx = e.clientX; sy = e.clientY;
    spX = panX; spY = panY;
    // (no pointer capture — keeps normal click bubbling to pins)
    vp.classList.add('dragging');
  };
  const onMove = (e) => {
    if (!dragging) return;
    const dx = e.clientX - sx;
    const dy = e.clientY - sy;
    if (Math.abs(dx) + Math.abs(dy) > 4) moved = true;
    const vw = vp.clientWidth;
    const vh = vp.clientHeight;
    panX = Math.max(vw - WORLD_W, Math.min(0, spX + dx));
    panY = Math.max(vh - WORLD_H, Math.min(0, spY + dy));
    apply();
  };
  const onUp = () => {
    dragging = false;
    vp.classList.remove('dragging');
  };

  vp.addEventListener('pointerdown', onDown);
  vp.addEventListener('pointermove', onMove);
  vp.addEventListener('pointerup', onUp);
  vp.addEventListener('pointercancel', onUp);

  // Suppress click on buildings if user was dragging
  vp.addEventListener('click', (e) => {
    if (moved) { e.stopPropagation(); e.preventDefault(); moved = false; }
  }, true);
}

function applyTimeOfDay() {
  const h = new Date().getHours();
  const cls = h >= 5 && h < 9 ? 'time-dawn'
    : h >= 9 && h < 17 ? 'time-day'
    : h >= 17 && h < 20 ? 'time-evening'
    : 'time-night';
  document.body.classList.remove('time-dawn', 'time-day', 'time-evening', 'time-night');
  document.body.classList.add(cls);
  const sun = document.querySelector('.island-sun');
  if (sun) sun.textContent = cls === 'time-night' ? '🌙' : '☀️';
}

function closeIsland() {
  $('#island-fullscreen').classList.add('hidden');
  document.body.classList.remove('island-mode');
  // Reset to map view for next open
  $('#island-view-map').classList.remove('hidden');
  $('#island-view-store').classList.add('hidden');
  $('#store-recs').classList.add('hidden');
  $('#store-intents').classList.remove('hidden');
  islandState.currentStore = null;
  islandState.currentStoreData = null;
  window.sfx?.play('ui_close');
  // Refresh dashboard/tier display
  loadDashboard();
}

function paintIslandCharacter() {
  const charEl = $('#island-character');
  if (!charEl) return;
  charEl.innerHTML = renderCharacter({ animal: state.animal, level: state.lastLevel, mood: 'happy', outfit: 'none' });
  // Store-inside mascot: simple Fluent img, no halo/rarity chip — fits 72px circle cleanly
  const storeChar = $('#store-character');
  if (storeChar) storeChar.innerHTML = window.animalImg ? window.animalImg(state.animal, 'in-store') : '';
}

// Per-store NPC animal — offline fallback. Server `store_npc_animal` config wins.
const STORE_NPC_ANIMAL = {
  familymart: 'bear',
  seven_eleven: 'penguin',
  pxmart: 'hamster',
  mcdonalds: 'fox',
  kfc: 'shiba',
  starbucks: 'tuxedo',
  night_market: 'dinosaur',
  bubble_tea: 'cat',
  sushi_box: 'rabbit',
  healthy_box: 'hamster',
  fp_shop: 'bear',
  fp_base: 'fox',
};
function npcAnimalFor(storeKey) {
  const picks = ['bear','penguin','hamster','fox','shiba','tuxedo','dinosaur','cat','rabbit'];
  const userAnimal = state.animal || 'cat';
  const serverMap = cfg('store_npc_animal', STORE_NPC_ANIMAL) || {};
  const preferred = serverMap[storeKey] || 'bear';
  if (preferred !== userAnimal) return preferred;
  // Collision: pick first alternative that isn't the user's
  return picks.find((p) => p !== userAnimal) || 'bear';
}

function paintIslandQuest() {
  // Count unique stores visited this week (rough — based on scenes that have
  // at least one visited_today hotspot over the past 7 days client-side)
  // For simplicity, increment locally per visit this session.
  // In v2 we'd compute server-side; keeping it simple here.
  const quest = $('#island-quest');
  const target = 3;
  const raw = Number(localStorage.getItem('island_week_count') || 0);
  const fill = $('#quest-fill');
  const prog = $('#quest-progress');
  const pct = Math.min(100, (raw / target) * 100);
  if (fill) fill.style.width = pct + '%';
  if (prog) prog.textContent = `${Math.min(raw, target)} / ${target} · 完成拿 30 XP`;
  if (quest) quest.classList.toggle('done', raw >= target);
  $('#quest-target').textContent = String(target);
}

function bumpIslandQuest() {
  const cur = Number(localStorage.getItem('island_week_count') || 0);
  const target = 3;
  if (cur < target) {
    localStorage.setItem('island_week_count', String(cur + 1));
    if (cur + 1 === target) {
      toast('週任務完成！+30 XP 🎉');
      window.sfx?.play('level_up');
    }
  }
  paintIslandQuest();
}

function renderIslandMap() {
  const d = islandState.data;
  const ent = islandState.entitlements;
  if (!d || !ent) return;
  paintIslandQuest();

  // Tier badge
  const tierEl = $('#island-tier-display');
  tierEl.className = `island-tier-badge tier-${ent.tier}`;
  const tierName = tierLabel(ent.tier);
  tierEl.textContent = ent.tier === 'fp_lifetime' ? `👑 ${tierName}` : tierName;

  // Quota badge
  const quotaEl = $('#island-quota-display');
  if (ent.unlimited_island) {
    quotaEl.className = 'island-quota-badge unlimited';
    quotaEl.textContent = ent.subscription !== 'none' ? '📱 已訂閱' : '♾️ 永久會員';
  } else {
    const cls = ent.island_quota_remaining <= 1 ? 'island-quota-badge low' : 'island-quota-badge';
    quotaEl.className = cls;
    quotaEl.textContent = `本月剩 ${ent.island_quota_remaining} / ${ent.island_quota_total}`;
  }

  paintIslandCharacter();

  const bubble = $('#island-character-bubble');
  if (bubble) {
    bubble.textContent = `來${mascotName()}的島嶼探險吧～`;
    setTimeout(() => bubble.classList.add('show'), 200);
    setTimeout(() => bubble.classList.remove('show'), 3500);
  }

  // Store positions on the 1300×950 world map — hand-placed for good layout
  const STORE_POSITIONS = {
    familymart:   { x: 200, y: 430 },
    pxmart:       { x: 420, y: 500 },
    mcdonalds:    { x: 650, y: 570 },
    seven_eleven: { x: 890, y: 450 },
    starbucks:    { x: 320, y: 680 },
    bubble_tea:   { x: 570, y: 780 },
    kfc:          { x: 1030, y: 560 },
    night_market: { x: 850, y: 250 },
    sushi_box:    { x: 220, y: 220 },
    healthy_box:  { x: 1100, y: 780 },
    fp_shop:      { x: 470, y: 190 },
    fp_base:      { x: 1080, y: 140 },
  };

  const stores = $('#island-stores');
  stores.innerHTML = '';
  for (const s of d.scenes) {
    const pin = document.createElement('button');
    pin.type = 'button';
    pin.dataset.key = s.key;
    const pos = STORE_POSITIONS[s.key] || { x: 650, y: 450 };
    pin.style.left = pos.x + 'px';
    pin.style.top = pos.y + 'px';
    const styleCls = s.key === 'fp_shop' ? ' tier-retail' : s.key === 'fp_base' ? ' tier-fp_franchise' : '';
    const lockCls = !s.unlocked && s.lock_reason === 'level' ? ' locked-level' : '';
    pin.className = `island-store-pin${styleCls}${lockCls}`;
    const famLabel = {
      first: '首次', new: '新客', regular: '熟面孔', frequent: '老客', mascot: '店寵',
    }[s.familiarity_level] || '';
    const famBadge = s.visit_count > 0
      ? `<span class="island-store-fam">${s.familiarity_emoji} ${famLabel} ${s.visit_count}</span>`
      : '';
    const lockChip = !s.unlocked && s.lock_reason === 'level'
      ? `<span class="island-store-pin-lock-chip">🔒 Lv.${s.min_level}</span>`
      : '';
    const iconName = STORE_ICON_MAP[s.key];
    const emojiHtml = iconName && window.icon
      ? window.icon(iconName, { size: 88 })
      : `<span style="font-size:64px">${s.emoji}</span>`;
    pin.innerHTML = `
      ${lockChip}
      ${famBadge}
      <div class="island-store-pin-emoji">${emojiHtml}</div>
      <div>
        <div class="island-store-pin-name">${s.name}</div>
        <div class="island-store-pin-desc">${s.unlocked ? s.description : `到 Lv.${s.min_level} 解鎖`}</div>
      </div>
    `;
    pin.addEventListener('click', () => {
      if (!s.unlocked && s.lock_reason === 'level') {
        toast(`這家店要 Lv.${s.min_level} 才能解鎖（目前 Lv.${s.user_level}） 🔒`);
        return;
      }
      enterStore(s);
    });
    stores.appendChild(pin);
  }
}

async function enterStore(scene) {
  // Cinematic walk-in: character slides in from left + door + backdrop fade
  await playWalkIntoStore(scene);
  const mapView = $('#island-view-map');
  const storeView = $('#island-view-store');

  islandState.currentStore = scene;
  let storeData;
  try {
    storeData = await api('GET', `/island/store/${scene.key}/${state.userId}`);
    islandState.currentStoreData = storeData;
  } catch (e) {
    const msg = String(e.message || '');
    if (msg.includes('試用次數已用完') || msg.includes('QUOTA_EXHAUSTED')) {
      openPaywall();
      return;
    }
    toast(msg || '進入店家失敗');
    return;
  }
  // Refresh entitlements (quota was consumed) for the badge
  try {
    islandState.entitlements = await api('GET', `/entitlements/${state.userId}`);
    renderIslandMap();
  } catch {}

  // Paint store header — SVG if we have one, else fallback emoji
  const headIconName = STORE_ICON_MAP[scene.key];
  const headEl = $('#store-emoji');
  if (headIconName && window.icon) {
    headEl.innerHTML = window.icon(headIconName, { size: 28 });
  } else {
    headEl.textContent = storeData.emoji;
  }
  $('#store-name').textContent = storeData.name;
  // Per-store inside theme (tints backdrop to match the store identity)
  storeView.dataset.storeKey = scene.key;
  // Paint character early so it's visible once store view appears
  paintIslandCharacter();
  // Reset scroll so character + speech are always in view
  const storeContent = storeView.querySelector('.store-content');
  if (storeContent) storeContent.scrollTop = 0;
  const speech = $('#store-speech');
  const greet = scene.key.startsWith('fp_')
    ? `${mascotName()}：歡迎來到 ${storeData.name}，今天想...`
    : `${mascotName()}：你今天來 ${storeData.name} 想...`;
  speech.textContent = greet;

  // Play JRPG-style NPC dialog FIRST (before showing intents)
  if (storeData.dialog && storeData.dialog.length > 0) {
    mapView.classList.add('hidden');
    storeView.classList.remove('hidden');
    await playDialog(storeData.dialog, {
      npc: storeData.npc || { emoji: '🧑', name: '店員' },
      npcAnimal: npcAnimalFor(scene.key),
      backdrop: scene.backdrop || storeData.backdrop,
    });
  }

  // Budget panel
  const us = storeData.user_state;
  const budgetEl = $('#store-budget');
  if (us.remaining_calories > 0) {
    budgetEl.innerHTML = `今天還剩 <b>${us.remaining_calories}</b> 卡 · 還需要 <b>${us.protein_needed_g}g</b> 蛋白質`;
    budgetEl.style.display = '';
  } else {
    budgetEl.innerHTML = `今日熱量已達標 · 建議選輕食或飲料 🌿`;
    budgetEl.style.display = '';
  }

  // Intents
  paintIntents(storeData.intents);

  // Show store view (may already be visible from dialog step above)
  mapView.classList.add('hidden');
  storeView.classList.remove('hidden');
  $('#store-recs').classList.add('hidden');
  $('#store-intents').classList.remove('hidden');
  paintIslandCharacter();
}

// Play milestone story — pulled from backend on advance
async function maybePlayMilestoneStory(journey) {
  if (!journey.milestones) return;
  const cur = journey.milestones.find((m) => m.day === journey.day && m.achieved_this_cycle);
  if (!cur) return;
  try {
    const story = await api('GET', `/journey/${state.userId}/milestone/${journey.day}`);
    if (story && story.lines && story.lines.length > 0) {
      await playDialog(story.lines, { npc: { emoji: '🗺️', name: '旅程精靈' } });
    }
  } catch {}
}

function paintIntents(intents) {
  const wrap = $('#store-intents');
  wrap.innerHTML = '';
  for (const intent of intents) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'store-intent-btn';
    const iconName = (cfg('intent_icon_map', INTENT_ICON_MAP) || {})[intent.emoji];
    const emojiHtml = iconName && window.icon
      ? window.icon(iconName, { size: 44 })
      : intent.emoji;
    btn.innerHTML = `
      <div class="store-intent-emoji">${emojiHtml}</div>
      <div class="store-intent-label">${intent.label}</div>
    `;
    btn.addEventListener('click', () => showRecommendations(intent));
    wrap.appendChild(btn);
  }
}

async function showRecommendations(intent) {
  window.sfx?.play('choice_select');

  // This is the deeper interaction — try to consume 1 monthly quota.
  // For unlimited users (FP lifetime / app subscribers) this is a no-op.
  try {
    const r = await api('POST', '/island/consume-visit', {
      user_id: state.userId,
      store_key: islandState.currentStore?.key,
    });
    islandState.entitlements = r;
    renderIslandMap();
  } catch (e) {
    const msg = String(e.message || '');
    if (msg.includes('QUOTA_EXHAUSTED') || msg.includes('試用次數已用完')) {
      openPaywall();
      return;
    }
    toast(msg || '載入失敗');
    return;
  }

  // Quest: count a unique store visit
  bumpIslandQuest();

  $('#store-intents').classList.add('hidden');
  const panel = $('#store-recs');
  panel.classList.remove('hidden');
  $('#store-recs-prompt').textContent = `${mascotName()}：${intent.prompt_line}`;

  const list = $('#store-recs-list');
  list.innerHTML = '';
  for (const rec of intent.recommendations) {
    const card = document.createElement('div');
    const cls = ['store-rec-card'];
    if (rec.stars >= 5) cls.push('best');
    if (rec.stars <= 1) cls.push('warn');
    card.className = cls.join(' ');
    const starStr = '★'.repeat(rec.stars) + '☆'.repeat(Math.max(0, 5 - rec.stars));
    const nutrLine = rec.calories > 0
      ? `<div class="store-rec-nutr">
          <span class="calories">${rec.calories} 卡</span>
          <span>蛋白 ${rec.protein_g}g</span>
          <span>碳水 ${rec.carbs_g}g</span>
          <span>脂肪 ${rec.fat_g}g</span>
        </div>`
      : '';
    const itemsHtml = rec.items.map((it) => `
      <div class="store-rec-item">
        <div class="store-rec-item-emoji">${emojiForItem(it)}</div>
        <div class="store-rec-item-name">${escapeHtml(it)}</div>
      </div>
    `).join('');
    card.innerHTML = `
      <div class="store-rec-head">
        <div class="store-rec-title">${escapeHtml(rec.title)}</div>
        <div class="store-rec-stars">${starStr}</div>
      </div>
      <div class="store-rec-items-grid">${itemsHtml}</div>
      ${nutrLine}
      <div class="store-rec-why">💡 ${escapeHtml(rec.why)}</div>
      <div class="store-rec-actions">
        ${rec.calories > 0 ? `<button class="store-rec-log" type="button">📝 記這餐</button>` : ''}
        ${rec.quiz_card_id ? `<button class="store-rec-quiz" type="button">🎴 測我懂不懂</button>` : ''}
      </div>
    `;
    const logBtn = card.querySelector('.store-rec-log');
    const quizBtn = card.querySelector('.store-rec-quiz');
    if (logBtn) logBtn.addEventListener('click', () => logRecMeal(rec));
    if (quizBtn) quizBtn.addEventListener('click', () => openRecQuiz(rec));
    list.appendChild(card);
  }
}

async function logRecMeal(rec) {
  try {
    const hour = new Date().getHours();
    const meal_type = hour < 10 ? 'breakfast' : hour < 14 ? 'lunch' : hour < 21 ? 'dinner' : 'snack';
    const r = await api('POST', '/meals/text', {
      user_id: state.userId,
      food_name: rec.primary_item,
      meal_type,
    });
    window.sfx?.play('meal_logged');
    toast(`已記錄！+${r.xp_gained} XP`, { emoji: '🎉' });
    const unlocks = collectUnlockRewards(r);
    for (const u of unlocks) enqueueReward(u);
    if (!unlocks.length) confetti();
    // Refresh budget inline without leaving store
    if (islandState.currentStore) {
      try {
        const fresh = await api('GET', `/island/store/${islandState.currentStore.key}/${state.userId}`);
        islandState.currentStoreData = fresh;
        const us = fresh.user_state;
        const budgetEl = $('#store-budget');
        if (us.remaining_calories > 0) {
          budgetEl.innerHTML = `今天還剩 <b>${us.remaining_calories}</b> 卡 · 還需要 <b>${us.protein_needed_g}g</b> 蛋白質`;
        } else {
          budgetEl.innerHTML = `今日熱量已達標 · 建議選輕食或飲料 🌿`;
        }
      } catch {}
    }
  } catch (e) {
    toast('記錄失敗：' + (e.message || ''), { emoji: '⚠️' });
  }
}

async function openRecQuiz(rec) {
  if (!rec.quiz_card_id) return;
  window.sfx?.play('ui_open');
  const modal = $('#card-modal');
  modal.classList.remove('hidden');
  $('#card-reveal').classList.add('hidden');
  $('#card-no-stamina').classList.add('hidden');
  $('#card-stage').classList.remove('hidden');
  $('#card-loading').classList.remove('hidden');
  resetCardStage();
  cardState.locked = true;
  let data;
  try {
    data = await api('POST', '/cards/scene-draw', { user_id: state.userId, card_id: rec.quiz_card_id });
  } catch (e) {
    $('#card-loading').classList.add('hidden');
    if (String(e.message).includes('ALREADY_ANSWERED')) {
      toast('這張卡今天測過了～明天再來 ✨');
    } else {
      toast(e.message || '開卡失敗');
    }
    closeCardModal();
    return;
  }
  cardState.playId = data.play_id;
  cardState.card = data;
  updateStaminaDisplay(data.stamina);
  const front = $('#card-front');
  front.classList.remove('rarity-common', 'rarity-rare', 'rarity-legendary');
  front.classList.add(`rarity-${data.rarity}`);
  $('#card-emoji').textContent = data.emoji || '🎴';
  $('#card-category').textContent = categoryLabel(data.category);
  $('#card-question').textContent = data.question;
  $('#card-hint').textContent = data.hint || '';
  $('#card-rarity-badge').textContent = rarityLabel(data.rarity);
  $('#card-new-badge').classList.toggle('hidden', !data.is_new);
  const btns = $$('#choice-fan .choice-card');
  btns.forEach((btn, i) => {
    const ch = data.choices[i];
    btn.querySelector('.choice-text').textContent = ch ? ch.text : '';
    btn.style.display = ch ? '' : 'none';
  });
  $('#card-loading').classList.add('hidden');
  const flipper = $('#card-flipper');
  flipper.classList.add('entering');
  window.sfx?.play('card_draw');
  setTimeout(() => window.sfx?.play('card_flip'), 470);
  if (data.rarity === 'legendary') setTimeout(() => window.sfx?.play('legendary'), 800);
  setTimeout(() => {
    $('#choice-fan').classList.add('opened');
    cardState.locked = false;
  }, 900);
}

function backToIntents() {
  window.sfx?.play('ui_close');
  $('#store-recs').classList.add('hidden');
  $('#store-intents').classList.remove('hidden');
}

function backToIslandMap() {
  window.sfx?.play('ui_close');
  $('#island-view-store').classList.add('hidden');
  $('#island-view-map').classList.remove('hidden');
  islandState.currentStore = null;
  islandState.currentStoreData = null;
  // Refresh map to show new budget
  openIsland();
}

function tierLabel(tier) {
  return ({
    public: '公開會員',
    fp_lifetime: 'FP 永久會員',
  })[tier] || tier;
}
function tierBadge(tier) {
  return ({ fp_lifetime: '👑' })[tier] || '';
}

// =========================================================
// Tier / FP Team membership UI
// =========================================================

async function loadTierInfo() {
  if (!state.userId) return;
  try {
    const ent = await api('GET', `/entitlements/${state.userId}`);
    const badge = $('#tier-badge');
    if (badge) {
      badge.className = `tier-badge tier-${ent.tier}`;
      const xp = ent.tier === 'fp_lifetime' ? 1.5 : 1;
      badge.textContent = `${ent.tier === 'fp_lifetime' ? '👑 ' : ''}${tierLabel(ent.tier)} · XP ×${xp}`;
    }
    const summary = $('#tier-status-summary');
    const actions = $('#tier-actions-wrap');
    if (summary && actions) {
      let lines = [];
      if (ent.tier === 'fp_lifetime') {
        lines.push('👑 FP 永久會員（最高權限）');
        lines.push('♾️ 島嶼無限探險 · 🎴 FP 專屬卡片 · ⚡ XP 1.5×');
      } else if (ent.subscription === 'app_monthly' || ent.subscription === 'app_yearly') {
        lines.push(`📱 ${ent.subscription === 'app_monthly' ? 'App 月訂中' : 'App 年訂中'}`);
        lines.push(`♾️ 島嶼無限探險`);
        if (ent.subscription_expires_at_iso) {
          const d = new Date(ent.subscription_expires_at_iso);
          lines.push(`到期：${d.getFullYear()}/${d.getMonth()+1}/${d.getDate()}`);
        }
      } else {
        lines.push('🆓 免費版');
        lines.push(`本月剩 ${ent.island_quota_remaining} / ${ent.island_quota_total} 次島嶼試用`);
      }
      summary.innerHTML = lines.join('<br>');

      // Action buttons
      actions.innerHTML = '';
      if (ent.tier !== 'fp_lifetime') {
        const fpBtn = document.createElement('button');
        fpBtn.className = 'btn-primary';
        fpBtn.innerHTML = '🌟 加入 FP 網站會員（永久解鎖）';
        fpBtn.addEventListener('click', () => window.open(ent.fp_web_signup_url, '_blank'));
        actions.appendChild(fpBtn);
      }
      if (ent.tier !== 'fp_lifetime' && ent.subscription === 'none') {
        const subBtn = document.createElement('button');
        subBtn.className = 'btn-secondary';
        subBtn.textContent = '📱 查看訂閱方案';
        subBtn.addEventListener('click', () => openPaywall('general'));
        actions.appendChild(subBtn);
      }
      if (ent.subscription !== 'none') {
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn-secondary';
        cancelBtn.textContent = '取消訂閱（測試用）';
        cancelBtn.addEventListener('click', async () => {
          const ok = await UI.confirm('確定取消訂閱？', { title: '取消訂閱', okText: '確定取消', cancelText: '不要', danger: true });
          if (!ok) return;
          await handleSubscribe('none');
        });
        actions.appendChild(cancelBtn);
      }
    }
  } catch (e) { /* ignore */ }
}

async function redeemTierCode() {
  const input = $('#tier-redeem-input');
  if (!input || !state.userId) return;
  const code = input.value.trim();
  if (!code) { toast('輸入代碼再兌換喔'); return; }
  try {
    const r = await api('POST', '/tier/redeem', { user_id: state.userId, ref_code: code });
    input.value = '';
    if (r.upgraded) {
      toast(`升級成功！${tierLabel(r.new_tier)} ✨`, { emoji: '🎉' });
      window.sfx?.play('level_up');
    } else {
      toast(`代碼已記錄，但你已經是 ${tierLabel(r.new_tier)}`);
    }
    await loadTierInfo();
    await loadIsland();
    await loadDashboard();
  } catch (e) {
    toast(e.message || '兌換失敗');
  }
}

init();
