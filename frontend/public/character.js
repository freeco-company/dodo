// 潘朵拉飲食 character renderer — 集團統一 anchor v2 (方向1 手繪棉花紙質感 / 溫柔文青風)
// PNGs come from @freeco-company/pandora-design-svg v0.2.0, copied to /svg/anchors/*.png
// at frontend build time (see package.json: "sync:design-svg" / preserve / presync:cap:sync:ios).
// Hand-drawn SVG / Fluent Emoji set retired 2026-04-30.

const ANCHOR_BASE = '/svg/anchors';

const ANIMAL_META = {
  rabbit:   { file: 'rabbit.png',   name: '兔兔',     halo: '#FCE6E6' },
  cat:      { file: 'cat.png',      name: '貓貓',     halo: '#FFE3D6' },
  tiger:    { file: 'tiger.png',    name: '虎虎',     halo: '#FAE3B3' },
  penguin:  { file: 'penguin.png',  name: '企鵝',     halo: '#DDE9F2' },
  bear:     { file: 'bear.png',     name: '熊熊',     halo: '#E8D5BB' },
  dog:      { file: 'dog.png',      name: '狗狗',     halo: '#F5E1C8' },
  fox:      { file: 'fox.png',      name: '狐狸',     halo: '#F5C8A0' },
  dinosaur: { file: 'dinosaur.png', name: '恐龍',     halo: '#D5DFC2' },
  sheep:    { file: 'sheep.png',    name: '綿羊',     halo: '#F0E4D2' },
  pig:      { file: 'pig.png',      name: '小豬',     halo: '#F5D5D2' },
  robot:    { file: 'robot.png',    name: '機器人',   halo: '#D8D8D8' },
};

// Per-animal anatomy landmarks (y% from top of 512x512 PNG).
// 2026-05-01 — eyeballed from anchor v2 PNGs; user will iterate from screenshots.
// head_top is implicit at 0%; head_top overlays anchor with offset_px from top.
const ANIMAL_ANCHORS = {
  // ears tall, body content shifted down
  rabbit:   { eye_y: 38, nose_y: 45, neck_y: 58, chest_y: 70, back_y: 52 },
  cat:      { eye_y: 36, nose_y: 43, neck_y: 52, chest_y: 62, back_y: 50 },
  tiger:    { eye_y: 36, nose_y: 44, neck_y: 58, chest_y: 68, back_y: 52 },
  // tall body, head/eyes high
  penguin:  { eye_y: 28, nose_y: 34, neck_y: 50, chest_y: 62, back_y: 48 },
  // big head, low eyes — confirmed via PR #106 screenshots
  bear:     { eye_y: 32, nose_y: 39, neck_y: 48, chest_y: 58, back_y: 46 },
  dog:      { eye_y: 36, nose_y: 43, neck_y: 54, chest_y: 64, back_y: 50 },
  fox:      { eye_y: 32, nose_y: 40, neck_y: 50, chest_y: 58, back_y: 46 },
  // round body, eyes low-ish
  dinosaur: { eye_y: 38, nose_y: 44, neck_y: 52, chest_y: 64, back_y: 50 },
  sheep:    { eye_y: 34, nose_y: 41, neck_y: 52, chest_y: 62, back_y: 48 },
  pig:      { eye_y: 36, nose_y: 44, neck_y: 52, chest_y: 62, back_y: 48 },
  robot:    { eye_y: 32, nose_y: 38, neck_y: 48, chest_y: 62, back_y: 48 },
};

