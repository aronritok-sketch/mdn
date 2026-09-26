// Találati oldal (mandala/search-results): a szerver által kirakott első eredményt a böngészőben a
// teljes keresőmotor finomítja – elírás-tűrés, „500 g alatt” / „432 Hz” értelmezés, „Erre gondoltál?”,
// kategória javaslat –, és naplózza a keresést a statisztikához.
import { $, esc, icon, fmt, loadProducts, productCard, refreshReveal, logSearch } from './env.js';
import { getCorpus } from './search-engine.js';
import { noticesHtml, catsHtml } from './search-ui.js';

const M = window.MANDALA || {};
const root = $('[data-search-page]');

if (root) {
  const q = root.dataset.q || '';
  const box = $('[data-search-products]', root);
  const h = {
    esc, icon, fmt,
    productUrl: (p) => p.url,
    thumb: () => '',
    searchUrl: (query) => `${M.search}?s=${encodeURIComponent(query)}`,
    shopUrl: (query) => `${M.shop}${M.shop.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}`,
    highlight: (text) => esc(text),
  };
  const PAGE = 24;
  let shown = PAGE;

  loadProducts().then(() => {
    const r = getCorpus()?.search(q);
    if (!r) return;
    logSearch(q, r.total, 'page');
    const notices = noticesHtml(r, q, h, { asLinks: true }) + catsHtml(r, h);
    if (!r.items.length) {
      // A szerver üres állapota marad, fölé kerül az „Erre gondoltál?” / értelmezés.
      if (notices) box.insertAdjacentHTML('afterbegin', `<div class="search-notices">${notices}</div>`);
      return;
    }
    const render = () => {
      box.innerHTML = `<div class="search-notices">${notices}</div>
        <h2 style="font-size:var(--fs-h3)">Termékek <span class="text-muted">(${r.total})</span></h2>
        <ul class="products columns-4">${r.items.slice(0, shown).map(productCard).join('')}</ul>
        ${r.total > shown ? `<p class="load-more"><button type="button" class="iu-button iu-button-outline" data-more-results>Továbbiak (${r.total - shown}) ${icon('chevron', 'ico ico-s')}</button>
          <a class="iu-button iu-button-link" href="${esc(h.shopUrl(q))}">Szűrés a kínálatban ${icon('arrow', 'ico ico-s')}</a></p>` : ''}`;
      refreshReveal();
    };
    render();
    box.addEventListener('click', (e) => {
      if (e.target.closest('[data-more-results]')) { shown += PAGE; render(); return; }
      const card = e.target.closest('li.product[data-id] a:not(.add_to_cart_button)');
      if (card) logSearch(q, r.total, 'page', Number(card.closest('li.product').dataset.id));
    });
  });
}
