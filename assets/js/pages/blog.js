// blog_page sablon: iu/title + iu/terms (kategória szűrő) + iu/query (main_query, paging).
import { initPage, $, $$, esc, params, refreshReveal } from '../ui.js';
import { postCard } from '../blocks.js';
import { ARTICLES, ARTICLE_CATEGORIES } from '../data.js';

initPage({ active: 'mag' });
const PER_PAGE = 3;
const p0 = params();
let cat = p0.get('kategoria') || '';
let page = Number(p0.get('oldal')) || 1;

function render(focus) {
  const list = ARTICLES.filter((a) => !cat || a.category === cat);
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  page = Math.min(page, pages);
  $('[data-terms]').innerHTML = [['', 'Összes'], ...ARTICLE_CATEGORIES.map((c) => [c, c])]
    .map(([v, l]) => `<button type="button" class="chip" data-term-cat="${esc(v)}" aria-pressed="${cat === v}">${esc(l)} <span class="text-muted">${ARTICLES.filter((a) => !v || a.category === v).length}</span></button>`).join('');
  $('[data-posts]').innerHTML = list.length ? list.slice((page - 1) * PER_PAGE, page * PER_PAGE).map(postCard).join('')
    : '<div class="empty-state" style="grid-column:1/-1"><h2 style="font-size:var(--fs-h3)">Ebben a kategóriában még nincs cikk</h2><p>Hamarosan érkezik – addig nézz szét a többi között.</p></div>';
  $('[data-paging]').innerHTML = pages > 1 ? [
    page > 1 ? `<button type="button" class="page-numbers prev" data-page="${page - 1}" aria-label="Előző oldal">‹</button>` : '',
    ...Array.from({ length: pages }, (_, i) => i + 1).map((n) => (n === page ? `<span class="page-numbers current" aria-current="page">${n}</span>` : `<button type="button" class="page-numbers" data-page="${n}" aria-label="${n}. oldal">${n}</button>`)),
    page < pages ? `<button type="button" class="page-numbers next" data-page="${page + 1}" aria-label="Következő oldal">›</button>` : '',
  ].join('') : '';
  const u = new URLSearchParams();
  if (cat) u.set('kategoria', cat);
  if (page > 1) u.set('oldal', page);
  history.replaceState(null, '', `${location.pathname}${u.toString() ? `?${u}` : ''}`);
  refreshReveal();
  if (focus) $(focus)?.focus();
}
document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-term-cat],[data-page]');
  if (!t) return;
  if (t.dataset.page) { page = Number(t.dataset.page); render('[data-posts] a'); $('[data-terms]').scrollIntoView({ behavior: 'smooth' }); return; }
  cat = t.dataset.termCat; page = 1;
  render(`[data-term-cat="${CSS.escape(cat)}"]`);
});
render();
