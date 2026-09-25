// Több oldalon használt tartalmi blokkok.
import { art } from './art.js';
import { esc, icon, img } from './app.js';

export const fmtDate = (iso) => new Date(iso).toLocaleDateString('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });

export function postCard(a) {
  return `<a class="post reveal" href="cikk.html?a=${encodeURIComponent(a.slug)}">
    <div class="post-media">${a.image ? img(a.image, '', { sizes: '(max-width: 860px) 100vw, 33vw' }) : art(a.art, a.tone)}</div>
    <time datetime="${a.date}">${fmtDate(a.date)}</time>
    <h3>${esc(a.title)}</h3>
    <p>${esc(a.excerpt)}</p>
    <span class="go">Tovább olvasok ${icon('arrow')}</span>
  </a>`;
}

export function quoteCard(q) {
  return `<figure class="quote reveal">
    <blockquote>${esc(q.text)}</blockquote>
    <figcaption><strong>${esc(q.name)}</strong></figcaption>
  </figure>`;
}

/** Stilizált útvonal-térkép: Budapest ↔ India ↔ Nepál. */
export function routeMap() {
  const dot = (x, y, big = false) => `
    <circle cx="${x}" cy="${y}" r="${big ? 6 : 4}" fill="currentColor"/>
    <circle cx="${x}" cy="${y}" r="${big ? 14 : 10}" fill="none" stroke="currentColor" stroke-opacity=".35"/>`;
  const label = (x, y, text, anchor = 'start', cls = '') => `<text x="${x}" y="${y}" text-anchor="${anchor}" ${cls ? `class="${cls}"` : ''}>${text}</text>`;
  const grid = Array.from({ length: 7 }, (_, i) => `<path d="M0 ${40 + i * 48}H540" stroke="currentColor" stroke-opacity=".08"/>`).join('')
    + Array.from({ length: 10 }, (_, i) => `<path d="M${30 + i * 56} 0V340" stroke="currentColor" stroke-opacity=".08"/>`).join('');
  return `<svg class="route" viewBox="0 0 540 340" role="img" aria-label="Útvonal Nepálból és Indiából Budapestig">
    ${grid}
    <path class="path" d="M482 176C440 70 260 20 70 78" fill="none" stroke="currentColor" stroke-width="1.5"/>
    <path class="path" d="M392 204C330 124 200 70 70 78" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity=".6"/>
    <path d="M346 250L392 204M372 306L346 250" fill="none" stroke="currentColor" stroke-opacity=".35" stroke-dasharray="2 5"/>
    ${dot(70, 78, true)}${label(88, 82, 'Budapest')}${label(88, 100, 'rendelésed innen indul', 'start', 'small-t')}
    ${dot(482, 176, true)}${label(482, 212, 'Katmandu', 'middle')}${label(482, 228, 'hangtál, szobor', 'middle', 'small-t')}
    ${dot(392, 204)}${label(378, 208, 'Moradabad', 'end')}${label(378, 224, 'réz', 'end', 'small-t')}
    ${dot(346, 250)}${label(332, 254, 'Jaipur', 'end')}${label(332, 270, 'textil, ékszer', 'end', 'small-t')}
    ${dot(372, 306)}${label(358, 310, 'Bengaluru', 'end')}${label(358, 326, 'füstölők', 'end', 'small-t')}
  </svg>`;
}
