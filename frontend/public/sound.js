// Doudou sound FX — Japanese kawaii game style via Web Audio API.
// Zero audio files. Uses pentatonic scales + soft sine/triangle waves.
//
// Inspired by: Animal Crossing UI pops, Mario coin rises, FF menu blips,
//              Pokemon crit-heal chimes, music-box kirakira ✨
//
// Usage:
//   sfx.play('correct')     // pentatonic rising chime
//   sfx.play('ui_tap')      // Animal-Crossing-style soft pop
//   sfx.mute(true)          // silence all
//   sfx.toggle()            // flip mute
//   sfx.isMuted()
//
// All methods are safe to call before user interaction; audio stays
// suspended until first tap (iOS/Safari unlock), at which point it plays.

(function () {
  const LS_KEY = 'doudou_sfx_muted';
  let ctx = null;
  let master = null;
  let muted = localStorage.getItem(LS_KEY) === '1';
  let unlocked = false;
  let ambientBus = null;
  let ambientNodes = [];

  function ensureCtx() {
    if (ctx) return ctx;
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    ctx = new AC();
    master = ctx.createGain();
    master.gain.value = 0.85; // bumped from 0.5 — user wanted louder
    master.connect(ctx.destination);
    ambientBus = ctx.createGain();
    ambientBus.gain.value = 0.0;
    ambientBus.connect(master);
    return ctx;
  }

  function unlock() {
    if (unlocked) return;
    const c = ensureCtx();
    if (!c) return;
    if (c.state === 'suspended') c.resume();
    unlocked = true;
  }

  // Listen for first user gesture to unlock (iOS/Safari requirement).
  function installUnlock() {
    const handler = () => {
      unlock();
      window.removeEventListener('touchstart', handler);
      window.removeEventListener('pointerdown', handler);
      window.removeEventListener('keydown', handler);
    };
    window.addEventListener('touchstart', handler, { once: true, passive: true });
    window.addEventListener('pointerdown', handler, { once: true });
    window.addEventListener('keydown', handler, { once: true });
  }
  installUnlock();

  // --- Synthesis helpers ---

  function now() { return ctx.currentTime; }

  // Single tone with shaped envelope (attack + decay).
  function tone({ freq, dur = 0.12, type = 'sine', gain = 0.2, attack = 0.005, decay, detune = 0, delay = 0, filter = null }) {
    if (!ctx) return;
    const t0 = now() + delay;
    const osc = ctx.createOscillator();
    const g = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t0);
    if (detune) osc.detune.value = detune;
    const d = decay != null ? decay : dur;
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(gain, t0 + attack);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + attack + d);
    let node = osc;
    if (filter) {
      const f = ctx.createBiquadFilter();
      f.type = filter.type || 'lowpass';
      f.frequency.value = filter.freq || 2000;
      f.Q.value = filter.q || 1;
      osc.connect(f);
      node = f;
    }
    node.connect(g);
    g.connect(master);
    osc.start(t0);
    osc.stop(t0 + attack + d + 0.05);
  }

  // Frequency glide (pitch bend) — useful for wooshes and "bu-bun".
  function glide({ freqStart, freqEnd, dur = 0.2, type = 'sine', gain = 0.2, delay = 0 }) {
    if (!ctx) return;
    const t0 = now() + delay;
    const osc = ctx.createOscillator();
    const g = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freqStart, t0);
    osc.frequency.exponentialRampToValueAtTime(Math.max(1, freqEnd), t0 + dur);
    g.gain.setValueAtTime(0, t0);
    g.gain.linearRampToValueAtTime(gain, t0 + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    osc.connect(g);
    g.connect(master);
    osc.start(t0);
    osc.stop(t0 + dur + 0.05);
  }

  // Short filtered noise burst — card flip / paper / woosh.
  function noise({ dur = 0.08, gain = 0.12, filterFreq = 2000, filterType = 'bandpass', q = 2, delay = 0 }) {
    if (!ctx) return;
    const t0 = now() + delay;
    const buf = ctx.createBuffer(1, Math.floor(ctx.sampleRate * dur), ctx.sampleRate);
    const data = buf.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = (Math.random() * 2 - 1) * 0.8;
    const src = ctx.createBufferSource();
    src.buffer = buf;
    const f = ctx.createBiquadFilter();
    f.type = filterType;
    f.frequency.value = filterFreq;
    f.Q.value = q;
    const g = ctx.createGain();
    g.gain.setValueAtTime(gain, t0);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    src.connect(f); f.connect(g); g.connect(master);
    src.start(t0);
    src.stop(t0 + dur + 0.02);
  }

  // Japanese pentatonic scale (A minor pentatonic, warm):
  // A4=440, C5=523.25, D5=587.33, E5=659.25, G5=783.99, A5=880
  // C6=1046.50, D6=1174.66, E6=1318.51, G6=1567.98
  const PENTA = {
    A4: 440, C5: 523.25, D5: 587.33, E5: 659.25, G5: 783.99,
    A5: 880, C6: 1046.50, D6: 1174.66, E6: 1318.51, G6: 1567.98,
    A6: 1760, C7: 2093,
  };

  // --- Named FX ---

  const FX = {
    // Soft Animal-Crossing-style pop for UI taps.
    ui_tap() {
      tone({ freq: 880, dur: 0.06, type: 'sine', gain: 0.12, decay: 0.05 });
      tone({ freq: 660, dur: 0.05, type: 'sine', gain: 0.08, delay: 0.015, decay: 0.04 });
    },

    // Menu / modal open — gentle "pop-whoo" rising.
    ui_open() {
      glide({ freqStart: 440, freqEnd: 880, dur: 0.12, type: 'triangle', gain: 0.14 });
      tone({ freq: PENTA.E6, dur: 0.12, gain: 0.08, delay: 0.08, decay: 0.1 });
    },

    ui_close() {
      glide({ freqStart: 880, freqEnd: 440, dur: 0.12, type: 'triangle', gain: 0.12 });
    },

    // Card whoosh — paper flip with rising tail.
    card_draw() {
      // layered woosh: body + air
      noise({ dur: 0.28, gain: 0.18, filterFreq: 900, filterType: 'bandpass', q: 1.3 });
      noise({ dur: 0.22, gain: 0.1, filterFreq: 2400, filterType: 'highpass', q: 0.8, delay: 0.04 });
      // rising pitch like card approaching
      glide({ freqStart: 220, freqEnd: 880, dur: 0.28, type: 'sine', gain: 0.16 });
      // sparkle tail as it lands
      tone({ freq: PENTA.E6, dur: 0.22, type: 'sine', gain: 0.12, delay: 0.25, decay: 0.2 });
      tone({ freq: PENTA.G6, dur: 0.18, type: 'sine', gain: 0.08, delay: 0.3, decay: 0.15 });
    },

    // Card flips to reveal face — sharp "ka" + soft chime.
    card_flip() {
      noise({ dur: 0.06, gain: 0.2, filterFreq: 2800, filterType: 'bandpass', q: 2.5 });
      tone({ freq: PENTA.G5, dur: 0.12, type: 'triangle', gain: 0.2, decay: 0.1 });
      tone({ freq: PENTA.D6, dur: 0.18, type: 'sine', gain: 0.1, delay: 0.04, decay: 0.15 });
    },

    // Choice card hovered/selected — tiny tick.
    choice_hover() {
      tone({ freq: PENTA.D6, dur: 0.04, type: 'sine', gain: 0.08, decay: 0.03 });
    },

    // Choice committed — "pi".
    choice_select() {
      tone({ freq: PENTA.E6, dur: 0.08, type: 'triangle', gain: 0.16, decay: 0.07 });
      tone({ freq: PENTA.C6, dur: 0.06, type: 'sine', gain: 0.1, delay: 0.03, decay: 0.05 });
    },

    // Correct! Pentatonic rising triad (Mario-ish 正解 ♪).
    correct() {
      const notes = [PENTA.C6, PENTA.E6, PENTA.G6];
      notes.forEach((f, i) => {
        tone({ freq: f, dur: 0.16, type: 'sine', gain: 0.28, delay: i * 0.08, decay: 0.14 });
        // Tiny harmonic sparkle
        tone({ freq: f * 2, dur: 0.1, type: 'sine', gain: 0.08, delay: i * 0.08 + 0.01, decay: 0.09 });
      });
      // Ending shimmer
      tone({ freq: PENTA.C7, dur: 0.35, type: 'sine', gain: 0.14, delay: 0.28, decay: 0.32 });
    },

    // Wrong — soft descending "bu-bun", not harsh.
    wrong() {
      glide({ freqStart: 330, freqEnd: 180, dur: 0.24, type: 'triangle', gain: 0.28 });
      tone({ freq: 165, dur: 0.2, type: 'sine', gain: 0.2, delay: 0.22, decay: 0.18 });
    },

    // Combo fanfare — bigger arpeggio + harmonic.
    combo() {
      const notes = [PENTA.C6, PENTA.E6, PENTA.G6, PENTA.C7];
      notes.forEach((f, i) => {
        tone({ freq: f, dur: 0.18, type: 'triangle', gain: 0.18, delay: i * 0.06, decay: 0.15 });
        tone({ freq: f * 1.5, dur: 0.1, type: 'sine', gain: 0.06, delay: i * 0.06, decay: 0.09 });
      });
      // Held chord
      tone({ freq: PENTA.G6, dur: 0.5, type: 'sine', gain: 0.08, delay: 0.3, decay: 0.45 });
    },

    // XP earned — crystal bell (kira).
    xp() {
      tone({ freq: PENTA.G6, dur: 0.25, type: 'sine', gain: 0.22, decay: 0.22 });
      tone({ freq: PENTA.C7, dur: 0.2, type: 'sine', gain: 0.13, delay: 0.03, decay: 0.17 });
      tone({ freq: PENTA.E6, dur: 0.12, type: 'sine', gain: 0.1, delay: 0.07, decay: 0.1 });
    },

    // Level up — short RPG fanfare (C-E-G-C + hold).
    level_up() {
      const seq = [
        { f: PENTA.C6, t: 0 }, { f: PENTA.E6, t: 0.1 },
        { f: PENTA.G6, t: 0.2 }, { f: PENTA.C7, t: 0.3 },
      ];
      seq.forEach(({ f, t }) => {
        tone({ freq: f, dur: 0.18, type: 'triangle', gain: 0.2, delay: t, decay: 0.16 });
        tone({ freq: f * 2, dur: 0.14, type: 'sine', gain: 0.06, delay: t + 0.01, decay: 0.12 });
      });
      // Final held chord
      tone({ freq: PENTA.C6, dur: 0.7, type: 'triangle', gain: 0.14, delay: 0.45, decay: 0.6 });
      tone({ freq: PENTA.E6, dur: 0.7, type: 'triangle', gain: 0.12, delay: 0.45, decay: 0.6 });
      tone({ freq: PENTA.G6, dur: 0.7, type: 'triangle', gain: 0.1, delay: 0.45, decay: 0.6 });
    },

    // Legendary card reveal — kira kira shimmer.
    legendary() {
      // Rising sparkle staircase
      const sparkles = [PENTA.E6, PENTA.G6, PENTA.A6, PENTA.C7, PENTA.E6 * 2];
      sparkles.forEach((f, i) => {
        tone({ freq: f, dur: 0.12, type: 'sine', gain: 0.13, delay: i * 0.05, decay: 0.11 });
      });
      // Warm pad underneath
      tone({ freq: PENTA.C5, dur: 0.6, type: 'triangle', gain: 0.08, decay: 0.55 });
    },

    // Achievement unlock — satisfying "dum tss".
    achievement() {
      tone({ freq: PENTA.G5, dur: 0.1, type: 'triangle', gain: 0.2, decay: 0.08 });
      tone({ freq: PENTA.C6, dur: 0.3, type: 'sine', gain: 0.15, delay: 0.1, decay: 0.26 });
      tone({ freq: PENTA.E6, dur: 0.3, type: 'sine', gain: 0.12, delay: 0.1, decay: 0.26 });
      noise({ dur: 0.1, gain: 0.06, filterFreq: 6000, filterType: 'highpass', q: 1, delay: 0.1 });
    },

    // Meal logged — cheerful pentatonic "piko pon".
    meal_logged() {
      tone({ freq: PENTA.E6, dur: 0.1, type: 'triangle', gain: 0.16, decay: 0.08 });
      tone({ freq: PENTA.G6, dur: 0.12, type: 'triangle', gain: 0.14, delay: 0.08, decay: 0.1 });
    },

    // Toast / notification — single soft bell.
    notify() {
      tone({ freq: PENTA.A5, dur: 0.18, type: 'sine', gain: 0.12, decay: 0.15 });
    },

    // Pandora box opening — grand magical reveal.
    // Creaking lift (low noise swell) + rising chord + shimmering bells.
    box_open() {
      // Low creak / lift whoosh
      noise({ dur: 0.5, gain: 0.18, filterFreq: 400, filterType: 'lowpass', q: 1 });
      glide({ freqStart: 80, freqEnd: 260, dur: 0.6, type: 'triangle', gain: 0.2 });
      // Rising pentatonic arpeggio (lid swings open)
      const arp = [PENTA.C5, PENTA.E5, PENTA.G5, PENTA.C6, PENTA.E6];
      arp.forEach((f, i) => {
        tone({ freq: f, dur: 0.32, type: 'sine', gain: 0.2, delay: 0.18 + i * 0.08, decay: 0.28 });
        tone({ freq: f * 2, dur: 0.22, type: 'sine', gain: 0.08, delay: 0.18 + i * 0.08 + 0.01, decay: 0.2 });
      });
      // Big held chord (C + E + G) — the light pours out
      tone({ freq: PENTA.C5, dur: 1.2, type: 'triangle', gain: 0.16, delay: 0.6, decay: 1.0 });
      tone({ freq: PENTA.E5, dur: 1.2, type: 'triangle', gain: 0.13, delay: 0.62, decay: 1.0 });
      tone({ freq: PENTA.G5, dur: 1.2, type: 'triangle', gain: 0.1, delay: 0.64, decay: 1.0 });
      // High sparkle tail
      tone({ freq: PENTA.C7, dur: 0.6, type: 'sine', gain: 0.08, delay: 0.9, decay: 0.55 });
      tone({ freq: PENTA.E6 * 2, dur: 0.5, type: 'sine', gain: 0.06, delay: 1.0, decay: 0.45 });
    },

    // Pet / pat character — cute soft "nyu" poke.
    pet() {
      tone({ freq: PENTA.E6, dur: 0.06, type: 'sine', gain: 0.16, decay: 0.05 });
      tone({ freq: PENTA.G6, dur: 0.08, type: 'sine', gain: 0.12, delay: 0.04, decay: 0.06 });
    },
  };

  function play(name) {
    if (muted) return;
    ensureCtx();
    const fn = FX[name];
    if (!fn) return;
    try { fn(); } catch (e) { /* ignore */ }
  }

  // --- Ambient BGM layer ---
  // "Shrine garden" style: ultra-low warm drone + occasional sparse wind-chime notes.
  // Sine waves only (no harmonics), notes kept in lower register to avoid ear fatigue,
  // heavy low-pass filter, gain barely audible — meant to feel like distant air, not music.

  const AMBIENT_PRESETS = {
    card_modal: {
      // Drone at C3 (130Hz) with fifth C+G — warm cushion, no highs
      droneFreqs: [130.81, 196.00], // C3, G3
      droneGain: 0.06,
      // Rare pentatonic chimes (C major pentatonic, kept at C5 max)
      chimeNotes: [261.63, 329.63, 392.00, 523.25], // C4, E4, G4, C5
      chimeGain: 0.05,
      chimeIntervalMs: [5500, 9000], // random between 5.5-9s
      filterFreq: 600, // heavy low-pass
    },
    event: {
      // Slightly more movement but still very soft
      droneFreqs: [146.83, 220.00], // D3, A3 — minor-ish, gentle tension
      droneGain: 0.07,
      chimeNotes: [293.66, 349.23, 440.00, 587.33], // D4, F4, A4, D5
      chimeGain: 0.06,
      chimeIntervalMs: [3500, 6500],
      filterFreq: 700,
    },
  };

  let chimeTimer = null;
  let chimeFilter = null;

  function scheduleNextChime(preset) {
    if (chimeTimer) clearTimeout(chimeTimer);
    const [lo, hi] = preset.chimeIntervalMs;
    const delay = lo + Math.random() * (hi - lo);
    chimeTimer = setTimeout(() => {
      if (!ctx || !ambientBus || !chimeFilter) return;
      if (muted) return;
      // Pick a random chime note and play it softly with long decay (music-box feel)
      const f = preset.chimeNotes[Math.floor(Math.random() * preset.chimeNotes.length)];
      const t0 = ctx.currentTime;
      const osc = ctx.createOscillator();
      const g = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = f;
      g.gain.setValueAtTime(0, t0);
      g.gain.linearRampToValueAtTime(preset.chimeGain, t0 + 0.04);
      // Long exponential decay — 3 seconds of gentle fade
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + 3.2);
      osc.connect(g);
      g.connect(chimeFilter);
      osc.start(t0);
      osc.stop(t0 + 3.4);
      scheduleNextChime(preset);
    }, delay);
    ambientNodes.push({ stop: () => clearTimeout(chimeTimer) });
  }

  function startAmbient(name = 'card_modal', fadeInSec = 1.2) {
    // Disabled — user feedback: ambient BGM was unpleasant. Keep the function
    // as a no-op so existing callers don't error.
    return;
    // eslint-disable-next-line no-unreachable
    if (muted) return;
    ensureCtx();
    stopAmbient(0); // stop any previous
    const preset = AMBIENT_PRESETS[name] || AMBIENT_PRESETS.card_modal;
    const t0 = now();

    // Heavy low-pass filter shared by drone + chimes
    const filter = ctx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = preset.filterFreq;
    filter.Q.value = 0.3; // no resonance — keep it soft
    filter.connect(ambientBus);
    chimeFilter = filter;

    // Very slow breathing LFO on drone gain (not filter — filter staying low-pass is safer)
    const breathLfo = ctx.createOscillator();
    const breathGain = ctx.createGain();
    breathLfo.frequency.value = 0.08; // ~12 sec cycle
    breathGain.gain.value = 0.3; // depth
    breathLfo.connect(breathGain);
    breathLfo.start(t0);
    ambientNodes.push(breathLfo);

    // Drone tones — pure sine at low register
    for (const f of preset.droneFreqs) {
      const osc = ctx.createOscillator();
      const g = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = f;
      g.gain.value = preset.droneGain / preset.droneFreqs.length;
      // Patch breath LFO onto gain for subtle swell
      breathGain.connect(g.gain);
      osc.connect(g);
      g.connect(filter);
      osc.start(t0);
      ambientNodes.push(osc);
    }

    // Start sparse chime loop
    scheduleNextChime(preset);

    // Fade in bus
    ambientBus.gain.cancelScheduledValues(t0);
    ambientBus.gain.setValueAtTime(0.0, t0);
    ambientBus.gain.linearRampToValueAtTime(1.0, t0 + fadeInSec);
  }

  function stopAmbient(fadeOutSec = 0.8) {
    if (!ctx || !ambientBus) return;
    const t0 = now();
    if (chimeTimer) { clearTimeout(chimeTimer); chimeTimer = null; }
    ambientBus.gain.cancelScheduledValues(t0);
    const cur = ambientBus.gain.value;
    ambientBus.gain.setValueAtTime(cur, t0);
    ambientBus.gain.linearRampToValueAtTime(0.0, t0 + Math.max(0.05, fadeOutSec));
    const stopAt = t0 + Math.max(0.05, fadeOutSec) + 0.1;
    for (const n of ambientNodes) {
      try { if (n.stop) n.stop(stopAt); } catch (e) { /* ignore */ }
    }
    setTimeout(() => { ambientNodes = []; chimeFilter = null; }, (fadeOutSec + 0.2) * 1000);
  }

  function mute(value) {
    muted = Boolean(value);
    localStorage.setItem(LS_KEY, muted ? '1' : '0');
    if (muted) stopAmbient(0.2);
    window.dispatchEvent(new CustomEvent('sfx:muted-changed', { detail: muted }));
  }

  function toggle() { mute(!muted); return muted; }
  function isMuted() { return muted; }

  window.sfx = { play, mute, toggle, isMuted, startAmbient, stopAmbient };
})();
