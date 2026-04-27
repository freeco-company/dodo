// Doudou character renderer — powered by Microsoft Fluent Emoji (Flat, MIT).
// Assets downloaded locally at /characters/*.svg
// No more hand-drawn SVG. Pro-grade illustrations.

const ANIMAL_META = {
  cat:      { file: 'cat.svg',      name: '貓貓', halo: '#FFD9B5' },
  rabbit:   { file: 'rabbit.svg',   name: '兔兔', halo: '#FCE6E6' },
  bear:     { file: 'bear.svg',     name: '熊熊', halo: '#FFE0B5' },
  hamster:  { file: 'hamster.svg',  name: '倉鼠', halo: '#FFECC2' },
  fox:      { file: 'fox.svg',      name: '狐狸', halo: '#FFD4B5' },
  // --- Custom-made in Fluent Flat style (not from Microsoft) ---
  shiba:    { file: 'shiba.svg',    name: '柴犬',   halo: '#FFE0B5' },
  dinosaur: { file: 'dinosaur.svg', name: '恐龍',   halo: '#DCF0D9' },
  penguin:  { file: 'penguin.svg',  name: '企鵝',   halo: '#DDE9F2' },
  tuxedo:   { file: 'tuxedo.svg',   name: '賓士貓', halo: '#F0F0F0' },
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

// Outfit — mostly Fluent assets; a few use emoji-text overlays
function outfitRender(outfit) {
  if (!outfit || outfit === 'none') return '';
  const imgMap = {
    scarf:      { src: 'scarf.svg',      className: 'of-scarf' },
    glasses:    { src: 'glasses.svg',    className: 'of-glasses' },
    headphones: { src: 'headphone.svg',  className: 'of-headphones' },
    devil_horns:{ src: 'devil.svg',      className: 'of-devil' },
    halo:       { src: 'halo.svg',       className: 'of-halo' },
  };
  if (imgMap[outfit]) {
    return `<img class="char-outfit ${imgMap[outfit].className}" src="/characters/${imgMap[outfit].src}" alt=""/>`;
  }
  // Fallback: emoji text overlays for those we don't have flat-Fluent assets for
  const emojiMap = {
    straw_hat: { char: '👒', className: 'of-hat' },
    chef_hat:  { char: '👨‍🍳', className: 'of-hat' },
    angel_wings: { char: '👼', className: 'of-wings' },
  };
  if (emojiMap[outfit]) {
    return `<span class="char-outfit-emoji ${emojiMap[outfit].className}">${emojiMap[outfit].char}</span>`;
  }
  return '';
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
        <img class="char-body-mini" src="/characters/${a.file}" alt="${a.name}"/>
      </div>
    </div>`;
  }

  const accFile = accessoryAsset(level);
  const moodFile = MOOD_BADGE[mood];
  const r = rarityOf(level);

  return `
    <div class="char-root" style="--halo:${a.halo};">
      <div class="char-halo"></div>
      <div class="char-wrap">
        <img class="char-body" src="/characters/${a.file}" alt="${a.name}" draggable="false"/>
        ${outfitRender(outfit)}
        ${accFile ? `<img class="char-accessory" src="/characters/${accFile}" alt=""/>` : ''}
        ${moodFile ? `<img class="char-mood-badge" src="/characters/${moodFile}" alt=""/>` : ''}
      </div>
      <div class="char-rarity" style="color:${r.textColor}; background: ${r.gradient}; text-shadow: ${r.shadow};">${r.name} · LV.${level}</div>
    </div>`;
}

// Simple img-only renderer for compact slots (store NPC, store mascot thumbnail)
function animalImg(animal, extraClass = '') {
  const a = ANIMAL_META[animal] || ANIMAL_META.cat;
  return `<img class="animal-img ${extraClass}" src="/characters/${a.file}" alt="${a.name}" draggable="false"/>`;
}
function animalList() { return Object.keys(ANIMAL_META); }

window.renderCharacter = renderCharacter;
window.rarityOf = rarityOf;
window.animalImg = animalImg;
window.animalList = animalList;
