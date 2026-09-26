// Kereső megjelenítés – a prototípus és a WordPress téma közös része. A környezetfüggő dolgokat
// (URL-ek, ár formázás, ikon, kép) a hívó adja át a `h` objektumban:
//   { esc, icon, fmt, productUrl(p), thumb(p), searchUrl(q), shopUrl(q), highlight(text) }

/** A kérdés egy értelmezett részének elhagyása (a címke ×-e). */
export const withoutPart = (q, part) => q.replace(part, ' ').replace(/\s+/g, ' ').trim();

/** Értelmezett szűrők, „erre gondoltál”, javítás és részleges találat jelzése. */
export function noticesHtml(r, q, h, { asLinks = false } = {}) {
  const link = (query, html, cls = 'chip') => (asLinks
    ? `<a class="${cls}" href="${h.esc(h.searchUrl(query))}">${html}</a>`
    : `<button type="button" class="${cls}" data-term="${h.esc(query)}">${html}</button>`);
  const out = [];
  if (r.filters?.length) {
    out.push(`<div class="search-filters" aria-label="Értelmezett szűrők"><span class="text-small text-muted">Szűrve:</span>${r.filters.map((f) => link(withoutPart(q, f.remove), `${h.esc(f.label)} ${h.icon('close', 'ico ico-s')}`, 'chip is-active')).join('')}</div>`);
  }
  if (r.droppedFilters?.length) {
    out.push(`<p class="search-note text-small">${h.icon('info', 'ico ico-s')} <span>A „${h.esc(r.droppedFilters.map((f) => f.label).join(', '))}” feltétellel nincs termék – a hozzá legközelebbi találatokat mutatjuk.</span></p>`);
  }
  if (r.corrections?.length && r.items.length) {
    out.push(`<p class="search-note text-small">${h.icon('sparkle', 'ico ico-s')} <span>Találatok erre is: ${r.corrections.map(([, to]) => `„${h.esc(to)}”`).join(', ')}</span></p>`);
  }
  if (r.didYouMean) {
    out.push(`<p class="search-note">Erre gondoltál: ${link(r.didYouMean, h.esc(r.didYouMean), 'search-dym')}?</p>`);
  }
  if (r.partial && r.items.length) {
    out.push(`<p class="search-note text-small">${h.icon('info', 'ico ico-s')} <span>Nincs olyan termék, amelyben minden szó szerepel – a legjobban illeszkedőket mutatjuk.</span></p>`);
  }
  return out.join('');
}

/** Kategória javaslatok. */
export function catsHtml(r, h) {
  if (!r.cats?.length) return '';
  return `<div class="search-cats"><span class="text-small text-muted">Kategóriában:</span>${r.cats.map((c) => `<a class="chip" href="${h.esc(c.url || '#')}">${h.esc(c.label)}${c.count ? ` <span class="text-muted">(${c.count})</span>` : ''}</a>`).join('')}</div>`;
}

/** Az élő kereső legördülője. */
export function suggestHtml(r, q, h, posts = []) {
  const hits = r.items.slice(0, 6);
  const products = `<div><h2>Termékek (${r.total})</h2>${noticesHtml(r, q, h)}${catsHtml(r, h)}${hits.length
    ? `<ul class="search-hits" role="listbox" aria-label="Termék találatok">${hits.map((p) => `<li role="option"><a href="${h.esc(h.productUrl(p))}" data-search-hit="${p.id}"><span class="thumb">${h.thumb(p)}</span><span>${h.highlight(p.name)}<small>${h.esc(p.catLabel || '')} · Cikkszám: ${h.highlight(p.sku || '–')}</small></span><strong class="num">${h.fmt(p.price)}</strong></a></li>`).join('')}</ul>
       <p style="margin-top:var(--space-4)"><a class="iu-button iu-button-outline" href="${h.esc(h.searchUrl(q))}">Mind a ${r.total + posts.length} találat ${h.icon('arrow', 'ico ico-s')}</a></p>`
    : `<p class="text-muted">Nincs termék erre: „${h.esc(q)}”. Próbáld rövidebben, vagy nézd meg a <a href="${h.esc(h.shopUrl(''))}">teljes kínálatot</a>.</p>`}</div>`;
  const magazine = `<div><h2>Magazin (${posts.length})</h2>${posts.length ? `<ul class="mega-list">${posts.map((a) => `<li><a href="${h.esc(a.url)}">${h.highlight(a.title)}</a></li>`).join('')}</ul>` : '<p class="text-muted text-small">Nincs cikk ezzel a kifejezéssel.</p>'}</div>`;
  return products + magazine;
}
