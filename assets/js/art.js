// Vonalas termékillusztrációk – addig jelennek meg, amíg egy termékhez nincs fotó.
// Minden rajz 200×200-as viewBoxban, `currentColor` körvonallal készül.

export const TONES = {
  saffron: { bg: '#F3E6D3', fg: '#9A5418' },
  maroon: { bg: '#F0DEDA', fg: '#6E2B2B' },
  sand: { bg: '#EFE8DC', fg: '#6B5A45' },
  sky: { bg: '#E3E8EC', fg: '#34506A' },
  sage: { bg: '#E4E8DD', fg: '#4B5C43' },
};

const beadsOnCircle = (cx, cy, r, n, br) => {
  let s = '';
  for (let i = 0; i < n; i++) {
    const a = (i / n) * Math.PI * 2 - Math.PI / 2;
    if (Math.abs(a - Math.PI / 2) < 0.2) continue; // hely a guru gyöngynek
    s += `<circle cx="${(cx + r * Math.cos(a)).toFixed(1)}" cy="${(cy + r * Math.sin(a)).toFixed(1)}" r="${br}"/>`;
  }
  return s;
};

const DRAW = {
  bowl: `
    <path d="M70 52c8-6 8-14 0-20M100 46c8-7 8-17 0-24M130 52c8-6 8-14 0-20" opacity=".5"/>
    <ellipse cx="100" cy="92" rx="60" ry="11"/>
    <path d="M40 92c3 42 24 58 60 58s57-16 60-58"/>
    <path d="M52 110c12 8 28 12 48 12s36-4 48-12" opacity=".45"/>
    <path d="M58 150c10 10 74 10 84 0" />
    <path d="M150 160l30-58" /><rect x="173" y="92" width="12" height="18" rx="5" transform="rotate(28 179 101)"/>`,
  tingsha: `
    <path d="M70 40c0 30 60 30 60 0" />
    <path d="M70 40v40M130 40v40"/>
    <ellipse cx="70" cy="104" rx="32" ry="9"/><path d="M38 104c2 14 14 22 32 22s30-8 32-22"/><circle cx="70" cy="95" r="4"/>
    <ellipse cx="130" cy="104" rx="32" ry="9"/><path d="M98 104c2 14 14 22 32 22s30-8 32-22"/><circle cx="130" cy="95" r="4"/>
    <path d="M60 150c6-4 12-4 18 0M122 150c6-4 12-4 18 0" opacity=".45"/>`,
  bell: `
    <path d="M100 26v14M92 30h16M94 40h12l-2 16h-8z"/>
    <path d="M96 56h8v10h-8z"/>
    <path d="M74 150c0-50 8-84 26-84s26 34 26 84"/>
    <path d="M66 150h68M70 158h60"/>
    <path d="M80 110h40M78 124h44" opacity=".45"/>
    <circle cx="100" cy="168" r="5"/>`,
  mala: `${beadsOnCircle(100, 88, 52, 32, 5.2)}
    <circle cx="100" cy="146" r="7"/>
    <path d="M100 153v8M92 161h16l-3 28h-10z"/>
    <path d="M97 168v18M103 168v18" opacity=".45"/>`,
  incense: `
    <path d="M36 150h128c-8 14-30 20-64 20s-56-6-64-20z"/>
    <path d="M74 150L128 60"/>
    <circle cx="128" cy="60" r="2.5" fill="currentColor"/>
    <path d="M128 56c-10-10 8-16-2-26s6-14 0-22" opacity=".5"/>`,
  holder: `
    <path d="M52 150h96M60 150c0-30 16-50 40-50s40 20 40 50"/>
    <path d="M100 100v-6M86 88h28"/><circle cx="100" cy="84" r="4"/>
    <circle cx="80" cy="128" r="4"/><circle cx="100" cy="122" r="4"/><circle cx="120" cy="128" r="4"/>
    <path d="M68 164h64" />
    <path d="M100 76c-8-10 6-16-2-26s6-12 0-20" opacity=".5"/>`,
  flag: `
    <path d="M14 46c60 22 112 22 172 0" />
    ${[0, 1, 2, 3, 4].map((i) => {
      const x = 26 + i * 32; const y = 51 + (i === 0 || i === 4 ? 0 : i === 2 ? 9 : 6);
      const c = ['#3E6FA8', '#F7F3EC', '#B23B2E', '#3E7F4F', '#D9A62B'][i];
      return `<path d="M${x} ${y}h26v44h-26z" fill="${c}" fill-opacity=".55"/><path d="M${x + 6} ${y + 14}h14M${x + 6} ${y + 22}h14M${x + 6} ${y + 30}h10" opacity=".45"/>`;
    }).join('')}`,
  buddha: `
    <circle cx="100" cy="58" r="17"/><circle cx="100" cy="37" r="6"/>
    <path d="M92 60h4M104 60h4M97 68c2 1 4 1 6 0" opacity=".6"/>
    <path d="M100 75c-22 0-34 12-38 32l-6 34M100 75c22 0 34 12 38 32l6 34"/>
    <path d="M56 141c14 10 74 10 88 0"/>
    <path d="M78 128c8 6 36 6 44 0" opacity=".6"/><ellipse cx="100" cy="126" rx="12" ry="5"/>
    <path d="M40 160c20-12 40-8 60 0 20-8 40-12 60 0M50 168h100" />`,
  chime: `
    <circle cx="100" cy="24" r="6"/><path d="M100 30v10"/>
    <ellipse cx="100" cy="46" rx="44" ry="6"/>
    <path d="M64 50v18M82 51v22M100 52v18M118 51v22M136 50v18" opacity=".5"/>
    <rect x="59" y="68" width="10" height="70" rx="3"/><rect x="77" y="73" width="10" height="88" rx="3"/>
    <rect x="95" y="70" width="10" height="100" rx="3"/><rect x="113" y="73" width="10" height="84" rx="3"/>
    <rect x="131" y="68" width="10" height="64" rx="3"/>
    <path d="M100 52v96"/><circle cx="100" cy="112" r="9"/>`,
  cushion: `
    <ellipse cx="100" cy="92" rx="66" ry="20"/>
    <path d="M34 92v34c0 11 30 20 66 20s66-9 66-20V92"/>
    ${Array.from({ length: 9 }, (_, i) => `<path d="M${46 + i * 13.5} ${108 + Math.abs(4 - i)} v28" opacity=".4"/>`).join('')}
    <circle cx="100" cy="92" r="6"/>`,
  candle: `
    <path d="M100 58c-10-12 0-24 0-30 0 6 10 18 0 30z"/>
    <path d="M100 58v14"/>
    <path d="M100 150c-30 0-52-14-58-40 16 2 30 10 38 22M100 150c30 0 52-14 58-40-16 2-30 10-38 22"/>
    <path d="M100 150c-14-10-22-26-22-46 10 6 18 16 22 28 4-12 12-22 22-28 0 20-8 36-22 46"/>
    <path d="M62 162h76"/>`,
  dreamcatcher: `
    <circle cx="100" cy="72" r="46"/>
    ${beadsOnCircle(100, 72, 30, 8, 1.6)}
    <path d="M100 26l30 46-30 46-30-46z M54 72h92" opacity=".45"/>
    <circle cx="100" cy="72" r="5"/>
    <path d="M70 110v36M100 118v50M130 110v36"/>
    <path d="M70 146c-8 8-6 22 0 28 6-6 8-20 0-28zM100 168c-8 6-6 16 0 22 6-6 8-16 0-22zM130 146c-8 8-6 22 0 28 6-6 8-20 0-28z"/>`,
  ring: `
    <ellipse cx="100" cy="116" rx="48" ry="42"/>
    <ellipse cx="100" cy="116" rx="38" ry="33" opacity=".45"/>
    <path d="M78 76l10-16h24l10 16-22 16z"/>
    <path d="M88 60l12 32 12-32M78 76h44" opacity=".5"/>`,
  scarf: `
    <path d="M40 40c30 10 90 10 120 0l-12 104c-30 8-66 8-96 0z"/>
    <path d="M52 144v18M62 146v18M72 147v18M82 148v18M92 148v18M102 148v18M112 148v18M122 147v18M132 146v18M142 144v18" opacity=".5"/>
    <path d="M70 70c10-10 20 0 30 0s20-10 30 0M66 100c10-10 22 0 34 0s24-10 34 0" opacity=".55"/>
    <circle cx="84" cy="84" r="4"/><circle cx="116" cy="84" r="4"/><circle cx="100" cy="118" r="4"/>`,
  pants: `
    <path d="M58 34h84l6 36c6 40 4 72-6 108h-26l-16-80-16 80H58c-10-36-12-68-6-108z"/>
    <path d="M58 46h84" opacity=".5"/>
    <path d="M58 166c8 4 18 4 26 0M116 166c8 4 18 4 26 0" opacity=".5"/>
    <circle cx="80" cy="100" r="8" opacity=".5"/><circle cx="120" cy="100" r="8" opacity=".5"/>`,
  copper: `
    <rect x="86" y="24" width="28" height="16" rx="3"/>
    <path d="M90 40v12c-22 8-30 24-30 44v60c0 10 8 16 18 16h44c10 0 18-6 18-16V96c0-20-8-36-30-44V40"/>
    <path d="M72 100c4 4 8-2 12 2s8-2 12 2 8-2 12 2 8-2 12 2 8-2 8 0" opacity=".45"/>
    <path d="M72 130c4 4 8-2 12 2s8-2 12 2 8-2 12 2 8-2 12 2 8-2 8 0" opacity=".45"/>`,
  tea: `
    <path d="M46 96h96v18c0 26-20 44-48 44s-48-18-48-44z"/>
    <path d="M142 104c16-2 22 8 18 18s-14 12-22 10"/>
    <path d="M38 166h112"/>
    <path d="M78 82c-6-8 4-12 0-20M96 80c-6-10 6-14 0-24M114 82c-6-8 4-12 0-20" opacity=".5"/>`,
  gift: `
    <rect x="40" y="80" width="120" height="84" rx="4"/>
    <rect x="34" y="62" width="132" height="22" rx="4"/>
    <path d="M100 62v102"/>
    <path d="M100 62c-10-22-40-26-40-10 0 10 22 10 40 10zM100 62c10-22 40-26 40-10 0 10-22 10-40 10z"/>
    <path d="M58 112h24M58 124h18" opacity=".45"/>`,
};

