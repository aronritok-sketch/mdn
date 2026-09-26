// search_content sablon: termékek és cikkek; üres találat állapot.
import { initPage, $, esc, icon, params, setMeta, productCard, searchResult, searchPosts, searchHelpers, refreshReveal } from '../ui.js';
import { noticesHtml, catsHtml } from '../search-ui.js';
import { postCard } from '../blocks.js';
import { CATEGORIES } from '../data.js';
import { loadProducts } from '../store.js';

initPage();
const q = (params().get('s') || '').trim();
$('#s').value = q;
$('[data-search-title]').textContent = q ? `Keresés erre: „${q}”` : 'Keresés';
setMeta({ title: q ? `Keresés: ${q}` : 'Keresés' });
const box = $('[data-search]');
if (!q) {
  box.innerHTML = `<p class="lead">Írd be, mit keresel – terméknevet, cikkszámot vagy témát.</p><div class="chip-row">${CATEGORIES.map((c) => `<a class="chip" href="termekek.html?cat=${c.slug}">${esc(c.label)}</a>`).join('')}</div>`;
} else {
  const r = searchResult(await loadProducts(), q);
  const products = r.items;
  const posts = searchPosts(q);
  const h = searchHelpers(q);
  if (!products.length && !posts.length) {
    box.innerHTML = `<div class="empty-state">${icon('search', 'ico ico-xl')}<h2 style="font-size:var(--fs-h3)">Nincs találat erre: „${esc(q)}”</h2>${noticesHtml(r, q, h, { asLinks: true })}
      <p>Ellenőrizd a helyesírást, próbálj rövidebb vagy általánosabb kifejezést (pl. „tál” a „hangtálak” helyett).</p>
      <div class="chip-row" style="justify-content:center">${['hangtál', 'füstölő', 'mala', 'Buddha'].map((t) => `<a class="chip" href="kereses.html?s=${encodeURIComponent(t)}">${t}</a>`).join('')}</div>
      <div class="iu-button-group iu-button-group-center"><a class="iu-button" href="termekek.html">Teljes kínálat</a><a class="iu-button iu-button-outline" href="kapcsolat.html">Kérdezz tőlünk</a></div></div>`;
  } else {
    box.innerHTML = `${noticesHtml(r, q, h, { asLinks: true })}${catsHtml(r, h)}${products.length ? `<h2 style="font-size:var(--fs-h3)">Termékek <span class="text-muted">(${products.length})</span></h2><ul class="products columns-4">${products.map(productCard).join('')}</ul>` : ''}
      ${posts.length ? `<h2 style="font-size:var(--fs-h3);margin-top:var(--space-8)">Magazin <span class="text-muted">(${posts.length})</span></h2><div class="iu-query iu-query-col-3">${posts.map(postCard).join('')}</div>` : ''}`;
  }
}
refreshReveal();
