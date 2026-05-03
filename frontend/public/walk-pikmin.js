/* SPEC-pikmin-walk-v1 — Pikmin Bloom 風 mini-dodo follower train + 探險日記。
 *
 * 設計：
 *  - Self-mounting: 偵測 [data-walk-widget] 自動初始化（不依賴 app.js）
 *  - API: GET /api/walk/today, POST /api/walk/sync, GET /api/walk/diary
 *  - HealthKit: 嘗試 capacitor-health plugin readSteps；fallback 顯示「同步」按鈕 disabled
 *  - 5 色 mini-dodo SVG inline（紅蛋白質 / 綠纖維 / 藍水 / 黃好油 / 紫全穀類）
 *  - 合規：用「均衡」「日常」「活力」中性詞，禁忌「燃脂」「排毒」「補鈣」
 *
 * 為什麼 self-mounting：app.js 已 6,693 行，每加新 feature 都改它太脆弱。
 * 走 vanilla module pattern：HTML 標 data-* anchor，JS 找 anchor 自掛載。
 * Cleanup 也容易（移除 anchor = 失效）。
 */
(function () {
  'use strict';

  const PHASE_THRESHOLDS = { seed: 0, sprout: 2000, bloom: 5000, fruit: 8000 };
  const PHASE_LABEL_ZH = { seed: '種子', sprout: '發芽', bloom: '開花', fruit: '結果' };
  const COLOR_LABEL = {
    red: '紅 · 蛋白質均衡',
    green: '綠 · 蔬菜纖維',
    blue: '藍 · 水分日常',
    yellow: '黃 · 好油適量',
    purple: '紫 · 全穀類',
  };

  // 5 色 SVG 共用 base shape（圓圓 dodo 寶寶 + 大眼 + 不同色 hood / 圍巾）。
  // 為什麼 inline：避免再 fetch 5 個檔；vector 縮放好；色彩切換靠 currentColor。
  function miniDodoSvg(color) {
    const fill = {
      red: '#E87B5C', green: '#7CB66B', blue: '#5BA8DD', yellow: '#E8B96A', purple: '#9F7BC9',
    }[color] || '#A89683';
    return (
      '<svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="32" height="32" aria-hidden="true">' +
      // body
      '<ellipse cx="18" cy="22" rx="11" ry="10" fill="#F5E8D7"/>' +
      // hood (color)
      '<path d="M7 18 Q18 6 29 18 Q24 14 18 14 Q12 14 7 18 Z" fill="' + fill + '"/>' +
      // eyes
      '<ellipse cx="14" cy="20" rx="1.7" ry="2.2" fill="#2A1F18"/>' +
      '<ellipse cx="22" cy="20" rx="1.7" ry="2.2" fill="#2A1F18"/>' +
      '<ellipse cx="14.6" cy="19.4" rx="0.5" ry="0.6" fill="#FFF"/>' +
      '<ellipse cx="22.6" cy="19.4" rx="0.5" ry="0.6" fill="#FFF"/>' +
      // beak
      '<path d="M16.5 24 Q18 25.4 19.5 24 Q18 25.8 16.5 24 Z" fill="#D88A4A"/>' +
      // cheeks
      '<circle cx="11.5" cy="23" r="1.2" fill="#F4B8A0" opacity="0.7"/>' +
      '<circle cx="24.5" cy="23" r="1.2" fill="#F4B8A0" opacity="0.7"/>' +
      '</svg>'
    );
  }

  function inferApiBase() {
    // app.js sets window.API_BASE on bootstrap; fallback for early-load timing.
    if (typeof window.API_BASE === 'string' && window.API_BASE) return window.API_BASE;
    if (typeof window.__API_BASE__ === 'string' && window.__API_BASE__) return window.__API_BASE__;
    return ''; // same-origin
  }

  function getAuthToken() {
    // app.js stores in localStorage 'auth_token' or 'sanctum_token' historically.
    return (
      localStorage.getItem('auth_token') ||
      localStorage.getItem('sanctum_token') ||
      localStorage.getItem('dodo_token') ||
      ''
    );
  }

  async function apiGet(path) {
    const token = getAuthToken();
    if (!token) return null;
    try {
      const resp = await fetch(inferApiBase() + path, {
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
      });
      if (!resp.ok) return null;
      return await resp.json();
    } catch (_) {
      return null;
    }
  }

  async function apiPost(path, body) {
    const token = getAuthToken();
    if (!token) return null;
    try {
      const resp = await fetch(inferApiBase() + path, {
        method: 'POST',
        headers: {
          Authorization: 'Bearer ' + token,
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(body),
      });
      if (!resp.ok) return null;
      return await resp.json();
    } catch (_) {
      return null;
    }
  }

  /** Try capacitor-health plugin (iOS HealthKit / Android Health Connect). */
  async function readNativeStepsToday() {
    try {
      // Capacitor 5+ exposes plugins via window.Capacitor.Plugins.<Name>
      const plug = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.Health;
      if (!plug || typeof plug.readSteps !== 'function') return null;
      const start = new Date();
      start.setHours(0, 0, 0, 0);
      const end = new Date();
      const result = await plug.readSteps({ startDate: start.toISOString(), endDate: end.toISOString() });
      // Different versions return { steps: number } vs { value: number } vs aggregated array
      if (typeof result?.steps === 'number') return result.steps;
      if (typeof result?.value === 'number') return result.value;
      if (Array.isArray(result?.aggregated)) {
        return result.aggregated.reduce((s, x) => s + (x.value || 0), 0);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  function phaseFromSteps(steps) {
    if (steps >= PHASE_THRESHOLDS.fruit) return 'fruit';
    if (steps >= PHASE_THRESHOLDS.bloom) return 'bloom';
    if (steps >= PHASE_THRESHOLDS.sprout) return 'sprout';
    return 'seed';
  }

  function fillForPhase(phase, steps) {
    // Returns 3 segment fills (seed→sprout, sprout→bloom, bloom→fruit) as percentages
    const segs = [
      { from: 0, to: PHASE_THRESHOLDS.sprout },
      { from: PHASE_THRESHOLDS.sprout, to: PHASE_THRESHOLDS.bloom },
      { from: PHASE_THRESHOLDS.bloom, to: PHASE_THRESHOLDS.fruit },
    ];
    return segs.map((s) => {
      if (steps >= s.to) return 100;
      if (steps <= s.from) return 0;
      return Math.round(((steps - s.from) / (s.to - s.from)) * 100);
    });
  }

  function renderWidget(state) {
    const phase = state.phase || 'seed';
    const steps = state.total_steps || 0;
    const collected = Array.isArray(state.collected) ? state.collected : [];

    const phaseLabel = document.getElementById('walk-widget-phase-label');
    if (phaseLabel) {
      phaseLabel.textContent = `${PHASE_LABEL_ZH[phase] || phase} · ${steps.toLocaleString('zh-TW')} 步`;
    }
    const fills = fillForPhase(phase, steps);
    const fillEls = [
      document.getElementById('walk-phase-fill'),
      document.getElementById('walk-phase-fill-2'),
      document.getElementById('walk-phase-fill-3'),
    ];
    fillEls.forEach((el, i) => {
      if (el) el.style.width = `${fills[i]}%`;
    });

    const trainEl = document.getElementById('walk-mini-dodos');
    if (trainEl) {
      if (collected.length === 0) {
        trainEl.innerHTML =
          '<span class="text-xs text-muted">還沒收集到 mini-dodo · 記一餐就會跑出來囉</span>';
      } else {
        // dedupe by color so train shows up to 5 distinct mini-dodos
        const seen = new Set();
        const distinct = [];
        for (const c of collected) {
          if (seen.has(c.color)) continue;
          seen.add(c.color);
          distinct.push(c);
        }
        trainEl.innerHTML = distinct
          .map((c) => {
            const label = COLOR_LABEL[c.color] || c.color;
            return (
              `<span class="mini-dodo mini-dodo-${c.color}" title="${label}" aria-label="${label}">${miniDodoSvg(c.color)}</span>`
            );
          })
          .join('');
      }
    }

    const hint = document.getElementById('walk-widget-hint');
    if (hint) {
      const hintText = {
        seed: '今天剛起步，慢慢來 🌱 點我看朵朵的探險日記 →',
        sprout: '小芽冒出來了，繼續走一段吧 🌿 →',
        bloom: '花開了！步數已過半 🌸 →',
        fruit: '結果啦！今天的目標達成 🎉 →',
      };
      hint.textContent = hintText[phase] || '點我看探險日記 →';
    }
  }

  async function syncSteps() {
    const native = await readNativeStepsToday();
    if (typeof native === 'number' && native >= 0) {
      const r = await apiPost('/api/walk/sync', { total_steps: Math.floor(native) });
      if (r && r.data) return r.data;
    }
    // No native plugin available — use whatever today() returns.
    return null;
  }

  async function refresh() {
    // Try native sync first; either way, fetch today afterwards for canonical state.
    await syncSteps();
    const today = await apiGet('/api/walk/today');
    if (today && today.data) renderWidget(today.data);
  }

  function showDiary() {
    // Lightweight modal — full screen on mobile. Loads /api/walk/diary on demand.
    const existing = document.getElementById('walk-diary-overlay');
    if (existing) existing.remove();
    const overlay = document.createElement('div');
    overlay.id = 'walk-diary-overlay';
    overlay.className = 'fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40';
    overlay.innerHTML = `
      <div class="card w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl rounded-b-none p-5"
           style="background:linear-gradient(180deg,#FFF9F2,#FFE9D2); max-height:80vh; overflow:auto;">
        <div class="flex items-center justify-between mb-3">
          <div class="text-base font-bold">🌸 朵朵探險日記</div>
          <button class="chip" id="walk-diary-close" type="button">關閉</button>
        </div>
        <div id="walk-diary-body" class="space-y-2 text-sm">
          <div class="text-muted">朵朵正在整理今天的旁白…</div>
        </div>
      </div>
    `;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
    const closeBtn = document.getElementById('walk-diary-close');
    if (closeBtn) closeBtn.addEventListener('click', () => overlay.remove());

    apiGet('/api/walk/diary').then((resp) => {
      const body = document.getElementById('walk-diary-body');
      if (!body) return;
      const data = resp && resp.data;
      if (!data) {
        body.innerHTML = '<div class="text-muted">日記讀不到，等一下再試 ✨</div>';
        return;
      }
      const n = data.narrative || {};
      const lines = Array.isArray(n.lines) ? n.lines : [];
      const colors = (data.payload && data.payload.colors_collected) || [];
      const colorChips = colors
        .map((c) => `<span class="chip mini-dodo-chip mini-dodo-chip-${c}">${COLOR_LABEL[c] || c}</span>`)
        .join(' ');
      body.innerHTML = `
        <div class="text-lg font-bold">${escapeHtml(n.headline || '今天的探險')}</div>
        <div class="space-y-1">${lines.map((l) => `<div>${escapeHtml(l)}</div>`).join('')}</div>
        <div class="flex flex-wrap gap-1 mt-3">${colorChips}</div>
        <div class="text-[11px] text-muted mt-3">朵朵 · 導師日記</div>
      `;
    });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function init() {
    const widget = document.querySelector('[data-walk-widget]');
    if (!widget) return; // page does not host walk widget — bail
    widget.addEventListener('click', showDiary);
    refresh();
    // Re-refresh when user returns to home tab. app.js fires 'tab:changed' historically.
    document.addEventListener('tab:changed', (e) => {
      const detail = e.detail || {};
      if (detail.tab === 'home') refresh();
    });
    // Also refresh when window becomes visible (Capacitor app resume).
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refresh();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose hook for app.js to trigger refresh after a meal log.
  window.PandoraWalk = { refresh, showDiary };
})();