// Outfit → anchor classification.
// anchor:
//   'head_top'  — top:0% + offset_px (negative pulls upward above head)
//   'eye_y' / 'neck_y' / 'chest_y' / 'back_y' — top:<animal_anchor>% with translate(-50%,-50%)
// scale: width as fraction of body container (body is .char-wrap, ~200px in main / responsive)
// behind: true = z-index:1 (behind body silhouette, e.g. wings/cape)
const OUTFIT_ANCHOR = {
  // HEAD-TOP
  ribbon:           { anchor: 'head_top', offset_px: -4,  scale: 0.32 },
  witch_hat:        { anchor: 'head_top', offset_px: -32, scale: 0.42 },
  fp_crown:         { anchor: 'head_top', offset_px: -18, scale: 0.34 },
  fp_chef:          { anchor: 'head_top', offset_px: -28, scale: 0.38 },
  straw_hat:        { anchor: 'head_top', offset_px: -10, scale: 0.46 },
  sakura:           { anchor: 'head_top', offset_px: -2,  scale: 0.40 },
  headphones:       { anchor: 'head_top', offset_px: -10, scale: 0.42 },
  devil_horns:      { anchor: 'head_top', offset_px: -16, scale: 0.26 },
  halo:             { anchor: 'head_top', offset_px: -22, scale: 0.34 },
  // FACE
  glasses:          { anchor: 'eye_y',  scale: 0.34 },
  sunglasses:       { anchor: 'eye_y',  scale: 0.32 },
  // NECK
  winter_scarf:     { anchor: 'neck_y', scale: 0.40 },
  scarf:            { anchor: 'neck_y', scale: 0.26 },
  // CHEST
  chef_apron:       { anchor: 'chest_y', scale: 0.46 },
  fp_apron_premium: { anchor: 'chest_y', scale: 0.46 },
  // BACK
  angel_wings:      { anchor: 'back_y', scale: 1.00, behind: true, opacity: 0.9 },
  starry_cape:      { anchor: 'back_y', scale: 0.92, behind: true, opacity: 0.92 },
};

const OUTFIT_SRC = {
  // Legacy 5
  scarf:            'scarf.svg',
  glasses:          'glasses.svg',
  headphones:       'headphone.svg',
  devil_horns:      'devil.svg',
  halo:             'halo.svg',
  // PR #98 + #102 body-positioned overlays
  ribbon:           'outfit_ribbon_overlay.svg',
  chef_apron:       'outfit_chef_apron_overlay.svg',
  witch_hat:        'outfit_witch_hat_overlay.svg',
  starry_cape:      'outfit_starry_cape_overlay.svg',
  sunglasses:       'outfit_sunglasses_overlay.svg',
  sakura:           'outfit_sakura_overlay.svg',
  winter_scarf:     'outfit_winter_scarf_overlay.svg',
  angel_wings:      'outfit_angel_wings_overlay.svg',
  fp_crown:         'outfit_fp_crown_overlay.svg',
  fp_chef:          'outfit_fp_chef_overlay.svg',
  fp_apron_premium: 'outfit_fp_apron_premium_overlay.svg',
  straw_hat:        'outfit_straw_hat_overlay.svg',
};

const MOOD_BADGE = {
  sleeping:    'zzz.svg',
  happy:       'sparkling_heart.svg',
  cheering:    'party.svg',
  proud:       'trophy.svg',
  content:     null,
  worried:     null,
  sad:         'pleading.svg',
  missing_you: 'pleading.svg',
};

// Accessory by level (overlay on top of head)
function accessoryAsset(level) {
  if (level >= 50) return 'crown.svg';
  if (level >= 20) return 'ribbon.svg';
  if (level >= 10) return 'cherry.svg';
  return null;
}

// Build inline-style positioned <img> for an outfit, picking anchor by animal.
function outfitRender(outfit, animal = 'cat') {
  if (!outfit || outfit === 'none' || outfit === 'basic') return '';
  const meta = OUTFIT_ANCHOR[outfit];
  const src = OUTFIT_SRC[outfit];
  if (!meta || !src) return '';
  const anim = ANIMAL_ANCHORS[animal] || ANIMAL_ANCHORS.cat;

  const widthPct = (meta.scale * 100).toFixed(1);
  const zIndex = meta.behind ? 1 : (meta.anchor === 'head_top' ? 5 : 4);
  const opacity = meta.opacity != null ? meta.opacity : 1;
  const offsetPx = meta.offset_px || 0;
  const className = `char-outfit of-${outfit.replace(/_/g, '-')}`;

  let style;
  if (meta.anchor === 'head_top') {
    // Anchored to top of body container; horizontal center; transform only X.
    style = `position:absolute;top:${offsetPx}px;left:50%;`
          + `width:${widthPct}%;transform:translateX(-50%);`
          + `z-index:${zIndex};opacity:${opacity};`
          + `filter:drop-shadow(0 2px 3px rgba(45,36,32,.15));pointer-events:none;`;
  } else {
    const topPct = anim[meta.anchor] != null ? anim[meta.anchor] : 50;
    style = `position:absolute;top:${topPct}%;left:50%;`
          + `width:${widthPct}%;transform:translate(-50%,-50%);`
          + `z-index:${zIndex};opacity:${opacity};`
          + `filter:drop-shadow(0 2px 3px rgba(45,36,32,.15));pointer-events:none;`;
  }

  return `<img class="${className}" src="/characters/${src}" alt="" style="${style}" draggable="false"/>`;
}

