// iu blokkok viselkedése és több oldalon használt tartalmi blokkok.
import { art } from './art.js';
import { esc, icon, img, $, $$ } from './ui.js';

export const fmtDate = (iso) => new Date(iso).toLocaleDateString('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' });

/** iu/query elem – bejegyzés kártya. */
export function postCard(a) {
  return `<article class="iu-query-item reveal"><a class="post-link" href="cikk.html?a=${encodeURIComponent(a.slug)}">
    <div class="post-thumb">${a.image ? img(a.image, '', { sizes: '(max-width: 991px) 100vw, 33vw' }) : art(a.art, a.tone)}</div>
    <p class="post-meta"><span>${esc(a.category || 'Magazin')}</span><time datetime="${a.date}">${fmtDate(a.date)}</time><span>${a.minutes || 4} perc</span></p>
    <h3>${esc(a.title)}</h3>
    <p>${esc(a.excerpt)}</p>
    <span class="go">Tovább olvasok ${icon('arrow', 'ico ico-s')}</span>
  </a></article>`;
}

export function quoteCard(q) {
  const initials = q.name.split(' ').map((w) => w[0]).join('').slice(0, 2);
  return `<figure class="quote reveal"><blockquote>${esc(q.text)}</blockquote>
    <figcaption><span class="avatar" aria-hidden="true">${esc(initials)}</span><span><strong>${esc(q.name)}</strong>Vásárló</span></figcaption></figure>`;
}

/** Útvonaltérkép (saját blokk: mandala/origin-map). */
export function routeMap() {
  const dot = (x, y, big = false) => `<circle cx="${x}" cy="${y}" r="${big ? 6 : 4}" fill="currentColor"/><circle cx="${x}" cy="${y}" r="${big ? 14 : 10}" fill="none" stroke="currentColor" stroke-opacity=".35"/>`;
  const label = (x, y, text, anchor = 'start', cls = '') => `<text x="${x}" y="${y}" text-anchor="${anchor}" ${cls ? `class="${cls}"` : ''}>${text}</text>`;
  const grid = Array.from({ length: 7 }, (_, i) => `<path d="M0 ${40 + i * 48}H540" stroke="currentColor" stroke-opacity=".08"/>`).join('')
    + Array.from({ length: 10 }, (_, i) => `<path d="M${30 + i * 56} 0V340" stroke="currentColor" stroke-opacity=".08"/>`).join('');
  return `<svg class="route" viewBox="0 0 540 340" role="img" aria-label="Útvonal Nepálból és Indiából Budapestig">${grid}
    <path class="path" d="M482 176C440 70 260 20 70 78" fill="none" stroke="currentColor" stroke-width="1.5"/>
    <path class="path" d="M392 204C330 124 200 70 70 78" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity=".6"/>
    <path d="M346 250L392 204M372 306L346 250" fill="none" stroke="currentColor" stroke-opacity=".35" stroke-dasharray="2 5"/>
    ${dot(70, 78, true)}${label(88, 82, 'Budapest')}${label(88, 100, 'innen indul a csomagod', 'start', 't-small')}
    ${dot(482, 176, true)}${label(482, 212, 'Katmandu', 'middle')}${label(482, 228, 'hangtál, szobor', 'middle', 't-small')}
    ${dot(392, 204)}${label(378, 208, 'Moradabad', 'end')}${label(378, 224, 'réz', 'end', 't-small')}
    ${dot(346, 250)}${label(332, 254, 'Jaipur', 'end')}${label(332, 270, 'textil, ékszer', 'end', 't-small')}
    ${dot(372, 306)}${label(358, 310, 'Bengaluru', 'end')}${label(358, 326, 'füstölők', 'end', 't-small')}</svg>`;
}

/** iu/accordion: jQuery helyett natív, akadálymentes változat (aria-expanded + region). */
export function bindAccordions(root = document) {
  $$('.iu-accordion-item-head', root).forEach((head, i) => {
    const body = head.nextElementSibling;
    if (!body.id) body.id = `acc-${i}-${Math.random().toString(36).slice(2, 7)}`;
    head.setAttribute('aria-controls', body.id);
    body.setAttribute('role', 'region');
    body.hidden = head.getAttribute('aria-expanded') !== 'true';
    head.addEventListener('click', () => {
      const open = head.getAttribute('aria-expanded') !== 'true';
      head.setAttribute('aria-expanded', String(open));
      body.hidden = !open;
    });
  });
}

/** iu/tabs: WAI-ARIA fülek, nyilakkal navigálható. */
export function bindTabs(root = document) {
  $$('.iu-tabs', root).forEach((tabs) => {
    const btns = $$('[role="tab"]', tabs);
    const select = (b, focus) => {
      btns.forEach((x) => { const on = x === b; x.setAttribute('aria-selected', String(on)); x.tabIndex = on ? 0 : -1; $(`#${x.getAttribute('aria-controls')}`).hidden = !on; });
      if (focus) b.focus();
    };
    btns.forEach((b, i) => {
      b.addEventListener('click', () => select(b));
      b.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') select(btns[(i + 1) % btns.length], true);
        if (e.key === 'ArrowLeft') select(btns[(i - 1 + btns.length) % btns.length], true);
      });
    });
  });
}

/** iu-woocommerce/product-carousel: scroll-snap, nyilak, haladásjelző. */
export function bindCarousel(root) {
  const track = $('.carousel-track', root);
  const prev = $('[data-dir="-1"]', root);
  const next = $('[data-dir="1"]', root);
  const bar = $('.carousel-progress span', root);
  const update = () => {
    const max = track.scrollWidth - track.clientWidth;
    prev.disabled = track.scrollLeft < 4;
    next.disabled = track.scrollLeft > max - 4;
    const vis = track.clientWidth / track.scrollWidth;
    bar.style.width = `${vis * 100}%`;
    bar.style.transform = `translateX(${max ? (track.scrollLeft / max) * ((1 - vis) / vis) * 100 : 0}%)`;
  };
  [prev, next].forEach((b) => b.addEventListener('click', () => track.scrollBy({ left: Number(b.dataset.dir) * track.clientWidth * 0.8, behavior: 'smooth' })));
  track.addEventListener('scroll', update, { passive: true });
  addEventListener('resize', update);
  update();
}
export const carouselNav = (label) => `<div class="carousel-nav" role="group" aria-label="${esc(label)} lapozása">
  <button type="button" data-dir="-1" aria-label="Előző">${icon('chevron-left')}</button>
  <button type="button" data-dir="1" aria-label="Következő">${icon('chevron-right')}</button></div>`;
