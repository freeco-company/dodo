/**
 * SPEC-daily-login-streak + SPEC-streak-milestone-rewards — frontend toast.
 *
 * Fetches GET /api/streak/today on app boot. If `is_first_today` is true,
 * shows a small toast「連續第 N 天」. If `is_milestone` is also true, shows a
 * richer reveal with 朵朵 SVG + unlocked outfit/card thumbnails.
 *
 * Special "彩蛋" overlay variant at streak 21 / 100 — fullscreen modal with
 * tap-to-dismiss instead of auto-fading toast.
 *
 * Tone: 朵朵 = 導師（妳/朋友）— never「您/會員」.
 *
 * Usage: included in index.html; tryResume() calls window.dodoStreakToast.run().
 *
 * Design notes:
 *   - Pure vanilla; no module/import (matches app.js style).
 *   - Reuses window.api (defined in app.js) for auth + envelope handling.
 *   - Uses #toast-root container that already exists in index.html.
 *   - Asia/Taipei date display — backend returns `today_date` in TPE.
 */
(function () {
  'use strict';

  const TOAST_ROOT_ID = 'toast-root';
  const SHORT_MS = 3000;
  const MILESTONE_MS = 5000;
  // Streak values that trigger the fullscreen 彩蛋 overlay (vs the inline toast).
  const OVERLAY_STREAKS = new Set([21, 100]);

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
        max-width: 92vw;
      }
      .streak-toast--milestone {
        background: linear-gradient(135deg, #ff7a59 0%, #ff4d6d 100%);
        padding: 14px 20px; border-radius: 22px;
        animation: streakToastIn 320ms ease-out, streakToastMilestoneOut 380ms ease-in forwards;
        animation-delay: 0s, 4.6s; /* longer hold */
      }
      .streak-toast__inner { display: flex; align-items: center; gap: 12px; }
      .streak-toast__dodo {
        display: inline-flex; width: 56px; height: 56px;
        animation: dodoFlyIn 520ms cubic-bezier(.2,.9,.3,1.4) both;
      }
      .streak-toast__dodo img { width: 100%; height: 100%; object-fit: contain; }
      .streak-toast__title { font-weight: 700; font-size: 15px; }
      .streak-toast__sub   { opacity: 0.92; font-size: 13px; margin-top: 2px; }
      .streak-toast__unlocks {
        display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;
      }
      .streak-toast__chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,0.18);
        padding: 4px 8px; border-radius: 12px; font-size: 12px;
        animation: chipReveal 600ms ease-out both;
        animation-delay: 360ms;
      }
      .streak-toast__chip img {
        width: 18px; height: 18px;
        filter: drop-shadow(0 0 2px rgba(255,255,255,0.6));
      }
      .streak-toast__chip--card { background: rgba(255,255,255,0.28); }

      /* Fullscreen 彩蛋 overlay — streak 21 / 100 */
      .streak-overlay {
        position: fixed; inset: 0; z-index: 10000;
        background: radial-gradient(circle at 50% 35%, rgba(255,210,180,0.96), rgba(60,30,80,0.94) 70%);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 24px; text-align: center;
        animation: overlayIn 420ms ease-out both;
        cursor: pointer;
        color: #fff;
      }
      .streak-overlay__dodo {
        width: 180px; height: 180px;
        animation: dodoFlyIn 720ms cubic-bezier(.2,.9,.3,1.4) both;
        filter: drop-shadow(0 12px 40px rgba(255,180,120,0.55));
      }
      .streak-overlay__dodo img { width: 100%; height: 100%; object-fit: contain; }
      .streak-overlay__title {
        font-size: 24px; font-weight: 800; margin-top: 18px; letter-spacing: 1px;
        animation: chipReveal 600ms ease-out 240ms both;
      }
      .streak-overlay__sub {
        font-size: 15px; margin-top: 10px; max-width: 320px; line-height: 1.6;
        animation: chipReveal 600ms ease-out 420ms both;
      }
      .streak-overlay__unlocks {
        display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; justify-content: center;
        animation: chipReveal 600ms ease-out 600ms both;
      }
      .streak-overlay__chip {
        background: rgba(255,255,255,0.22);
        padding: 8px 14px; border-radius: 16px; font-size: 13px;
        display: inline-flex; align-items: center; gap: 8px;
      }
      .streak-overlay__chip img { width: 24px; height: 24px; }
      .streak-overlay__hint {
        margin-top: 28px; font-size: 12px; opacity: 0.72;
        animation: chipReveal 600ms ease-out 900ms both;
      }
      .streak-overlay__glitter::before, .streak-overlay__glitter::after {
        content: ''; position: absolute; pointer-events: none;
        width: 6px; height: 6px; border-radius: 50%;
        background: rgba(255,255,200,0.9);
        box-shadow:
          12vw 18vh #fff, 28vw 30vh rgba(255,200,160,0.9),
          70vw 22vh #fff, 84vw 38vh rgba(255,220,180,0.9),
          18vw 70vh rgba(255,210,170,0.9), 76vw 78vh #fff,
          50vw 12vh #fff, 60vw 60vh rgba(255,200,160,0.8);
        animation: glitter 1500ms ease-out infinite;
      }
      .streak-overlay__glitter::after { animation-delay: 700ms; opacity: 0.6; }

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
      @keyframes dodoFlyIn {
        0%   { opacity: 0; transform: translateY(20px) scale(0.6); }
        60%  { opacity: 1; transform: translateY(-4px) scale(1.08); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
      }
      @keyframes chipReveal {
        0%   { opacity: 0; transform: scale(0.4); }
        70%  { opacity: 1; transform: scale(1.1); }
        100% { opacity: 1; transform: scale(1); }
      }
      @keyframes overlayIn {
        from { opacity: 0; }
        to   { opacity: 1; }
      }
      @keyframes glitter {
        0%   { opacity: 0; transform: translateY(0); }
        50%  { opacity: 1; }
        100% { opacity: 0; transform: translateY(-12px); }
      }
    `;
    const style = document.createElement('style');
    style.id = 'streak-toast-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  /** 朵朵 portrait <img> with emoji fallback on 404. */
  function dodoImgHtml(size) {
    return '<img src="/characters/dodo-portrait.png" alt="" width="' + size + '" height="' + size
      + '" onerror="this.replaceWith(document.createTextNode(\'🐦\'));this.style.fontSize=\'' + Math.floor(size * 0.6) + 'px\'" />';
  }

  /** Build chip(s) for unlocked outfits/cards inside an inline toast. */
  function unlocksChipsHtml(unlocks, isOverlay) {
    if (!unlocks) return '';
    const wrapClass = isOverlay ? 'streak-overlay__unlocks' : 'streak-toast__unlocks';
    const chipClass = isOverlay ? 'streak-overlay__chip' : 'streak-toast__chip';
    const chips = [];

    if (unlocks.outfit_unlocked) {
      const code = String(unlocks.outfit_unlocked);
      const safeCode = code.replace(/[^a-z0-9_]/gi, '');
      chips.push(
        '<span class="' + chipClass + '">'
          + '<img src="/outfits/outfit_' + safeCode + '.svg" alt="" '
          + 'onerror="this.style.display=\'none\'" />'
          + '解鎖造型 ✨'
          + '</span>'
      );
    }

    const cards = Array.isArray(unlocks.cards_unlocked) ? unlocks.cards_unlocked : [];
    cards.forEach(function (c) {
      if (!c || !c.label) return;
      chips.push(
        '<span class="' + chipClass + ' streak-toast__chip--card">'
          + '🃏 ' + escapeHtml(c.label)
          + '</span>'
      );
    });

    if (unlocks.xp_bonus && unlocks.xp_bonus > 0) {
      chips.push('<span class="' + chipClass + '">⚡ +' + Number(unlocks.xp_bonus) + ' XP</span>');
    }

    if (chips.length === 0) return '';
    return '<div class="' + wrapClass + '">' + chips.join('') + '</div>';
  }

  /** Build the inline milestone toast DOM. */
  function buildToastEl({ streak, isMilestone, milestoneLabel, unlocks }) {
    const el = document.createElement('div');
    el.className = 'streak-toast' + (isMilestone ? ' streak-toast--milestone' : '');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');

    const inner = document.createElement('div');
    inner.className = 'streak-toast__inner';

    if (isMilestone) {
      const dodo = document.createElement('span');
      dodo.className = 'streak-toast__dodo';
      dodo.innerHTML = dodoImgHtml(56);
      inner.appendChild(dodo);

      const text = document.createElement('div');
      text.className = 'streak-toast__text';
      const title = escapeHtml(milestoneLabel || '里程碑');
      text.innerHTML =
        '<div class="streak-toast__title">' + title + ' 🔥</div>'
        + '<div class="streak-toast__sub">妳已經連續 ' + streak + ' 天了，朋友！</div>'
        + unlocksChipsHtml(unlocks, false);
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

  /** Build the fullscreen 彩蛋 overlay (streak 21 / 100). */
  function buildOverlayEl({ streak, milestoneLabel, unlocks }) {
    const el = document.createElement('div');
    el.className = 'streak-overlay streak-overlay__glitter';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', milestoneLabel || ('連續 ' + streak + ' 天'));

    let copy;
    if (streak === 21) {
      copy = '21 天的習慣養成完成！妳已經是潘朵拉的好朋友了。';
    } else if (streak === 100) {
      copy = '100 天傳奇達成！朵朵見證了妳每一步，朋友。';
    } else {
      copy = '連續 ' + streak + ' 天，妳真的好棒！';
    }

    el.innerHTML =
      '<div class="streak-overlay__dodo">' + dodoImgHtml(180) + '</div>'
      + '<div class="streak-overlay__title">' + escapeHtml(milestoneLabel || ('連續 ' + streak + ' 天')) + '</div>'
      + '<div class="streak-overlay__sub">' + escapeHtml(copy) + '</div>'
      + unlocksChipsHtml(unlocks, true)
      + '<div class="streak-overlay__hint">點任意處關閉</div>';

    el.addEventListener('click', function () {
      el.style.transition = 'opacity 280ms ease-in';
      el.style.opacity = '0';
      setTimeout(function () {
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }, 320);
    });

    return el;
  }

  function show({ streak, isMilestone, milestoneLabel, unlocks }) {
    ensureStyles();
    const root = ensureRoot();

    if (isMilestone && OVERLAY_STREAKS.has(Number(streak))) {
      const el = buildOverlayEl({ streak: streak, milestoneLabel: milestoneLabel, unlocks: unlocks });
      document.body.appendChild(el);
      return;
    }

    const el = buildToastEl({
      streak: streak,
      isMilestone: isMilestone,
      milestoneLabel: milestoneLabel,
      unlocks: unlocks,
    });
    root.appendChild(el);

    // Hold longer when there are unlocks worth reading.
    const hasUnlocks = unlocks && (
      unlocks.outfit_unlocked
      || (Array.isArray(unlocks.cards_unlocked) && unlocks.cards_unlocked.length > 0)
      || (unlocks.xp_bonus && unlocks.xp_bonus > 0)
    );
    const ttl = isMilestone ? (hasUnlocks ? MILESTONE_MS : MILESTONE_MS) : SHORT_MS;
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
      unlocks: resp.unlocks || null,
    });
  }

  window.dodoStreakToast = { run: run, show: show };
})();