// Rarity frame — text color picked to stay readable over the gradient.
function rarityOf(level) {
  if (level >= 50) return { key: 'mythic',    name: '神話',   textColor: '#FFFFFF', shadow: '0 1px 3px rgba(0,0,0,.35)', gradient: 'linear-gradient(135deg, #FFD56E, #FF6EA5 55%, #6EC8FF)' };
  if (level >= 20) return { key: 'legendary', name: '傳說',   textColor: '#FFFFFF', shadow: '0 1px 2px rgba(139,90,48,.6)', gradient: 'linear-gradient(135deg, #F4D78A, #E89F7A)' };
  if (level >= 10) return { key: 'epic',      name: '史詩',   textColor: '#FFFFFF', shadow: '0 1px 2px rgba(74,60,80,.6)', gradient: 'linear-gradient(135deg, #B89AC9, #7A6A9C)' };
  if (level >= 5)  return { key: 'rare',      name: '稀有',   textColor: '#FFFFFF', shadow: '0 1px 2px rgba(78,106,63,.6)', gradient: 'linear-gradient(135deg, #A8C5B4, #7A9B8A)' };
  return              { key: 'common', name: '普通', textColor: '#5C4E46', shadow: 'none', gradient: 'linear-gradient(135deg, #FFFFFF, #F5ECD9)' };
}

function renderCharacter({ animal = 'cat', level = 1, mood = 'happy', outfit = null, mini = false } = {}) {
  const a = ANIMAL_META[animal] || ANIMAL_META.cat;

  if (mini) {
    return `<div class="char-root mini">
      <div class="char-stage-mini">
        <img class="char-body-mini" src="${ANCHOR_BASE}/${a.file}" alt="${a.name}"/>
      </div>
    </div>`;
  }

  const accFile = accessoryAsset(level);
  const moodFile = MOOD_BADGE[mood];
  const r = rarityOf(level);
  const animalKey = ANIMAL_META[animal] ? animal : 'cat';

  return `
    <div class="char-root" style="--halo:${a.halo};">
      <div class="char-halo"></div>
      <div class="char-wrap">
        <img class="char-body" src="${ANCHOR_BASE}/${a.file}" alt="${a.name}" draggable="false"/>
        ${outfitRender(outfit, animalKey)}
        ${accFile ? `<img class="char-accessory" src="/characters/${accFile}" alt=""/>` : ''}
        ${moodFile ? `<img class="char-mood-badge" src="/characters/${moodFile}" alt=""/>` : ''}
      </div>
      <div class="char-rarity" style="color:${r.textColor}; background: ${r.gradient}; text-shadow: ${r.shadow};">${r.name} · LV.${level}</div>
    </div>`;
}

// Simple img-only renderer for compact slots (store NPC, store mascot thumbnail)
function animalImg(animal, extraClass = '') {
  const a = ANIMAL_META[animal] || ANIMAL_META.cat;
  return `<img class="animal-img ${extraClass}" src="${ANCHOR_BASE}/${a.file}" alt="${a.name}" draggable="false"/>`;
}
function animalList() { return Object.keys(ANIMAL_META); }

window.renderCharacter = renderCharacter;
window.rarityOf = rarityOf;
window.animalImg = animalImg;
window.animalList = animalList;
window.ANIMAL_ANCHORS = ANIMAL_ANCHORS;
window.OUTFIT_ANCHOR = OUTFIT_ANCHOR;
