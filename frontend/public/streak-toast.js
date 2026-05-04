/**
 * SPEC-daily-login-streak — frontend toast.
 *
 * Fetches GET /api/streak/today on app boot. If `is_first_today` is true,
 * shows a small toast「連續第 N 天」. If `is_milestone` is also true,
 * shows a longer toast with 朵朵 SVG and a tutor-tone message.
 *
 * Usage: included in index.html; tryResume() calls window.dodoStreakToast.run().
 *
 * Design notes:
 *   - Pure vanilla; no module/import (matches app.js style).
 *   - Reuses window.api (defined in app.js) for auth + envelope handling.
 *   - Uses #toast-root container that already exists in index.html.
 *   - Asia/Taipei date display — backend already returns `today_date` in TPE.
 *   - 朵朵 = 導師 tone：「妳/朋友」not「您/會員」.
 */
(function () {
  'use strict';

  const TOAST_ROOT_ID = 'toast-root';
  const SHORT_MS = 3000;
  const LONG_MS = 5000;

  /** Build the toast DOM. */
  function buildEl({ streak, isMilestone, milestoneLabel }) {
    const el = document.createElement('div');
    el.className = 'streak-toast' + (isMilestone ? ' streak-toast--milestone' : '');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');

    const inner = document.createElement('div');
    inner.className = 'streak-toast__inner';

    if (isMilestone) {
      // 朵朵 SVG (loaded from pandora-design-svg if available; otherwise inline emoji-ish fallback)
      const dodo = document.createElement('span');
      dodo.className = 'streak-toast__dodo';
      // Best-effort: try to use a known SVG path. Fallback emoji on 404/no-asset.
      dodo.innerHTML = '<img src="/svg/dodo-mentor.svg" alt="" width="56" height="56" '
        + 'onerror="this.replaceWith(document.createTextNode(\'🐦\'));this.style.fontSize=\'36px\'" />';
      inner.appendChild(dodo);

      const text = document.createElement('div');
      text.className = 'streak-toast__text';
      text.innerHTML =
        '<div class="streak-toast__title">' + escapeHtml(milestoneLabel || '里程碑') + ' 🔥</div>'
        + '<div class="streak-toast__sub">妳已經連續 ' + streak + ' 天了，朋友！</div>';
      inner.appendChild(text);
    } else {
      const text = document.createElement('div');
      text.className = 'streak-toast__text';
      text.textContent = '連續第 ' + streak + ' 天 🔥';
      inner.appendChild(text);
    }

    el.appendChild(inner);
    return el;
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[c];
    });
  }

  function ensureRoot() {
    let root = document.getElementById(TOAST_ROOT_ID);
    if (!root) {
      root = document.createElement('div');
      root.id = TOAST_ROOT_ID;
      document.body.appendChild(root);
    }
    return root;
  }

  /** Inject minimal CSS once (keyframes + layout). */
  function ensureStyles() {
    if (document.getElementById('streak-toast-styles')) return;
    const css = `
      .streak-toast {
        position: fixed; left: 50%; top: 24px; transform: translateX(-50%);
        background: rgba(28,28,32,0.92); color: #fff;
        padding: 10px 16px; border-radius: 999px;
        font-size: 14px; line-height: 1.4; z-index: 9999;
        box-shadow: 0 6px 24px rgba(0,0,0,0.18);
        animation: streakToastIn 280ms ease-out, streakToastOut 320ms ease-in forwards;
      }
      .streak-toast--milestone {
        background: linear-gradient(135deg, #ff7a59 0%, #ff4d6d 100%);
        padding: 14px 20px; border-radius: 18px;
        animation: streakToastIn 320ms ease-out, streakToastMilestoneOut 380ms ease-in forwards;
        animation-delay: 0s, 4.6s; /* longer hold */
      }
      .streak-toast__inner { display: flex; align-items: center; gap: 12px; }
      .streak-toast__dodo { display: inline-flex; }
      .streak-toast__title { font-weight: 700; font-size: 15px; }
      .streak-toast__sub   { opacity: 0.92; font-size: 13px; margin-top: 2px; }
      @keyframes streakToastIn {
        from { opacity: 0; transform: translate(-50%, -12px); }
        to   { opacity: 1; transform: translate(-50%, 0); }
      }
      @keyframes streakToastOut {
        0%, 80% { opacity: 1; transform: translate(-50%, 0); }
        100%    { opacity: 0; transform: translate(-50%, -12px); }
      }
      @keyframes streakToastMilestoneOut {
        0%, 90% { opacity: 1; transform: translate(-50%, 0); }
        100%    { opacity: 0; transform: translate(-50%, -12px); }
      }
    `;
    const style = document.createElement('style');
    style.id = 'streak-toast-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  function show({ streak, isMilestone, milestoneLabel }) {
    ensureStyles();
    const root = ensureRoot();
    const el = buildEl({ streak: streak, isMilestone: isMilestone, milestoneLabel: milestoneLabel });
    root.appendChild(el);
    // Use animation-end as removal trigger; fall back to timeout for safety.
    const ttl = isMilestone ? LONG_MS : SHORT_MS;
    setTimeout(function () {
      if (el && el.parentNode) el.parentNode.removeChild(el);
    }, ttl + 600);
  }

  /**
   * Entry point — call after auth/session is established.
   * Idempotent within a session: backend's same-day no-op handles repeats.
   */
  async function run() {
    if (typeof window.api !== 'function') return;
    let resp;
    try {
      resp = await window.api('GET', '/streak/today');
    } catch (e) {
      // 401 or network — silently skip (auth handler in app.js will react).
      return;
    }
    if (!resp || !resp.is_first_today) return;
    show({
      streak: Number(resp.current_streak) || 0,
      isMilestone: !!resp.is_milestone,
      milestoneLabel: resp.milestone_label || null,
    });
  }

  window.dodoStreakToast = { run: run, show: show };
})();