/** Termékillusztráció SVG-ként. */
export function art(key = 'bowl', toneKey = 'sand', { label = '' } = {}) {
  const t = TONES[toneKey] || TONES.sand;
  const body = DRAW[key] || DRAW.bowl;
  return `<svg class="art" viewBox="0 0 200 200" preserveAspectRatio="xMidYMid slice" role="img" aria-label="${label}" style="--art-bg:${t.bg};color:${t.fg}">
    <rect width="200" height="200" fill="var(--art-bg)"/>
    <g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" transform="translate(30 30) scale(.7)">${body}</g>
  </svg>`;
}

/** Díszítő mandala (vonalas), a hősképhez és elválasztókhoz. */
export function mandala({ petals = 16, rings = 4, className = 'mandala' } = {}) {
  let g = '';
  for (let r = 0; r < rings; r++) {
    const R = 40 + r * 34;
    const n = petals * (r % 2 ? 1 : 1) + r * 4;
    for (let i = 0; i < n; i++) {
      const a = (360 / n) * i;
      const len = 26 + r * 4;
      g += `<path transform="rotate(${a.toFixed(2)} 200 200)" d="M200 ${200 - R}c${len / 3} ${-len / 2} ${len / 3} ${-len} 0 ${-len * 1.2}c${-len / 3} ${len * 0.2} ${-len / 3} ${len * 0.7} 0 ${len * 1.2}z"/>`;
    }
    g += `<circle cx="200" cy="200" r="${R}"/>`;
  }
  g += '<circle cx="200" cy="200" r="18"/><circle cx="200" cy="200" r="6"/>';
  return `<svg class="${className}" viewBox="0 0 400 400" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width=".8">${g}</g></svg>`;
}

/** Márkajel: kis, nyolcszirmú mandala. */
export const logoMark = `<svg class="logo-mark" viewBox="0 0 40 40" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="1.4">
  ${Array.from({ length: 8 }, (_, i) => `<path transform="rotate(${i * 45} 20 20)" d="M20 4c3.5 4 3.5 8 0 11-3.5-3-3.5-7 0-11z"/>`).join('')}
  <circle cx="20" cy="20" r="5"/><circle cx="20" cy="20" r="17.5" opacity=".45"/></g></svg>`;
