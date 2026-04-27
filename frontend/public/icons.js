// Doudou custom icon library — hand-drawn, colored, animated SVG.
// Matches the chubby/rounded animal mascot style with pastel palette.
// Usage: window.icon('water', { size: 40, cls: 'bounce' }) → SVG string

(function () {
  const P = {
    peach: '#F9C4B0', peachDeep: '#E89383', peachDark: '#D97260',
    sage: '#B8C9A0', sageDeep: '#84987A', sageDark: '#5A7A50',
    cream: '#FFF8F2', creamDark: '#FCE9E0',
    sakura: '#F5B5C5', sakuraDeep: '#E88BA0',
    gold: '#F4D78A', goldDeep: '#C9A961', goldDark: '#8A6820',
    sky: '#9AD3F5', skyDeep: '#6AB8E8', skyDark: '#3D85B0',
    purple: '#B89AD8', purpleDeep: '#9878C0',
    fire: '#F97A55', fireLight: '#FFB060', fireDark: '#D94525',
    ink: '#3A2A2A', inkLight: '#6B5248',
    grass: '#7AB87A', grassLight: '#A8D896', grassDark: '#4A7A4A',
    brown: '#8A5820', brownLight: '#C89878', brownDark: '#5A3818',
  };

  // Common SVG wrapper — sets viewBox, size, stroke defaults
  function svg(inner, { size = 40, viewBox = '0 0 40 40', cls = '' } = {}) {
    return `<svg class="doudou-icon ${cls}" viewBox="${viewBox}" width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="${P.ink}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;
  }

  const ICONS = {
    // ---- Care: water / exercise / scale / meal ----
    water: (opts) => svg(`
      <defs><linearGradient id="wg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${P.sky}"/><stop offset="1" stop-color="${P.skyDeep}"/></linearGradient></defs>
      <path d="M 20 4 C 13 14, 9 22, 9 28 A 11 11 0 0 0 31 28 C 31 22, 27 14, 20 4 Z" fill="url(#wg)" stroke="${P.skyDark}" stroke-width="1.8"/>
      <ellipse cx="16" cy="24" rx="3" ry="4" fill="#FFF" opacity=".55"/>
    `, opts),

    water_double: (opts) => svg(`
      <defs><linearGradient id="wg2" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${P.sky}"/><stop offset="1" stop-color="${P.skyDeep}"/></linearGradient></defs>
      <path d="M 12 6 C 8 13, 6 18, 6 22 A 7 7 0 0 0 20 22 C 20 18, 18 13, 12 6 Z" fill="url(#wg2)" stroke="${P.skyDark}" stroke-width="1.6"/>
      <path d="M 28 14 C 24 21, 22 26, 22 30 A 7 7 0 0 0 36 30 C 36 26, 34 21, 28 14 Z" fill="url(#wg2)" stroke="${P.skyDark}" stroke-width="1.6"/>
      <ellipse cx="10" cy="19" rx="2" ry="2.5" fill="#FFF" opacity=".55"/>
      <ellipse cx="26" cy="27" rx="2" ry="2.5" fill="#FFF" opacity=".55"/>
    `, opts),

    exercise: (opts) => svg(`
      <!-- Chubby runner with sweat droplets -->
      <circle cx="22" cy="9" r="4.5" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.6"/>
      <circle cx="21" cy="8.5" r=".8" fill="${P.ink}"/>
      <!-- body -->
      <path d="M 22 13 Q 18 14 17 20 Q 16 24 14 28" stroke="${P.peachDeep}" stroke-width="4" fill="none"/>
      <!-- arms -->
      <path d="M 18 16 L 11 22" stroke="${P.peachDeep}" stroke-width="3.5" fill="none"/>
      <path d="M 22 14 L 28 17 L 31 14" stroke="${P.peachDeep}" stroke-width="3.5" fill="none"/>
      <!-- legs -->
      <path d="M 18 22 L 14 32" stroke="${P.peachDeep}" stroke-width="4" fill="none"/>
      <path d="M 19 22 L 26 30 L 30 32" stroke="${P.peachDeep}" stroke-width="4" fill="none"/>
      <!-- sweat drops -->
      <circle cx="9" cy="14" r="1.5" fill="${P.sky}" opacity=".7"/>
      <circle cx="6" cy="10" r="1" fill="${P.sky}" opacity=".5"/>
    `, opts),

    scale: (opts) => svg(`
      <!-- Body -->
      <rect x="6" y="14" width="28" height="18" rx="4" fill="${P.sage}" stroke="${P.sageDark}" stroke-width="1.8"/>
      <!-- Dial -->
      <rect x="10" y="18" width="20" height="8" rx="2" fill="${P.cream}" stroke="${P.sageDark}" stroke-width="1.4"/>
      <line x1="14" y1="22" x2="26" y2="22" stroke="${P.sageDark}" stroke-width="1"/>
      <line x1="14" y1="20" x2="14" y2="24" stroke="${P.sageDark}" stroke-width="1"/>
      <line x1="20" y1="19" x2="20" y2="25" stroke="${P.peachDeep}" stroke-width="2"/>
      <line x1="26" y1="20" x2="26" y2="24" stroke="${P.sageDark}" stroke-width="1"/>
      <!-- Feet shadow -->
      <ellipse cx="20" cy="34" rx="14" ry="1.5" fill="${P.ink}" opacity=".2"/>
    `, opts),

    meal_box: (opts) => svg(`
      <!-- Bento box -->
      <rect x="5" y="12" width="30" height="22" rx="3" fill="${P.peachDark}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="8" y="15" width="24" height="14" rx="1" fill="${P.cream}"/>
      <!-- rice section -->
      <rect x="8" y="15" width="10" height="14" fill="${P.cream}"/>
      <circle cx="11" cy="19" r="1" fill="${P.creamDark}"/>
      <circle cx="14" cy="22" r="1" fill="${P.creamDark}"/>
      <!-- veggie -->
      <circle cx="22" cy="19" r="2" fill="${P.grass}"/>
      <circle cx="27" cy="21" r="1.5" fill="${P.fire}"/>
      <rect x="19" y="23" width="10" height="4" fill="${P.brownLight}" rx="1"/>
      <!-- Steam -->
      <path d="M 14 8 Q 13 5 15 3" stroke="${P.ink}" stroke-width="1.2" opacity=".5" fill="none"/>
      <path d="M 20 8 Q 19 5 21 3" stroke="${P.ink}" stroke-width="1.2" opacity=".5" fill="none"/>
    `, opts),

    // ---- Cards / Island / Camera ----
    card: (opts) => svg(`
      <!-- Two playing cards fanned -->
      <rect x="8" y="10" width="16" height="22" rx="2" fill="${P.purple}" stroke="${P.purpleDeep}" stroke-width="1.6" transform="rotate(-10 16 21)"/>
      <rect x="14" y="8" width="16" height="22" rx="2" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.6" transform="rotate(8 22 19)"/>
      <text x="22" y="22" font-size="10" text-anchor="middle" fill="${P.peachDark}" font-weight="800" stroke="none" transform="rotate(8 22 19)">✦</text>
    `, opts),

    island: (opts) => svg(`
      <!-- Desert island with palm -->
      <ellipse cx="20" cy="32" rx="16" ry="4" fill="${P.skyDeep}" opacity=".5"/>
      <ellipse cx="20" cy="30" rx="13" ry="3.5" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.4"/>
      <rect x="18.5" y="14" width="3" height="16" fill="${P.brown}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <path d="M 20 12 Q 10 9 8 14 Q 14 12 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.3"/>
      <path d="M 20 12 Q 30 9 32 14 Q 26 12 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.3"/>
      <path d="M 20 12 Q 20 4 26 4 Q 22 10 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.3"/>
      <circle cx="17" cy="14" r="1.2" fill="${P.peachDark}"/>
      <circle cx="22" cy="13" r="1" fill="${P.peachDark}"/>
    `, opts),

    camera: (opts) => svg(`
      <rect x="4" y="12" width="32" height="22" rx="3" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="14" y="7" width="12" height="7" rx="1" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.6"/>
      <circle cx="20" cy="23" r="7" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.6"/>
      <circle cx="20" cy="23" r="4" fill="${P.ink}"/>
      <circle cx="22" cy="21" r="1.2" fill="${P.cream}"/>
      <circle cx="31" cy="16" r="1.2" fill="${P.sakuraDeep}"/>
    `, opts),

    // ---- Smart action / Streak / Achievement ----
    fire: (opts) => svg(`
      <path d="M 20 4 Q 13 12, 13 20 Q 13 30, 20 34 Q 27 30, 27 20 Q 27 12, 20 4 Z" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.8"/>
      <path d="M 20 12 Q 16 18, 16 24 Q 16 30, 20 32 Q 24 30, 24 24 Q 24 18, 20 12 Z" fill="${P.fireLight}"/>
      <circle cx="20" cy="26" r="2" fill="${P.gold}" opacity=".6"/>
    `, opts),

    star: (opts) => svg(`
      <path d="M 20 4 L 24 15 L 36 16 L 27 24 L 30 35 L 20 29 L 10 35 L 13 24 L 4 16 L 16 15 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.6"/>
      <path d="M 20 10 L 22 18 L 30 19 L 24 23 L 26 31 L 20 27 L 14 31 L 16 23 L 10 19 L 18 18 Z" fill="${P.cream}" opacity=".4"/>
    `, opts),

    sparkle: (opts) => svg(`
      <path d="M 20 4 L 22 17 L 35 20 L 22 23 L 20 36 L 18 23 L 5 20 L 18 17 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.4"/>
      <circle cx="20" cy="20" r="3" fill="#FFF" opacity=".7"/>
    `, opts),

    target: (opts) => svg(`
      <circle cx="20" cy="20" r="14" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <circle cx="20" cy="20" r="10" fill="${P.cream}" stroke="${P.peachDark}" stroke-width="1.4"/>
      <circle cx="20" cy="20" r="6" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.2"/>
      <circle cx="20" cy="20" r="2" fill="#FFF"/>
    `, opts),

    // ---- Journey milestones ----
    sprout: (opts) => svg(`
      <ellipse cx="20" cy="33" rx="12" ry="2" fill="${P.brownDark}" opacity=".4"/>
      <path d="M 20 34 L 20 18" stroke="${P.grassDark}" stroke-width="2.5"/>
      <path d="M 20 22 Q 10 16, 8 7 Q 18 10, 20 22 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.6"/>
      <path d="M 20 22 Q 30 16, 32 7 Q 22 10, 20 22 Z" fill="${P.grassLight}" stroke="${P.grassDark}" stroke-width="1.6"/>
    `, opts),

    gem: (opts) => svg(`
      <path d="M 10 14 L 20 4 L 30 14 L 24 34 L 16 34 Z" fill="${P.purple}" stroke="${P.purpleDeep}" stroke-width="1.8"/>
      <path d="M 10 14 L 20 14 L 16 34 Z" fill="#FFF" opacity=".35"/>
      <path d="M 30 14 L 20 14 L 24 34 Z" fill="${P.purpleDeep}" opacity=".4"/>
      <path d="M 10 14 L 20 4 L 30 14 L 20 14 Z" fill="#FFF" opacity=".2"/>
    `, opts),

    crown: (opts) => svg(`
      <path d="M 5 28 L 9 10 L 14 22 L 20 6 L 26 22 L 31 10 L 35 28 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <rect x="5" y="28" width="30" height="6" fill="${P.goldDeep}" stroke="${P.goldDark}" stroke-width="1.6"/>
      <circle cx="9" cy="10" r="2.3" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.2"/>
      <circle cx="20" cy="6" r="2.3" fill="${P.grass}" stroke="${P.ink}" stroke-width="1.2"/>
      <circle cx="31" cy="10" r="2.3" fill="${P.sky}" stroke="${P.ink}" stroke-width="1.2"/>
    `, opts),

    // ---- Navigation / Overall ----
    home: (opts) => svg(`
      <path d="M 5 20 L 20 6 L 35 20 L 31 20 L 31 33 L 22 33 L 22 25 L 18 25 L 18 33 L 9 33 L 9 20 Z" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="17" y="25" width="6" height="8" fill="${P.brown}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <rect x="24" y="22" width="5" height="5" fill="${P.sky}" stroke="${P.skyDark}" stroke-width="1.2"/>
      <rect x="11" y="22" width="5" height="5" fill="${P.sky}" stroke="${P.skyDark}" stroke-width="1.2"/>
    `, opts),

    map: (opts) => svg(`
      <path d="M 4 10 L 14 6 L 26 10 L 36 6 L 36 30 L 26 34 L 14 30 L 4 34 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <line x1="14" y1="6" x2="14" y2="30" stroke="${P.goldDark}" stroke-width="1.2" opacity=".6"/>
      <line x1="26" y1="10" x2="26" y2="34" stroke="${P.goldDark}" stroke-width="1.2" opacity=".6"/>
      <path d="M 20 16 C 18 16 17 18 17 20 C 17 23 20 26 20 26 C 20 26 23 23 23 20 C 23 18 22 16 20 16 Z" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.2"/>
      <circle cx="20" cy="20" r="1.3" fill="${P.cream}"/>
    `, opts),

    heart: (opts) => svg(`
      <path d="M 20 34 Q 5 22 5 13 A 7 7 0 0 1 20 12 A 7 7 0 0 1 35 13 Q 35 22 20 34 Z" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.8"/>
      <ellipse cx="13" cy="14" rx="2.5" ry="3.5" fill="#FFF" opacity=".5"/>
    `, opts),

    medal: (opts) => svg(`
      <path d="M 12 4 L 16 18 L 24 18 L 28 4" stroke="${P.peachDeep}" stroke-width="3" fill="none" stroke-linecap="round"/>
      <circle cx="20" cy="25" r="10" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <circle cx="20" cy="25" r="6" fill="${P.goldDeep}" stroke="${P.goldDark}" stroke-width="1.2"/>
      <path d="M 17 25 L 19 27 L 23 22" stroke="${P.cream}" stroke-width="2" fill="none" stroke-linecap="round"/>
    `, opts),

    // ---- Shop buildings (simplified) ----
    shop: (opts) => svg(`
      <polygon points="4,18 20,6 36,18" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="6" y="18" width="28" height="16" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <rect x="9" y="22" width="5" height="4" fill="${P.sky}"/>
      <rect x="26" y="22" width="5" height="4" fill="${P.sky}"/>
    `, opts),

    bubble_tea: (opts) => svg(`
      <rect x="12" y="10" width="16" height="26" rx="2" fill="${P.brownLight}" stroke="${P.brownDark}" stroke-width="1.6"/>
      <rect x="14" y="12" width="12" height="6" fill="${P.creamDark}" opacity=".8"/>
      <circle cx="16" cy="28" r="1.8" fill="${P.ink}"/>
      <circle cx="20" cy="30" r="1.8" fill="${P.ink}"/>
      <circle cx="24" cy="28" r="1.8" fill="${P.ink}"/>
      <circle cx="18" cy="25" r="1.8" fill="${P.ink}"/>
      <circle cx="22" cy="25" r="1.8" fill="${P.ink}"/>
      <rect x="18.5" y="4" width="3" height="10" fill="${P.peachDark}" rx="1"/>
    `, opts),

    coffee: (opts) => svg(`
      <rect x="8" y="18" width="20" height="14" rx="2" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 28 22 Q 34 22 34 26 Q 34 30 28 30" stroke="${P.brownDark}" stroke-width="1.8" fill="none"/>
      <rect x="10" y="20" width="16" height="4" fill="${P.brownDark}"/>
      <path d="M 14 14 Q 13 10 15 8" stroke="${P.ink}" stroke-width="1.4" opacity=".5" fill="none"/>
      <path d="M 20 14 Q 19 10 21 8" stroke="${P.ink}" stroke-width="1.4" opacity=".5" fill="none"/>
    `, opts),

    chicken: (opts) => svg(`
      <path d="M 12 24 Q 10 14 18 10 Q 26 10 28 20 Q 30 30 22 33 Q 14 32 12 24 Z" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 14 22 Q 13 16 18 14" stroke="${P.goldDeep}" stroke-width="1.2" opacity=".6" fill="none"/>
      <rect x="17" y="4" width="4" height="9" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.4" rx="1"/>
      <ellipse cx="19" cy="5" rx="3" ry="2" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.4"/>
    `, opts),

    sushi: (opts) => svg(`
      <ellipse cx="20" cy="27" rx="15" ry="6" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <ellipse cx="20" cy="21" rx="13" ry="5.5" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.6"/>
      <rect x="6" y="19" width="28" height="2" fill="${P.grassDark}"/>
      <path d="M 12 21 Q 15 18 18 21" stroke="${P.cream}" stroke-width="1" fill="none" opacity=".5"/>
      <path d="M 22 21 Q 25 18 28 21" stroke="${P.cream}" stroke-width="1" fill="none" opacity=".5"/>
    `, opts),

    cart: (opts) => svg(`
      <circle cx="14" cy="32" r="3" fill="${P.ink}"/>
      <circle cx="28" cy="32" r="3" fill="${P.ink}"/>
      <path d="M 4 10 L 10 10 L 14 26 L 30 26 L 34 16 L 12 16" stroke="${P.peachDark}" stroke-width="2.2" fill="none" stroke-linejoin="round"/>
      <rect x="14" y="16" width="18" height="8" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.2"/>
      <line x1="20" y1="16" x2="20" y2="24" stroke="${P.peachDark}" stroke-width="1"/>
      <line x1="26" y1="16" x2="26" y2="24" stroke="${P.peachDark}" stroke-width="1"/>
    `, opts),

    lantern: (opts) => svg(`
      <line x1="20" y1="2" x2="20" y2="8" stroke="${P.brownDark}" stroke-width="1.6"/>
      <ellipse cx="20" cy="22" rx="12" ry="14" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.8"/>
      <rect x="8" y="8" width="24" height="2" fill="${P.brownDark}"/>
      <rect x="8" y="34" width="24" height="2" fill="${P.brownDark}"/>
      <line x1="14" y1="10" x2="14" y2="34" stroke="${P.fireDark}" stroke-width="1" opacity=".4"/>
      <line x1="26" y1="10" x2="26" y2="34" stroke="${P.fireDark}" stroke-width="1" opacity=".4"/>
      <rect x="17" y="36" width="6" height="2" fill="${P.gold}"/>
    `, opts),

    salad: (opts) => svg(`
      <ellipse cx="20" cy="26" rx="15" ry="7" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 24 Q 12 18 16 22 Q 18 26 14 28" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <path d="M 24 22 Q 28 16 32 22 Q 32 26 28 26" fill="${P.grassLight}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <circle cx="17" cy="22" r="2" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.2"/>
      <circle cx="22" cy="24" r="1.5" fill="${P.gold}"/>
      <circle cx="26" cy="28" r="1.5" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.2"/>
    `, opts),

    moon: (opts) => svg(`
      <path d="M 30 20 A 12 12 0 1 1 16 8 A 10 10 0 0 0 30 20 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <circle cx="22" cy="16" r="1.5" fill="${P.goldDeep}" opacity=".7"/>
      <circle cx="18" cy="24" r="1" fill="${P.goldDeep}" opacity=".6"/>
    `, opts),

    sun: (opts) => svg(`
      <circle cx="20" cy="20" r="7" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <g stroke="${P.goldDark}" stroke-width="2" stroke-linecap="round">
        <line x1="20" y1="4" x2="20" y2="8"/>
        <line x1="20" y1="32" x2="20" y2="36"/>
        <line x1="4" y1="20" x2="8" y2="20"/>
        <line x1="32" y1="20" x2="36" y2="20"/>
        <line x1="9" y1="9" x2="12" y2="12"/>
        <line x1="28" y1="28" x2="31" y2="31"/>
        <line x1="9" y1="31" x2="12" y2="28"/>
        <line x1="28" y1="12" x2="31" y2="9"/>
      </g>
    `, opts),

    // ---- Lock ----
    lock: (opts) => svg(`
      <path d="M 12 18 L 12 14 A 8 8 0 0 1 28 14 L 28 18" stroke="${P.ink}" stroke-width="2.5" fill="none"/>
      <rect x="8" y="18" width="24" height="16" rx="3" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <circle cx="20" cy="26" r="2.5" fill="${P.brownDark}"/>
      <rect x="18.5" y="26" width="3" height="5" fill="${P.brownDark}"/>
    `, opts),

    // ---- Plus button / Check / X ----
    plus: (opts) => svg(`
      <circle cx="20" cy="20" r="14" fill="${P.sage}" stroke="${P.sageDark}" stroke-width="1.8"/>
      <line x1="20" y1="12" x2="20" y2="28" stroke="${P.cream}" stroke-width="3" stroke-linecap="round"/>
      <line x1="12" y1="20" x2="28" y2="20" stroke="${P.cream}" stroke-width="3" stroke-linecap="round"/>
    `, opts),

    check: (opts) => svg(`
      <circle cx="20" cy="20" r="14" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.8"/>
      <path d="M 12 20 L 18 26 L 28 14" stroke="${P.cream}" stroke-width="3" stroke-linecap="round" fill="none"/>
    `, opts),

    // ---- Time of day ----
    sunset: (opts) => svg(`
      <circle cx="20" cy="22" r="8" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.6"/>
      <line x1="8" y1="28" x2="32" y2="28" stroke="${P.fireDark}" stroke-width="2" stroke-linecap="round"/>
      <line x1="6" y1="32" x2="34" y2="32" stroke="${P.fireLight}" stroke-width="1.5" stroke-linecap="round"/>
      <g stroke="${P.fireDark}" stroke-width="1.8" stroke-linecap="round">
        <line x1="20" y1="8" x2="20" y2="12"/>
        <line x1="10" y1="14" x2="12" y2="16"/>
        <line x1="28" y1="16" x2="30" y2="14"/>
      </g>
    `, opts),

    // ---- Events / bolts / gifts ----
    bolt: (opts) => svg(`
      <path d="M 22 4 L 10 22 L 18 22 L 14 36 L 30 16 L 22 16 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <path d="M 22 4 L 10 22 L 18 22 L 14 36" fill="${P.fire}" opacity=".5"/>
    `, opts),

    gift: (opts) => svg(`
      <rect x="6" y="14" width="28" height="22" rx="2" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <rect x="6" y="14" width="28" height="6" fill="${P.goldDeep}" stroke="${P.goldDark}" stroke-width="1.4"/>
      <rect x="18" y="14" width="4" height="22" fill="${P.sakuraDeep}"/>
      <rect x="4" y="12" width="32" height="5" rx="1" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.6"/>
      <path d="M 20 12 C 12 6 10 14 16 14 M 20 12 C 28 6 30 14 24 14" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.4"/>
    `, opts),

    // ---- Tab nav icons ----
    tab_home: (opts) => svg(`
      <path d="M 6 20 L 20 7 L 34 20 L 30 20 L 30 33 L 10 33 L 10 20 Z" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="17" y="25" width="6" height="8" fill="${P.brown}"/>
    `, opts),

    tab_island: (opts) => svg(`
      <ellipse cx="20" cy="30" rx="13" ry="3.5" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.4"/>
      <rect x="18.5" y="14" width="3" height="16" fill="${P.brown}"/>
      <path d="M 20 12 Q 11 9 9 14 Q 15 12 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.2"/>
      <path d="M 20 12 Q 29 9 31 14 Q 25 12 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.2"/>
      <path d="M 20 12 Q 20 5 25 5 Q 22 10 20 16 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.2"/>
    `, opts),

    tab_scan: (opts) => svg(`
      <rect x="5" y="13" width="30" height="21" rx="3" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="14" y="8" width="12" height="6" rx="1" fill="${P.peachDark}"/>
      <circle cx="20" cy="23" r="6" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="20" cy="23" r="3" fill="${P.ink}"/>
    `, opts),

    tab_cards: (opts) => svg(`
      <rect x="10" y="11" width="14" height="20" rx="2" fill="${P.purple}" stroke="${P.purpleDeep}" stroke-width="1.6" transform="rotate(-8 17 21)"/>
      <rect x="14" y="9" width="14" height="20" rx="2" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.6" transform="rotate(6 21 19)"/>
    `, opts),

    tab_me: (opts) => svg(`
      <circle cx="20" cy="14" r="7" fill="${P.sakura}" stroke="${P.sakuraDeep}" stroke-width="1.8"/>
      <path d="M 6 34 C 6 24 14 22 20 22 C 26 22 34 24 34 34 Z" fill="${P.sakura}" stroke="${P.sakuraDeep}" stroke-width="1.8"/>
      <circle cx="18" cy="13" r=".8" fill="${P.ink}"/>
      <circle cx="22" cy="13" r=".8" fill="${P.ink}"/>
      <path d="M 18 16 Q 20 17 22 16" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),

    // ---- Smart action / state icons ----
    moon_sleep: (opts) => svg(`
      <path d="M 30 20 A 12 12 0 1 1 16 8 A 10 10 0 0 0 30 20 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <text x="28" y="14" font-size="6" font-weight="800" fill="${P.skyDark}" stroke="none">Z</text>
      <text x="32" y="10" font-size="5" font-weight="800" fill="${P.skyDark}" stroke="none">z</text>
    `, opts),

    // ---- Store-specific building icons (replacing emoji on pins) ----
    store_conv: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="5" y="14" width="30" height="5" fill="${P.grassDark}"/>
      <rect x="5" y="14" width="30" height="2" fill="${P.grass}"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <rect x="9" y="22" width="5" height="5" fill="${P.sky}"/>
      <rect x="26" y="22" width="5" height="5" fill="${P.sky}"/>
      <text x="20" y="18" font-size="4" font-weight="800" fill="${P.cream}" stroke="none" text-anchor="middle">24H</text>
    `, opts),

    store_supermarket: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <polygon points="5,16 20,8 35,16" fill="${P.peachDeep}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <circle cx="10" cy="30" r="1.5" fill="${P.ink}"/>
      <circle cx="14" cy="30" r="1.5" fill="${P.ink}"/>
      <rect x="8" y="22" width="8" height="6" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1"/>
    `, opts),

    store_fastfood: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <polygon points="5,16 20,8 35,16" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <text x="20" y="15" font-size="5" font-weight="900" fill="${P.fire}" stroke="none" text-anchor="middle">M</text>
      <rect x="9" y="22" width="5" height="4" fill="${P.sky}"/>
      <rect x="26" y="22" width="5" height="4" fill="${P.sky}"/>
    `, opts),

    store_cafe: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <polygon points="5,16 20,8 35,16" fill="${P.grassDark}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <rect x="9" y="21" width="6" height="6" rx="3" fill="${P.brownDark}"/>
      <rect x="25" y="21" width="6" height="6" rx="3" fill="${P.brownDark}"/>
      <path d="M 14 14 Q 13 11 15 9" stroke="${P.cream}" stroke-width="1.2" opacity=".7" fill="none"/>
    `, opts),

    store_night_market: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="11" ry="13" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.8"/>
      <rect x="9" y="9" width="22" height="2" fill="${P.brownDark}"/>
      <rect x="9" y="34" width="22" height="2" fill="${P.brownDark}"/>
      <line x1="18" y1="3" x2="22" y2="3" stroke="${P.brownDark}" stroke-width="1.6"/>
      <line x1="20" y1="3" x2="20" y2="9" stroke="${P.brownDark}" stroke-width="1.2"/>
      <line x1="14" y1="11" x2="14" y2="34" stroke="${P.fireDark}" stroke-width="1" opacity=".3"/>
      <line x1="26" y1="11" x2="26" y2="34" stroke="${P.fireDark}" stroke-width="1" opacity=".3"/>
    `, opts),

    store_bubble: (opts) => svg(`
      <rect x="11" y="10" width="18" height="26" rx="2" fill="${P.brownLight}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="13" y="12" width="14" height="6" fill="${P.creamDark}"/>
      <circle cx="16" cy="30" r="1.8" fill="${P.ink}"/>
      <circle cx="20" cy="32" r="1.8" fill="${P.ink}"/>
      <circle cx="24" cy="30" r="1.8" fill="${P.ink}"/>
      <circle cx="18" cy="27" r="1.8" fill="${P.ink}"/>
      <circle cx="22" cy="27" r="1.8" fill="${P.ink}"/>
      <rect x="18.5" y="4" width="3" height="10" fill="${P.peachDark}"/>
    `, opts),

    store_sushi: (opts) => svg(`
      <ellipse cx="20" cy="28" rx="14" ry="6" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <ellipse cx="20" cy="22" rx="12" ry="5" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.6"/>
      <rect x="7" y="20" width="26" height="2" fill="${P.grassDark}"/>
      <ellipse cx="15" cy="21" rx="2" ry="1.5" fill="${P.cream}" opacity=".6"/>
      <ellipse cx="24" cy="23" rx="2" ry="1.5" fill="${P.cream}" opacity=".6"/>
    `, opts),

    store_healthy: (opts) => svg(`
      <ellipse cx="20" cy="26" rx="14" ry="7" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 24 Q 12 16 18 20 Q 20 24 15 28" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <path d="M 24 22 Q 30 16 32 22 Q 32 28 27 26" fill="${P.grassLight}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <circle cx="17" cy="22" r="2" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.2"/>
      <circle cx="22" cy="24" r="1.5" fill="${P.gold}"/>
    `, opts),

    store_fp: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <polygon points="5,16 20,8 35,16" fill="${P.grassDark}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <!-- FP leaf logo -->
      <path d="M 20 14 Q 14 16 16 22 Q 22 20 20 14 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1"/>
    `, opts),

    store_fp_base: (opts) => svg(`
      <rect x="5" y="16" width="30" height="18" fill="${P.gold}" stroke="${P.ink}" stroke-width="1.8"/>
      <polygon points="5,16 20,8 35,16" fill="${P.goldDeep}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="17" y="24" width="6" height="10" fill="${P.brown}"/>
      <path d="M 14 13 L 20 6 L 26 13 L 22 13 L 20 11 L 18 13 Z" fill="${P.goldDark}" stroke="${P.ink}" stroke-width="1"/>
    `, opts),

    // ---- Refresh / arrow ----
    refresh: (opts) => svg(`
      <path d="M 8 20 A 12 12 0 0 1 28 12 L 32 10 L 30 16 L 24 14" stroke="${P.peachDeep}" stroke-width="2.5" stroke-linecap="round" fill="none"/>
      <path d="M 32 20 A 12 12 0 0 1 12 28 L 8 30 L 10 24 L 16 26" stroke="${P.peachDeep}" stroke-width="2.5" stroke-linecap="round" fill="none"/>
    `, opts),

    // ---- Arrow right ----
    arrow_right: (opts) => svg(`
      <path d="M 10 20 L 28 20 M 22 14 L 28 20 L 22 26" stroke="${P.peachDeep}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
    `, opts),

    // ==== Phase 3: Food icons ====
    egg: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="11" ry="14" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 11 18 Q 14 14 18 16" stroke="${P.brownDark}" stroke-width="1.2" fill="none" opacity=".4"/>
    `, opts),
    egg_tea: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="11" ry="14" fill="${P.brownLight}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 12 20 Q 16 14 18 18 Q 22 12 24 18 Q 28 14 28 20" stroke="${P.brownDark}" stroke-width="1" fill="none" opacity=".5"/>
    `, opts),
    rice: (opts) => svg(`
      <ellipse cx="20" cy="28" rx="14" ry="3" fill="${P.brownDark}"/>
      <path d="M 7 24 Q 20 12 33 24 Q 30 27 20 27 Q 10 27 7 24 Z" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <circle cx="15" cy="22" r=".8" fill="${P.brownLight}"/>
      <circle cx="20" cy="19" r=".8" fill="${P.brownLight}"/>
      <circle cx="25" cy="22" r=".8" fill="${P.brownLight}"/>
    `, opts),
    rice_ball: (opts) => svg(`
      <path d="M 20 6 L 34 32 L 6 32 Z" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="12" y="22" width="16" height="7" fill="${P.ink}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <circle cx="17" cy="18" r=".8" fill="${P.brownLight}"/>
      <circle cx="23" cy="16" r=".8" fill="${P.brownLight}"/>
    `, opts),
    sweet_potato: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="14" ry="8" fill="${P.fire}" stroke="${P.brownDark}" stroke-width="1.8" transform="rotate(-15 20 22)"/>
      <ellipse cx="14" cy="18" rx="2" ry="1" fill="${P.brownLight}" opacity=".6"/>
      <ellipse cx="26" cy="24" rx="2" ry="1" fill="${P.brownLight}" opacity=".6"/>
    `, opts),
    banana: (opts) => svg(`
      <path d="M 8 10 Q 6 26 20 34 Q 32 30 32 14 L 30 14 Q 30 28 20 32 Q 10 26 12 12 Z" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 8 10 Q 6 12 8 14" stroke="${P.brownDark}" stroke-width="1.5" fill="none"/>
    `, opts),
    apple: (opts) => svg(`
      <path d="M 20 10 C 10 10 6 18 8 26 C 10 32 14 34 20 32 C 26 34 30 32 32 26 C 34 18 30 10 20 10 Z" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.8"/>
      <path d="M 20 10 Q 22 6 26 6" stroke="${P.grassDark}" stroke-width="1.6" fill="none"/>
      <ellipse cx="14" cy="18" rx="2" ry="3" fill="#FFF" opacity=".5"/>
    `, opts),
    milk: (opts) => svg(`
      <path d="M 10 12 L 10 34 L 30 34 L 30 12 L 26 8 L 14 8 Z" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="12" y="20" width="16" height="10" fill="${P.sky}" opacity=".3"/>
      <text x="20" y="28" font-size="6" font-weight="800" fill="${P.skyDark}" stroke="none" text-anchor="middle">奶</text>
    `, opts),
    soy_milk: (opts) => svg(`
      <rect x="10" y="10" width="20" height="26" rx="2" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="12" y="14" width="16" height="14" fill="${P.creamDark}" opacity=".7"/>
      <text x="20" y="24" font-size="7" font-weight="800" fill="${P.brownDark}" stroke="none" text-anchor="middle">豆</text>
    `, opts),
    noodle: (opts) => svg(`
      <ellipse cx="20" cy="28" rx="14" ry="6" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 9 24 Q 16 18 23 23 Q 30 18 31 24" stroke="${P.gold}" stroke-width="2" fill="none"/>
      <path d="M 11 26 Q 18 20 25 25 Q 30 22 31 26" stroke="${P.gold}" stroke-width="2" fill="none"/>
      <ellipse cx="16" cy="22" rx="3" ry="2" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1"/>
      <circle cx="24" cy="24" r="2" fill="${P.grass}"/>
    `, opts),
    dumpling: (opts) => svg(`
      <path d="M 6 22 Q 8 12 20 12 Q 32 12 34 22 Q 34 26 30 26 L 10 26 Q 6 26 6 22 Z" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 8 22 Q 12 18 14 22" stroke="${P.brownDark}" stroke-width="1.2" fill="none"/>
      <path d="M 14 22 Q 18 18 20 22" stroke="${P.brownDark}" stroke-width="1.2" fill="none"/>
      <path d="M 20 22 Q 24 18 26 22" stroke="${P.brownDark}" stroke-width="1.2" fill="none"/>
      <path d="M 26 22 Q 30 18 32 22" stroke="${P.brownDark}" stroke-width="1.2" fill="none"/>
    `, opts),
    fries: (opts) => svg(`
      <path d="M 10 14 L 10 36 L 30 36 L 30 14 Z" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.8"/>
      <rect x="12" y="4" width="3" height="14" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <rect x="16" y="2" width="3" height="16" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <rect x="20" y="4" width="3" height="14" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <rect x="24" y="6" width="3" height="12" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.2"/>
    `, opts),
    ice_cream: (opts) => svg(`
      <path d="M 20 34 L 10 16 L 30 16 Z" fill="${P.fire}" stroke="${P.brownDark}" stroke-width="1.6"/>
      <path d="M 12 16 L 15 14 L 13 12 M 16 14 L 19 12 L 17 10 M 20 12 L 23 10 L 21 8 M 24 10 L 27 8 L 25 6" stroke="${P.brownDark}" stroke-width=".8" fill="none"/>
      <circle cx="15" cy="14" r="4" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.6"/>
      <circle cx="22" cy="10" r="4.5" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.6"/>
      <circle cx="27" cy="14" r="4" fill="${P.brownLight}" stroke="${P.ink}" stroke-width="1.6"/>
    `, opts),
    cake: (opts) => svg(`
      <rect x="6" y="22" width="28" height="12" fill="${P.sakura}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 6 22 Q 20 16 34 22" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="12" cy="22" r="2" fill="${P.fire}"/>
      <circle cx="20" cy="20" r="2" fill="${P.fire}"/>
      <circle cx="28" cy="22" r="2" fill="${P.fire}"/>
      <line x1="20" y1="10" x2="20" y2="18" stroke="${P.gold}" stroke-width="1.5"/>
      <path d="M 20 8 Q 18 10 20 12 Q 22 10 20 8" fill="${P.fire}"/>
    `, opts),
    sandwich: (opts) => svg(`
      <polygon points="6,30 34,30 30,12 10,12" fill="${P.gold}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <path d="M 10 18 Q 20 14 30 18 L 28 22 L 12 22 Z" fill="${P.grass}"/>
      <path d="M 10 22 L 30 22 L 28 26 L 12 26 Z" fill="${P.fire}"/>
      <path d="M 10 24 L 30 24" stroke="${P.cream}" stroke-width="1" opacity=".5"/>
    `, opts),
    soda: (opts) => svg(`
      <path d="M 11 10 L 14 36 L 26 36 L 29 10 Z" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="11" y="10" width="18" height="6" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.4"/>
      <line x1="17" y1="4" x2="17" y2="10" stroke="${P.ink}" stroke-width="1.6"/>
      <circle cx="17" cy="3" r="1.5" fill="${P.ink}"/>
      <rect x="14" y="18" width="12" height="14" fill="${P.fire}" opacity=".5"/>
      <circle cx="17" cy="24" r="1" fill="#FFF" opacity=".7"/>
      <circle cx="22" cy="28" r="1" fill="#FFF" opacity=".7"/>
    `, opts),
    tea: (opts) => svg(`
      <rect x="10" y="16" width="18" height="16" rx="2" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 28 20 Q 34 20 34 24 Q 34 28 28 28" stroke="${P.ink}" stroke-width="1.8" fill="none"/>
      <rect x="12" y="18" width="14" height="3" fill="${P.grassDark}"/>
      <path d="M 14 14 Q 13 10 15 8" stroke="${P.grassDark}" stroke-width="1.2" fill="none"/>
      <path d="M 20 14 Q 19 10 21 8" stroke="${P.grassDark}" stroke-width="1.2" fill="none"/>
    `, opts),
    soup_bowl: (opts) => svg(`
      <path d="M 4 18 L 36 18 L 32 32 Q 20 36 8 32 Z" fill="${P.peach}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <ellipse cx="20" cy="18" rx="16" ry="3" fill="${P.cream}" stroke="${P.brownDark}" stroke-width="1.6"/>
      <path d="M 14 12 Q 13 9 15 7" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <path d="M 26 12 Q 25 9 27 7" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
    `, opts),
    hot_pot: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="15" ry="6" fill="${P.fire}" stroke="${P.fireDark}" stroke-width="1.6"/>
      <path d="M 5 22 L 7 32 Q 20 36 33 32 L 35 22" fill="${P.peachDark}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <circle cx="13" cy="22" r="1.8" fill="${P.cream}"/>
      <circle cx="20" cy="21" r="1.8" fill="${P.grass}"/>
      <circle cx="26" cy="23" r="1.8" fill="${P.cream}"/>
      <path d="M 12 18 Q 11 14 13 12" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <path d="M 20 16 Q 19 12 21 10" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <path d="M 28 18 Q 27 14 29 12" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
    `, opts),
    wine: (opts) => svg(`
      <path d="M 12 6 L 28 6 L 26 20 Q 20 26 14 20 Z" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.8"/>
      <line x1="20" y1="24" x2="20" y2="32" stroke="${P.ink}" stroke-width="1.8"/>
      <ellipse cx="20" cy="34" rx="6" ry="1.5" fill="${P.ink}"/>
    `, opts),
    coconut_drink: (opts) => svg(`
      <ellipse cx="20" cy="22" rx="10" ry="12" fill="${P.brownDark}" stroke="${P.ink}" stroke-width="1.8"/>
      <ellipse cx="20" cy="22" rx="7" ry="9" fill="${P.cream}"/>
      <rect x="18" y="4" width="3" height="10" fill="${P.grassDark}"/>
      <path d="M 20 6 L 24 2 M 20 6 L 16 3" stroke="${P.grassDark}" stroke-width="1.5"/>
    `, opts),

    // ==== Phase 3: Characters / symbols used in cards ====
    brain: (opts) => svg(`
      <path d="M 20 8 C 10 8 6 16 8 22 C 6 26 10 30 14 30 L 26 30 C 30 30 34 26 32 22 C 34 16 30 8 20 8 Z" fill="${P.sakura}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 20 8 L 20 30 M 14 14 Q 18 18 14 22 M 26 14 Q 22 18 26 22" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),
    phone: (opts) => svg(`
      <rect x="11" y="6" width="18" height="28" rx="3" fill="${P.ink}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="13" y="10" width="14" height="18" fill="${P.sky}" opacity=".8"/>
      <circle cx="20" cy="31" r="1.5" fill="${P.cream}"/>
      <rect x="17" y="8" width="6" height="1" fill="${P.cream}" opacity=".6"/>
    `, opts),
    clock: (opts) => svg(`
      <circle cx="20" cy="20" r="14" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <line x1="20" y1="20" x2="20" y2="10" stroke="${P.ink}" stroke-width="2" stroke-linecap="round"/>
      <line x1="20" y1="20" x2="26" y2="22" stroke="${P.peachDeep}" stroke-width="2" stroke-linecap="round"/>
      <circle cx="20" cy="20" r="1.5" fill="${P.ink}"/>
    `, opts),
    book: (opts) => svg(`
      <path d="M 6 10 Q 14 8 20 12 Q 26 8 34 10 L 34 30 Q 26 28 20 32 Q 14 28 6 30 Z" fill="${P.peach}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <line x1="20" y1="12" x2="20" y2="32" stroke="${P.brownDark}" stroke-width="1.5"/>
      <path d="M 10 14 L 17 14 M 10 18 L 17 18 M 23 14 L 30 14 M 23 18 L 30 18" stroke="${P.brownDark}" stroke-width="1" opacity=".5"/>
    `, opts),
    dumbbell: (opts) => svg(`
      <rect x="4" y="16" width="4" height="8" fill="${P.ink}" stroke="${P.ink}"/>
      <rect x="8" y="14" width="4" height="12" fill="${P.inkLight}" stroke="${P.ink}"/>
      <rect x="12" y="18" width="16" height="4" fill="${P.inkLight}" stroke="${P.ink}"/>
      <rect x="28" y="14" width="4" height="12" fill="${P.inkLight}" stroke="${P.ink}"/>
      <rect x="32" y="16" width="4" height="8" fill="${P.ink}" stroke="${P.ink}"/>
    `, opts),
    drop_sweat: (opts) => svg(`
      <path d="M 20 6 C 13 16 9 22 9 26 A 11 11 0 0 0 31 26 C 31 22 27 16 20 6 Z" fill="${P.sky}" stroke="${P.skyDark}" stroke-width="1.8"/>
      <ellipse cx="16" cy="22" rx="3" ry="4" fill="#FFF" opacity=".6"/>
    `, opts),
    lightbulb: (opts) => svg(`
      <path d="M 14 10 Q 14 2 20 2 Q 26 2 26 10 Q 26 16 22 20 L 22 26 L 18 26 L 18 20 Q 14 16 14 10 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <rect x="16" y="28" width="8" height="4" fill="${P.ink}"/>
      <rect x="17" y="33" width="6" height="2" fill="${P.ink}"/>
    `, opts),

    // ==== Phase 3: NPC portraits (simpler, bigger) ====
    npc_clerk: (opts) => svg(`
      <circle cx="20" cy="14" r="7" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.sky}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="17.5" cy="13" r=".8" fill="${P.ink}"/>
      <circle cx="22.5" cy="13" r=".8" fill="${P.ink}"/>
      <path d="M 17 16 Q 20 18 23 16" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
      <rect x="17" y="22" width="6" height="4" fill="${P.grass}"/>
    `, opts),
    npc_chef: (opts) => svg(`
      <ellipse cx="20" cy="8" rx="9" ry="5" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.6"/>
      <rect x="13" y="10" width="14" height="4" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.6"/>
      <circle cx="20" cy="18" r="6" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="18" cy="17" r=".8" fill="${P.ink}"/>
      <circle cx="22" cy="17" r=".8" fill="${P.ink}"/>
      <path d="M 17 20 Q 20 22 23 20" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),
    npc_artist: (opts) => svg(`
      <path d="M 10 12 Q 10 4 20 4 Q 30 4 30 14 L 28 16 L 24 12 L 20 16 L 16 12 L 12 16 Z" fill="${P.ink}"/>
      <circle cx="20" cy="16" r="7" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.purple}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="17.5" cy="15" r=".8" fill="${P.ink}"/>
      <circle cx="22.5" cy="15" r=".8" fill="${P.ink}"/>
      <path d="M 17 18 Q 20 20 23 18" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),
    npc_girl: (opts) => svg(`
      <path d="M 10 14 Q 10 4 20 4 Q 30 4 30 14 Q 32 20 30 24 L 10 24 Q 8 20 10 14 Z" fill="${P.brownDark}"/>
      <circle cx="20" cy="16" r="7" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="17.5" cy="15" r=".8" fill="${P.ink}"/>
      <circle cx="22.5" cy="15" r=".8" fill="${P.ink}"/>
      <path d="M 17 18 Q 20 20 23 18" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),
    npc_scientist: (opts) => svg(`
      <circle cx="20" cy="14" r="7" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="16" y="12" width="3" height="4" fill="${P.sky}" stroke="${P.ink}" stroke-width="1"/>
      <rect x="21" y="12" width="3" height="4" fill="${P.sky}" stroke="${P.ink}" stroke-width="1"/>
      <path d="M 17 16 Q 20 18 23 16" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
      <rect x="18" y="24" width="4" height="8" fill="${P.grass}"/>
    `, opts),
    npc_elder: (opts) => svg(`
      <path d="M 10 16 Q 10 6 20 6 Q 30 6 30 16 L 26 14 Q 20 16 14 14 Z" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="20" cy="16" r="7" fill="${P.peach}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 8 36 C 8 26 14 24 20 24 C 26 24 32 26 32 36 Z" fill="${P.fire}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="17.5" cy="15" r=".8" fill="${P.ink}"/>
      <circle cx="22.5" cy="15" r=".8" fill="${P.ink}"/>
      <path d="M 17 19 Q 20 20 23 19" stroke="${P.ink}" stroke-width="1.2" fill="none"/>
    `, opts),

    // ==== Phase 3: Island decorations ====
    palm: (opts) => svg(`
      <rect x="18" y="18" width="4" height="18" fill="${P.brown}" stroke="${P.brownDark}" stroke-width="1.4"/>
      <path d="M 20 18 Q 8 14 4 20 Q 12 16 20 22 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <path d="M 20 18 Q 32 14 36 20 Q 28 16 20 22 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <path d="M 20 18 Q 20 6 28 4 Q 24 14 20 22 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <path d="M 20 18 Q 20 6 12 4 Q 16 14 20 22 Z" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.4"/>
      <circle cx="16" cy="16" r="1.5" fill="${P.brownDark}"/>
      <circle cx="24" cy="16" r="1.5" fill="${P.brownDark}"/>
    `, opts),
    tree: (opts) => svg(`
      <rect x="18" y="24" width="4" height="12" fill="${P.brown}" stroke="${P.brownDark}" stroke-width="1.4"/>
      <circle cx="20" cy="20" r="14" fill="${P.grass}" stroke="${P.grassDark}" stroke-width="1.8"/>
      <circle cx="15" cy="18" r="2" fill="${P.grassLight}" opacity=".6"/>
      <circle cx="24" cy="22" r="1.5" fill="${P.grassLight}" opacity=".6"/>
    `, opts),
    flower: (opts) => svg(`
      <circle cx="10" cy="20" r="6" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="30" cy="20" r="6" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="20" cy="10" r="6" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="20" cy="30" r="6" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.4"/>
      <circle cx="20" cy="20" r="5" fill="${P.gold}" stroke="${P.ink}" stroke-width="1.4"/>
    `, opts),
    rock: (opts) => svg(`
      <path d="M 6 28 L 10 16 L 20 10 L 30 14 L 34 24 L 32 32 L 8 32 Z" fill="${P.inkLight}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 10 20 L 20 14" stroke="${P.ink}" stroke-width="1" opacity=".4"/>
    `, opts),

    // ==== Phase 3: Intent icons ====
    breakfast: (opts) => svg(`
      <circle cx="20" cy="14" r="10" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <line x1="20" y1="2" x2="20" y2="4" stroke="${P.goldDark}" stroke-width="1.8"/>
      <line x1="6" y1="14" x2="8" y2="14" stroke="${P.goldDark}" stroke-width="1.8"/>
      <line x1="32" y1="14" x2="34" y2="14" stroke="${P.goldDark}" stroke-width="1.8"/>
      <rect x="4" y="26" width="32" height="4" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.4"/>
      <path d="M 4 28 L 36 28" stroke="${P.brownDark}" opacity=".3"/>
    `, opts),
    lunch: (opts) => svg(`
      <rect x="5" y="12" width="30" height="22" rx="3" fill="${P.peachDark}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <rect x="8" y="15" width="24" height="14" rx="1" fill="${P.cream}"/>
      <circle cx="14" cy="20" r="2.5" fill="${P.gold}"/>
      <circle cx="22" cy="20" r="2" fill="${P.grass}"/>
      <rect x="18" y="22" width="10" height="3" fill="${P.brownLight}" rx="1"/>
    `, opts),
    late_snack: (opts) => svg(`
      <path d="M 30 20 A 12 12 0 1 1 16 8 A 10 10 0 0 0 30 20 Z" fill="${P.gold}" stroke="${P.goldDark}" stroke-width="1.8"/>
      <circle cx="8" cy="8" r=".8" fill="${P.goldDark}"/>
      <circle cx="32" cy="28" r="1" fill="${P.goldDark}"/>
      <circle cx="10" cy="30" r=".8" fill="${P.goldDark}"/>
    `, opts),
    prep: (opts) => svg(`
      <rect x="8" y="6" width="22" height="28" rx="2" fill="${P.cream}" stroke="${P.ink}" stroke-width="1.8"/>
      <rect x="14" y="4" width="10" height="4" rx="1" fill="${P.peachDeep}" stroke="${P.brownDark}" stroke-width="1.2"/>
      <line x1="12" y1="14" x2="26" y2="14" stroke="${P.peachDeep}" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="20" x2="26" y2="20" stroke="${P.peachDeep}" stroke-width="1.5" stroke-linecap="round"/>
      <line x1="12" y1="26" x2="26" y2="26" stroke="${P.peachDeep}" stroke-width="1.5" stroke-linecap="round"/>
      <path d="M 10 14 L 11 15 L 13 13" stroke="${P.grassDark}" stroke-width="1.4" fill="none"/>
      <path d="M 10 20 L 11 21 L 13 19" stroke="${P.grassDark}" stroke-width="1.4" fill="none"/>
    `, opts),
    cheat_day: (opts) => svg(`
      <path d="M 6 20 L 34 20 L 20 4 Z" fill="${P.sakuraDeep}" stroke="${P.ink}" stroke-width="1.8"/>
      <path d="M 6 20 Q 6 28 20 32 Q 34 28 34 20 Z" fill="${P.gold}" stroke="${P.ink}" stroke-width="1.8"/>
      <circle cx="12" cy="8" r="1.5" fill="${P.sakura}"/>
      <circle cx="28" cy="8" r="1.5" fill="${P.sakura}"/>
      <circle cx="20" cy="24" r="2" fill="${P.fire}"/>
    `, opts),
    dinner: (opts) => svg(`
      <rect x="6" y="20" width="28" height="14" rx="2" fill="${P.peachDark}" stroke="${P.brownDark}" stroke-width="1.8"/>
      <ellipse cx="20" cy="22" rx="12" ry="3" fill="${P.cream}"/>
      <path d="M 12 14 Q 11 10 13 8" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <path d="M 20 14 Q 19 10 21 8" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <path d="M 28 14 Q 27 10 29 8" stroke="${P.ink}" stroke-width="1.2" fill="none" opacity=".5"/>
      <circle cx="16" cy="22" r="1.5" fill="${P.fire}"/>
      <circle cx="22" cy="22" r="1.5" fill="${P.grass}"/>
    `, opts),

    // Generic food fallback
    food: (opts) => svg(`
      <circle cx="20" cy="20" r="14" fill="${P.peach}" stroke="${P.peachDark}" stroke-width="1.8"/>
      <circle cx="16" cy="17" r="2" fill="${P.fire}"/>
      <circle cx="23" cy="20" r="2" fill="${P.grass}"/>
      <circle cx="19" cy="24" r="2" fill="${P.gold}"/>
    `, opts),
  };

  // Emoji → icon name mapping (for automatic replacement)
  const EMOJI_MAP = {
    // Care
    '💧': 'water', '💦': 'water', '🏃': 'exercise', '💪': 'dumbbell',
    '⚖️': 'scale', '🍱': 'meal_box', '🍽️': 'meal_box',
    // Navigation/general
    '🏠': 'tab_home', '🏝️': 'island', '📷': 'camera',
    '🎴': 'card', '🎭': 'card', '✨': 'sparkle', '⚡': 'bolt',
    '🎯': 'target', '🗺️': 'map',
    // Time
    '☀️': 'sun', '🌆': 'sunset', '🌙': 'moon', '🌅': 'breakfast',
    // Milestones / fire
    '🔥': 'fire', '🌱': 'sprout', '💎': 'gem', '👑': 'crown',
    '🎉': 'sparkle', '✅': 'check', '🎁': 'gift', '🔒': 'lock',
    // Shops
    '🏪': 'store_conv', '🛒': 'store_supermarket',
    '🍔': 'burger', '🍗': 'chicken', '☕': 'coffee',
    '🏮': 'lantern', '🧋': 'bubble_tea', '🍣': 'sushi',
    '🥗': 'salad', '🌿': 'store_fp',
    // Food
    '🥚': 'egg', '🍚': 'rice', '🍙': 'rice_ball', '🍠': 'sweet_potato',
    '🥛': 'milk', '🍌': 'banana', '🍎': 'apple', '🥭': 'apple',
    '🥐': 'sandwich', '🥪': 'sandwich', '🍞': 'sandwich',
    '🥟': 'dumpling', '🍜': 'noodle', '🍲': 'hot_pot',
    '🍟': 'fries', '🍦': 'ice_cream', '🍰': 'cake', '🧁': 'cake',
    '🥤': 'soda', '🍵': 'tea', '🍶': 'soy_milk', '🧃': 'soda',
    '🍻': 'wine', '🍺': 'wine', '🥃': 'wine', '🥂': 'wine',
    '🥣': 'soup_bowl', '🍢': 'hot_pot',
    // Characters
    '🧠': 'brain', '📱': 'phone', '⏰': 'clock', '🧊': 'water',
    '📊': 'scale', '💡': 'lightbulb', '📚': 'book',
    '😔': 'moon', '💔': 'heart', '💕': 'heart', '💝': 'heart',
    // NPCs
    '🧑': 'npc_clerk', '🧑‍💼': 'npc_clerk',
    '🧑‍🍳': 'npc_chef', '👨‍🍳': 'npc_chef', '👩‍🍳': 'npc_chef',
    '👩‍🎨': 'npc_artist', '👩‍🔬': 'npc_scientist',
    '👧': 'npc_girl', '📣': 'npc_elder',
    // Nature
    '🌴': 'palm', '🌳': 'tree', '🌲': 'tree',
    '🌺': 'flower', '🌸': 'flower', '🌼': 'flower',
    '🪨': 'rock',
    // Intent icons
    '☕': 'coffee', '📋': 'prep', '🍧': 'ice_cream',
    '🎓': 'book',
    // Fallback-ish
    '🐾': 'check',
  };

  window.iconFromEmoji = function (emoji, opts) {
    if (!emoji) return '';
    const name = EMOJI_MAP[emoji];
    if (!name) return '';
    return ICONS[name]?.(opts) || '';
  };

  window.icon = function (name, opts) {
    const fn = ICONS[name];
    if (!fn) return '';
    return fn(opts);
  };
  window.iconNames = Object.keys(ICONS);
})();
