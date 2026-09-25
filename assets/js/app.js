// Közös elemek: fejléc, megamenü, mobilmenü, kereső, kosárfiók, lábléc, termékkártya.
import { CONFIG, CATEGORIES, INTENTS, ORIGINS } from './data.js';
import { art, logoMark } from './art.js';
import { cart, cartDetails, fmt, loadProducts, subLabel } from './store.js';

export const esc = (s = '') => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const params = () => new URLSearchParams(location.search);

const ICON_PATHS = {
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  bag: '<path d="M5 8h14l-1 12H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  close: '<path d="M6 6l12 12M18 6 6 18"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  minus: '<path d="M5 12h14"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  chevron: '<path d="m6 9 6 6 6-6"/>',
  truck: '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17" r="1.8"/><circle cx="17" cy="17" r="1.8"/>',
  hand: '<path d="M7 11V6.5a1.5 1.5 0 0 1 3 0V11m0-1V5a1.5 1.5 0 0 1 3 0v5m0 0V6.5a1.5 1.5 0 0 1 3 0V13c0 4-2.5 7-6 7s-5-2-6.5-4.5L5 12.5a1.5 1.5 0 0 1 2.5-1.5z"/>',
  return: '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
  pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
  leaf: '<path d="M5 19c0-8 6-14 15-14 0 9-6 15-14 15"/><path d="M5 19 14 10"/>',
  clock: '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
  filter: '<path d="M4 6h16M7 12h10M10 18h4"/>',
  check: '<path d="m5 12 5 5 9-10"/>',
  facebook: '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v7h4v-7h3l1-4h-4V8z"/>',
  instagram: '<rect x="4" y="4" width="16" height="16" rx="4.5"/><circle cx="12" cy="12" r="3.5"/><circle cx="17" cy="7" r=".6" fill="currentColor"/>',
};
export const icon = (name, cls = 'ico') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${ICON_PATHS[name] || ''}</svg>`;

const img = (name, alt, { eager = false, sizes = '100vw', w } = {}) => {
  const big = w || 1600;
  return `<img src="assets/img/${name}-800.webp" srcset="assets/img/${name}-800.webp 800w, assets/img/${name}-${big}.webp ${big}w" sizes="${sizes}" alt="${esc(alt)}" ${eager ? 'fetchpriority="high"' : 'loading="lazy"'} decoding="async">`;
};
export { img };

// ---------- Termékkártya ----------

export function productMedia(p, { large = false } = {}) {
  if (p.image) return `<img src="${esc(p.image)}" alt="${esc(p.name)}" loading="${large ? 'eager' : 'lazy'}" decoding="async">`;
  return art(p.art, p.tone, { label: p.name });
}

export function productCard(p) {
  const origin = ORIGINS[p.origin];
  const sale = p.compare && p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0;
  const url = `termek.html?p=${encodeURIComponent(p.slug)}`;
  return `<article class="card">
    <a class="card-media" href="${url}" tabindex="-1" aria-hidden="true">
      ${productMedia(p)}
      <span class="badges">${p.isNew ? '<span class="badge">Új</span>' : ''}${sale ? `<span class="badge badge-sale">−${sale}%</span>` : ''}</span>
    </a>
    <div class="card-body">
      <p class="card-meta"><span class="origin-dot" style="--dot:${origin?.tone}"></span>${esc(origin?.label || '')} · ${esc(subLabel(p.cat, p.sub))}</p>
      <h3 class="card-title"><a href="${url}">${esc(p.name)}</a></h3>
      <div class="card-foot">
        <span class="price">${fmt(p.price)}${sale ? ` <s>${fmt(p.compare)}</s>` : ''}</span>
        <button class="btn-add" type="button" data-add="${p.id}" ${p.stock === 'out' ? 'disabled' : ''} aria-label="Kosárba: ${esc(p.name)}">${icon('plus')}</button>
      </div>
    </div>
  </article>`;
}

// ---------- Fejléc ----------

function megaMenu() {
  const cols = CATEGORIES.map((c) => `
    <div class="mega-col">
      <a class="mega-head" href="termekek.html?cat=${c.slug}">${esc(c.label)}</a>
      <ul>${c.subs.map(([s, l]) => `<li><a href="termekek.html?cat=${c.slug}&sub=${s}">${esc(l)}</a></li>`).join('')}</ul>
    </div>`).join('');
  return `<div class="mega" id="mega" hidden>
    <div class="container mega-inner">
      ${cols}
      <div class="mega-col">
        <span class="mega-head">Szándék szerint</span>
        <ul>${INTENTS.map((i) => `<li><a href="termekek.html?intent=${i.id}">${esc(i.label)}</a></li>`).join('')}</ul>
        <span class="mega-head mega-head-2">Eredet szerint</span>
        <ul>${Object.entries(ORIGINS).map(([k, o]) => `<li><a href="termekek.html?origin=${k}">${esc(o.label)}</a></li>`).join('')}</ul>
      </div>
      <a class="mega-feature" href="termekek.html?sub=hangtalak&cat=szakralis-targyak">
        ${img('hangtalak-studio', 'Nepáli hangtálak ütőkkel', { sizes: '320px' })}
        <span><strong>Hangtál-kalauz</strong> Frekvencia, hang és csakra szerint</span>
      </a>
    </div>
  </div>`;
}

function header(active) {
  const link = (href, label, key) => `<a href="${href}" ${active === key ? 'aria-current="page"' : ''}>${label}</a>`;
  return `
  <a class="skip" href="#main">Ugrás a tartalomra</a>
  <div class="topbar"><div class="container topbar-inner">
    <p>${icon('truck')} Ingyenes szállítás ${fmt(CONFIG.freeShippingFrom)} felett <span class="sep">·</span> <span class="hide-sm">Közvetlen import Indiából és Nepálból</span></p>
    <nav aria-label="Kiegészítő"><a href="viszonteladoknak.html">Viszonteladóknak</a><a href="kapcsolat.html">Kapcsolat</a></nav>
  </div></div>
  <div class="masthead"><div class="container masthead-inner">
    <button class="icon-btn only-mobile" type="button" data-open="drawer" aria-label="Menü">${icon('menu')}</button>
    <a class="logo" href="index.html" aria-label="Mandala – kezdőlap">${logoMark}<span>Mandala</span></a>
    <nav class="mainnav" aria-label="Fő">
      <button class="nav-trigger" type="button" aria-expanded="false" aria-controls="mega" ${active === 'shop' ? 'aria-current="page"' : ''}>Kínálat ${icon('chevron', 'ico ico-sm')}</button>
      ${link('termekek.html?sort=new', 'Újdonságok', 'new')}
      ${link('rolunk.html', 'Eredetünk', 'about')}
      ${link('magazin.html', 'Magazin', 'mag')}
      ${link('viszonteladoknak.html', 'Viszonteladóknak', 'b2b')}
    </nav>
    <div class="masthead-actions">
      <button class="icon-btn" type="button" data-open="search" aria-label="Keresés">${icon('search')}</button>
      <button class="icon-btn cart-btn" type="button" data-open="cart" aria-label="Kosár">${icon('bag')}<span class="cart-count" data-cart-count hidden>0</span></button>
    </div>
  </div>${megaMenu()}</div>`;
}

function mobileDrawer() {
  return `<div class="drawer drawer-left" id="drawer" hidden>
    <div class="drawer-panel" role="dialog" aria-modal="true" aria-label="Menü">
      <div class="drawer-head"><a class="logo" href="index.html">${logoMark}<span>Mandala</span></a>
        <button class="icon-btn" type="button" data-close aria-label="Bezárás">${icon('close')}</button></div>
      <nav class="mnav" aria-label="Mobil">
        ${CATEGORIES.map((c) => `<details><summary>${esc(c.label)} ${icon('chevron', 'ico ico-sm')}</summary>
          <ul><li><a href="termekek.html?cat=${c.slug}">Összes ${esc(c.label.toLowerCase())}</a></li>
          ${c.subs.map(([s, l]) => `<li><a href="termekek.html?cat=${c.slug}&sub=${s}">${esc(l)}</a></li>`).join('')}</ul></details>`).join('')}
        <a href="termekek.html?sort=new">Újdonságok</a>
        <a href="rolunk.html">Eredetünk</a>
        <a href="magazin.html">Magazin</a>
        <a href="viszonteladoknak.html">Viszonteladóknak</a>
        <a href="kapcsolat.html">Kapcsolat</a>
      </nav>
      <p class="drawer-note">${icon('truck')} Ingyenes szállítás ${fmt(CONFIG.freeShippingFrom)} felett</p>
    </div>
  </div>`;
}

function cartDrawer() {
  return `<div class="drawer drawer-right" id="cart" hidden>
    <div class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="cart-title">
      <div class="drawer-head"><h2 id="cart-title">Kosár</h2>
        <button class="icon-btn" type="button" data-close aria-label="Bezárás">${icon('close')}</button></div>
      <div class="cart-body" data-cart-body></div>
    </div>
  </div>`;
}

function searchDialog() {
  return `<div class="search" id="search" hidden>
    <div class="search-panel" role="dialog" aria-modal="true" aria-label="Keresés">
      <form class="container search-form" action="termekek.html" role="search">
        ${icon('search')}
        <label class="sr-only" for="q">Keresés a termékek között</label>
        <input id="q" name="q" type="search" placeholder="Hangtál, füstölő, mala, Buddha szobor…" autocomplete="off">
        <button class="icon-btn" type="button" data-close aria-label="Bezárás">${icon('close')}</button>
      </form>
      <div class="container search-results" data-search-results aria-live="polite"></div>
    </div>
  </div>`;
}

// ---------- Hírlevél és lábléc ----------

function newsletter() {
  return `<section class="newsletter" aria-labelledby="nl-title"><div class="container newsletter-inner">
    <div>
      <p class="eyebrow">Hírlevél</p>
      <h2 id="nl-title">Csendes levelek, havonta kétszer</h2>
      <p>Új érkezések Nepálból és Indiából, magazincikkek és előfizetői kedvezmények. Nincs zaj, csak ami számít.</p>
    </div>
    <form class="nl-form" novalidate data-newsletter>
      <div class="nl-row">
        <label class="sr-only" for="nl-email">E-mail-cím</label>
        <input id="nl-email" type="email" name="email" placeholder="E-mail-címed" required autocomplete="email">
        <button class="btn btn-primary" type="submit">Feliratkozom</button>
      </div>
      <label class="check"><input type="checkbox" name="consent" required> <span>Elfogadom az <a href="informaciok.html#adatvedelem">adatkezelési tájékoztatót</a>.</span></label>
      <p class="form-msg" role="status"></p>
    </form>
  </div></section>`;
}

function footer() {
  const c = CONFIG.contact;
  return `<footer class="footer"><div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a class="logo" href="index.html">${logoMark}<span>Mandala</span></a>
        <p>Hangtálak, füstölők, szobrok, textilek és ajándékok – kézzel válogatva Nepál és India műhelyeiből, hogy a csendnek otthon is helye legyen.</p>
        <div class="social"><a href="${c.facebook}" aria-label="Facebook" rel="noopener" target="_blank">${icon('facebook')}</a></div>
      </div>
      <nav aria-label="Kínálat"><h3>Kínálat</h3><ul>
        ${CATEGORIES.map((cat) => `<li><a href="termekek.html?cat=${cat.slug}">${esc(cat.label)}</a></li>`).join('')}
        <li><a href="termekek.html?sort=new">Újdonságok</a></li>
        <li><a href="termekek.html?sale=1">Akciók</a></li>
      </ul></nav>
      <nav aria-label="Információk"><h3>Információk</h3><ul>
        <li><a href="informaciok.html#szallitas">Szállítás</a></li>
        <li><a href="informaciok.html#fizetes">Fizetés</a></li>
        <li><a href="informaciok.html#visszakuldes">Visszaküldés</a></li>
        <li><a href="informaciok.html#aszf">ÁSZF</a></li>
        <li><a href="informaciok.html#adatvedelem">Adatvédelem</a></li>
        <li><a href="informaciok.html#impresszum">Impresszum</a></li>
      </ul></nav>
      <div><h3>Kapcsolat</h3><ul class="contact-list">
        <li>${icon('mail')}<a href="mailto:${c.email}">${c.email}</a></li>
        <li>${icon('phone')}<a href="tel:${c.phone.replace(/\s/g, '')}">${c.phone}</a></li>
        <li>${icon('pin')}<span>${esc(c.address)}</span></li>
        <li>${icon('clock')}<span>${esc(c.hours)}</span></li>
      </ul></div>
    </div>
    <div class="footer-bottom">
      <p>© ${new Date().getFullYear()} Mandala. Minden jog fenntartva.</p>
      <p class="pay">Bankkártya · Előre utalás · Utánvét</p>
    </div>
  </div></footer>`;
}

// ---------- Viselkedés ----------

let lastFocus;
function openLayer(id) {
  const el = document.getElementById(id);
  if (!el) return;
  closeLayers();
  lastFocus = document.activeElement;
  el.hidden = false;
  requestAnimationFrame(() => el.classList.add('open'));
  document.body.classList.add('locked');
  const focusTarget = el.querySelector('input, [data-close]');
  focusTarget?.focus();
  if (id === 'cart') renderCart();
}
function closeLayers() {
  $$('.drawer.open, .search.open').forEach((el) => {
    el.classList.remove('open');
    setTimeout(() => { if (!el.classList.contains('open')) el.hidden = true; }, 250);
  });
  document.body.classList.remove('locked');
  lastFocus?.focus?.();
}
export const openCart = () => openLayer('cart');

async function renderCart() {
  const body = $('[data-cart-body]');
  if (!body) return;
  const { items, subtotal, remaining, freeShipping } = await cartDetails();
  if (!items.length) {
    body.innerHTML = `<div class="cart-empty">${icon('bag', 'ico ico-xl')}<p>A kosarad még üres.</p>
      <a class="btn btn-ghost" href="termekek.html">Nézz körül a kínálatban</a></div>`;
    return;
  }
  const pct = Math.min(100, (subtotal / CONFIG.freeShippingFrom) * 100);
  body.innerHTML = `
    <div class="ship-meter">
      <p>${freeShipping ? `${icon('check')} A szállítás ingyenes.` : `Még <strong>${fmt(remaining)}</strong> és ingyen szállítunk.`}</p>
      <div class="meter"><span style="width:${pct}%"></span></div>
    </div>
    <ul class="cart-lines">${items.map(cartLine).join('')}</ul>
    <div class="cart-foot">
      <div class="row"><span>Részösszeg</span><strong>${fmt(subtotal)}</strong></div>
      <p class="muted small">A szállítási díjat a pénztárban számoljuk.</p>
      <a class="btn btn-primary btn-block" href="kosar.html#penztar">Tovább a pénztárhoz</a>
      <a class="btn btn-link btn-block" href="kosar.html">Kosár megtekintése</a>
    </div>`;
}

export function cartLine({ product: p, qty }) {
  return `<li class="cart-line">
    <a class="cart-thumb" href="termek.html?p=${encodeURIComponent(p.slug)}">${productMedia(p)}</a>
    <div class="cart-info">
      <a href="termek.html?p=${encodeURIComponent(p.slug)}">${esc(p.name)}</a>
      <span class="muted small">${fmt(p.price)} / db</span>
      <div class="qty" data-qty="${p.id}">
        <button type="button" data-step="-1" aria-label="Eggyel kevesebb">${icon('minus')}</button>
        <input type="number" min="1" max="99" value="${qty}" aria-label="Mennyiség: ${esc(p.name)}">
        <button type="button" data-step="1" aria-label="Eggyel több">${icon('plus')}</button>
      </div>
    </div>
    <div class="cart-side"><strong>${fmt(p.price * qty)}</strong>
      <button class="btn-link small" type="button" data-remove="${p.id}">Törlés</button></div>
  </li>`;
}

function updateCount() {
  const n = cart.count();
  $$('[data-cart-count]').forEach((el) => { el.textContent = n; el.hidden = n === 0; });
}

export function toast(html) {
  let wrap = $('.toasts');
  if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toasts'; wrap.setAttribute('aria-live', 'polite'); document.body.append(wrap); }
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = html;
  wrap.append(t);
  requestAnimationFrame(() => t.classList.add('in'));
  setTimeout(() => { t.classList.remove('in'); setTimeout(() => t.remove(), 300); }, 3600);
}

export async function addToCart(id, qty = 1) {
  const products = await loadProducts();
  const p = products.find((x) => x.id === id);
  if (!p) return;
  cart.add(id, qty);
  const btn = $('.cart-btn');
  btn?.classList.remove('bump'); void btn?.offsetWidth; btn?.classList.add('bump');
  toast(`${icon('check')}<span><strong>Kosárba tettük:</strong> ${esc(p.name)}</span><button type="button" class="btn-link" data-open="cart">Kosár</button>`);
}

async function runSearch(q) {
  const box = $('[data-search-results]');
  const term = q.trim().toLowerCase();
  if (term.length < 2) {
    box.innerHTML = `<p class="muted small">Népszerű: ${['hangtál', 'füstölő', 'mala', 'Buddha', 'réz kulacs'].map((t) => `<button type="button" class="chip" data-term="${t}">${t}</button>`).join(' ')}</p>`;
    return;
  }
  const products = await loadProducts();
  const norm = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const t = norm(term);
  const hits = products.filter((p) => norm(`${p.name} ${subLabel(p.cat, p.sub)} ${p.short}`).includes(t)).slice(0, 6);
  box.innerHTML = hits.length
    ? `<ul class="search-list">${hits.map((p) => `<li><a href="termek.html?p=${encodeURIComponent(p.slug)}"><span class="search-thumb">${productMedia(p)}</span><span>${esc(p.name)}<small>${esc(subLabel(p.cat, p.sub))}</small></span><strong>${fmt(p.price)}</strong></a></li>`).join('')}</ul>
       <a class="btn btn-link" href="termekek.html?q=${encodeURIComponent(q)}">Összes találat ${icon('arrow')}</a>`
    : `<p class="muted">Nincs találat erre: „${esc(q)}”. Próbáld rövidebb kifejezéssel.</p>`;
}

function initNewsletter(form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = $('.form-msg', form);
    const email = form.email.value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { msg.textContent = 'Kérjük, adj meg egy érvényes e-mail-címet.'; msg.className = 'form-msg err'; form.email.focus(); return; }
    if (!form.consent.checked) { msg.textContent = 'A feliratkozáshoz fogadd el az adatkezelési tájékoztatót.'; msg.className = 'form-msg err'; return; }
    msg.textContent = 'Köszönjük! Hamarosan küldjük a megerősítő levelet.';
    msg.className = 'form-msg ok';
    form.reset();
  });
}

function initMega() {
  const trigger = $('.nav-trigger');
  const mega = $('#mega');
  if (!trigger || !mega) return;
  let timer;
  let openedAt = 0;
  const open = () => { clearTimeout(timer); if (mega.hidden) openedAt = Date.now(); mega.hidden = false; trigger.setAttribute('aria-expanded', 'true'); requestAnimationFrame(() => mega.classList.add('open')); };
  const close = () => { mega.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false'); timer = setTimeout(() => { mega.hidden = true; }, 180); };
  // Egérrel a hover már megnyitotta: az azt követő kattintás ne zárja be azonnal.
  trigger.addEventListener('click', () => (trigger.getAttribute('aria-expanded') === 'true' && Date.now() - openedAt > 400 ? close() : open()));
  const hoverable = matchMedia('(hover: hover)').matches;
  if (hoverable) {
    [trigger, mega].forEach((el) => {
      el.addEventListener('mouseenter', open);
      el.addEventListener('mouseleave', () => { timer = setTimeout(close, 160); });
    });
  }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !mega.hidden) { close(); trigger.focus(); } });
  document.addEventListener('click', (e) => { if (!mega.hidden && !mega.contains(e.target) && !trigger.contains(e.target)) close(); });
}

function initReveal() {
  const els = $$('.reveal');
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) { els.forEach((e) => e.classList.add('in')); return; }
  const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px' });
  els.forEach((e) => io.observe(e));
}
export const refreshReveal = initReveal;

/** Oldal inicializálása: közös elemek beillesztése és eseménykezelők. */
export function initPage({ active = '', newsletter: withNewsletter = true } = {}) {
  $('#site-header').innerHTML = header(active);
  const foot = $('#site-footer');
  foot.outerHTML = `${withNewsletter ? newsletter() : ''}${footer()}`;
  document.body.insertAdjacentHTML('beforeend', mobileDrawer() + cartDrawer() + searchDialog());

  updateCount();
  document.addEventListener('cart:change', () => { updateCount(); if ($('#cart')?.classList.contains('open')) renderCart(); });

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-open],[data-close],[data-add],[data-step],[data-remove],[data-term]');
    if (!t) {
      if (e.target.classList?.contains('drawer') || e.target.classList?.contains('search')) closeLayers();
      return;
    }
    if (t.dataset.open) { e.preventDefault(); openLayer(t.dataset.open); }
    else if (t.hasAttribute('data-close')) closeLayers();
    else if (t.dataset.add) addToCart(Number(t.dataset.add));
    else if (t.dataset.step) {
      const box = t.closest('[data-qty]');
      const input = $('input', box);
      const v = Math.max(1, Math.min(99, Number(input.value) + Number(t.dataset.step)));
      input.value = v;
      if (box.dataset.qty) cart.set(Number(box.dataset.qty), v);
    } else if (t.dataset.remove) cart.remove(Number(t.dataset.remove));
    else if (t.dataset.term) { const q = $('#q'); q.value = t.dataset.term; runSearch(q.value); q.focus(); }
  });
  document.addEventListener('change', (e) => {
    const box = e.target.closest('[data-qty]');
    if (box?.dataset.qty && e.target.matches('input')) cart.set(Number(box.dataset.qty), Math.max(1, Number(e.target.value) || 1));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeLayers();
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) { e.preventDefault(); openLayer('search'); }
  });
  $$('.drawer, .search').forEach((layer) => layer.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;
    const f = $$('a[href], button:not([disabled]), input, select, textarea, summary', layer).filter((el) => el.offsetParent !== null);
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
    else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
  }));

  let deb;
  $('#q').addEventListener('input', (e) => { clearTimeout(deb); deb = setTimeout(() => runSearch(e.target.value), 120); });
  runSearch('');

  $$('[data-newsletter]').forEach(initNewsletter);
  initMega();

  const mast = $('.masthead');
  const onScroll = () => mast.classList.toggle('scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();

  initReveal();
}

export function setMeta({ title, description }) {
  if (title) document.title = `${title} – Mandala`;
  if (description) $('meta[name="description"]')?.setAttribute('content', description);
}
