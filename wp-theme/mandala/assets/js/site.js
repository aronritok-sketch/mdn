// Mandala – közös viselkedés minden oldalon: fejléc, megamenü, mobilmenü, élő kereső,
// minikosár (WooCommerce fragmentek), kedvencek, cookie sáv, értesítések, karusszel,
// fülek, beúszás, másolás gombok, készletértesítő.
import { $, $$, esc, icon, fmt, loadProducts, wishlist, storage, refreshReveal, reducedMotion, logSearch } from './env.js';
import { getCorpus } from './search-engine.js';
import { suggestHtml } from './search-ui.js';
import { bindValidation, validateForm } from './validate.js';

const M = window.MANDALA || {};
const jq = () => window.jQuery;
document.documentElement.classList.add('has-js');

// ---------- Réteg- és fókuszkezelés ----------
let lastFocus = null;
const openLayers = [];
export function openLayer(el, focusSel) {
  if (!el) return;
  lastFocus = document.activeElement;
  el.hidden = false;
  requestAnimationFrame(() => el.classList.add('is-open'));
  document.body.classList.add('is-locked');
  openLayers.push(el);
  setTimeout(() => (focusSel ? $(focusSel, el) : $('input, button, a[href]', el))?.focus(), 50);
}
export function closeLayer(el = openLayers.at(-1)) {
  if (!el) return;
  el.classList.remove('is-open');
  const i = openLayers.indexOf(el);
  if (i > -1) openLayers.splice(i, 1);
  setTimeout(() => { if (!el.classList.contains('is-open')) el.hidden = true; }, reducedMotion() ? 0 : 260);
  if (!openLayers.length) document.body.classList.remove('is-locked');
  lastFocus?.focus?.();
}
function trapFocus(e) {
  const layer = openLayers.at(-1);
  if (!layer || e.key !== 'Tab') return;
  const f = $$('a[href], button:not([disabled]), input:not([disabled]), select, textarea, summary', layer).filter((x) => x.offsetParent !== null);
  if (!f.length) return;
  if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
  else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
}

export function modal({ id, title, body }) {
  $(`#${id}`)?.remove();
  document.body.insertAdjacentHTML('beforeend', `<div class="iu-modal" id="${id}" hidden><div class="iu-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="${id}-title">
    <div class="iu-modal-head"><h2 id="${id}-title">${esc(title)}</h2><button type="button" class="icon-button" data-close aria-label="Bezárás">${icon('close')}</button></div>
    <div class="iu-modal-body">${body}</div></div></div>`);
  const el = $(`#${id}`);
  openLayer(el);
  return el;
}

// ---------- Értesítés ----------
export function toast(html, { action = '', timeout = 4200 } = {}) {
  let wrap = $('.toasts');
  if (!wrap) { wrap = document.createElement('div'); wrap.className = 'toasts'; wrap.setAttribute('role', 'status'); wrap.setAttribute('aria-live', 'polite'); document.body.append(wrap); }
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = `${icon('check')}<span>${html}</span>${action}`;
  wrap.append(t);
  requestAnimationFrame(() => t.classList.add('is-in'));
  const kill = () => { t.classList.remove('is-in'); setTimeout(() => t.remove(), 300); };
  const timer = setTimeout(kill, timeout);
  t.addEventListener('click', (e) => { if (e.target.closest('button, a')) { clearTimeout(timer); kill(); } });
  return t;
}

// ---------- Fejléc ----------
const header = $('.site-masthead')?.closest('header');
header?.classList.add('site-header');
const onScroll = () => header?.classList.toggle('is-scrolled', scrollY > 8);
addEventListener('scroll', onScroll, { passive: true });
onScroll();

const trigger = $('.mega-trigger');
const panel = $('#mega-panel');
let megaTimer; let megaOpenedAt = 0;
function toggleMega(open) {
  if (!panel || !trigger) return;
  clearTimeout(megaTimer);
  if (open) { if (panel.hidden) megaOpenedAt = Date.now(); panel.hidden = false; requestAnimationFrame(() => panel.classList.add('is-open')); }
  else { panel.classList.remove('is-open'); megaTimer = setTimeout(() => { panel.hidden = true; }, 180); }
  trigger.setAttribute('aria-expanded', String(open));
}
if (trigger && panel && matchMedia('(hover: hover) and (min-width: 992px)').matches) {
  [trigger, panel].forEach((el) => {
    el.addEventListener('mouseenter', () => toggleMega(true));
    el.addEventListener('mouseleave', () => { megaTimer = setTimeout(() => toggleMega(false), 160); });
  });
}

// ---------- Kereső ----------
// A motor (search-engine.js): ragozás, elírás, szinonimák, kérdés-értelmezés, rangsor.
const thumb = (p) => (p.img ? `<img src="${esc(p.img)}" alt="" loading="lazy">` : `<img class="art" src="${esc(p.art)}" alt="" loading="lazy">`);
export const searchHelpers = (q) => ({
  esc, icon, fmt, thumb,
  productUrl: (p) => p.url,
  searchUrl: (query) => `${M.search}?s=${encodeURIComponent(query)}`,
  shopUrl: (query) => (query ? `${M.shop}${M.shop.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}` : M.shop),
  highlight: (text) => (getCorpus() ? getCorpus().highlight(text, q) : esc(text)),
});
let searchSeq = 0;
let logTimer;
async function runSearch(q) {
  const box = $('#search-results');
  const input = $('#search-input');
  if (!box || !input) return;
  const term = q.trim();
  const seq = ++searchSeq;
  clearTimeout(logTimer);
  if (term.length < 2) {
    input.setAttribute('aria-expanded', 'false');
    box.innerHTML = `<div><h2>Népszerű keresések</h2><div class="chip-row">${(M.searchConfig?.popular?.length ? M.searchConfig.popular : ['hangtál', 'tibeti füstölő', 'mala', 'Buddha szobor', 'réz kulacs', 'hangtál 500 g alatt']).map((x) => `<button type="button" class="chip" data-term="${esc(x)}">${esc(x)}</button>`).join('')}</div></div>
      <div><h2>Kategóriák</h2><ul class="mega-list">${(M.categories || []).map((c) => `<li><a href="${esc(c.url)}">${esc(c.label)}</a></li>`).join('')}</ul></div>`;
    return;
  }
  const [list, posts] = await Promise.all([
    loadProducts(),
    fetch(`${M.rest}posts?q=${encodeURIComponent(term)}`).then((r) => (r.ok ? r.json() : [])).catch(() => []),
  ]);
  if (seq !== searchSeq) return;
  const r = getCorpus()?.search(term) || { items: [], total: 0, filters: [], cats: [] };
  input.setAttribute('aria-expanded', String(r.items.length > 0));
  box.innerHTML = suggestHtml(r, term, searchHelpers(term), posts);
  box.dataset.q = term;
  box.dataset.n = String(r.total);
  // A „félbehagyott” keresés is számít (pl. nulla találat): 2 mp szünet után naplózzuk.
  logTimer = setTimeout(() => logSearch(term, r.total, 'live'), 2000);
}
// Találatra kattintás: a kifejezés + melyik termék (a statisztika „kattintás” oszlopa).
$('#search-results')?.addEventListener('click', (e) => {
  const hit = e.target.closest('[data-search-hit]');
  const box = e.currentTarget;
  if (hit) { clearTimeout(logTimer); logSearch(box.dataset.q, Number(box.dataset.n || 0), 'live', Number(hit.dataset.searchHit)); }
});
const searchInput = $('#search-input');
let searchTimer;
// A termékindex (és a keresőmotor) előtöltése már a kereső felé mozduláskor.
$$('[data-open-search]').forEach((el) => ['pointerenter', 'focus'].forEach((ev) => el.addEventListener(ev, () => loadProducts(), { once: true })));
searchInput?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => runSearch(searchInput.value), 140); });
searchInput?.addEventListener('keydown', (e) => {
  if (!['ArrowDown', 'ArrowUp'].includes(e.key)) return;
  const links = $$('#search-results .search-hits a');
  if (!links.length) return;
  e.preventDefault();
  const i = links.findIndex((a) => a.classList.contains('is-active'));
  links.forEach((a) => a.classList.remove('is-active'));
  const next = links[(i + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length];
  next.classList.add('is-active');
  next.focus();
});

// ---------- Minikosár (WooCommerce fragmentek) ----------
const minicart = $('#minicart');
export const openCart = () => { if (minicart && !minicart.classList.contains('is-open')) openLayer(minicart); };
function applyFragments(fragments) {
  if (!fragments) return;
  Object.entries(fragments).forEach(([sel, htmlStr]) => { $$(sel).forEach((el) => { el.outerHTML = htmlStr; }); });
  jq()?.(document.body).trigger('wc_fragments_refreshed');
}
async function setQty(key, qty) {
  const body = new URLSearchParams({ key, qty: String(qty), security: M.cartNonce || '' });
  const res = await fetch(String(M.wcAjax).replace('%%endpoint%%', 'mandala_set_qty'), { method: 'POST', credentials: 'same-origin', body });
  if (!res.ok) return;
  const data = await res.json();
  applyFragments(data.fragments);
  if (data.cart_hash) try { sessionStorage.setItem('wc_cart_hash', data.cart_hash); } catch { /* */ }
}
async function addToCart(id, qty = 1) {
  const body = new URLSearchParams({ product_id: String(id), quantity: String(qty) });
  const res = await fetch(String(M.wcAjax).replace('%%endpoint%%', 'add_to_cart'), { method: 'POST', credentials: 'same-origin', body });
  const data = await res.json().catch(() => null);
  if (!data || data.error) { if (data?.product_url) location.href = data.product_url; return null; }
  applyFragments(data.fragments);
  jq()?.(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, null]);
  document.dispatchEvent(new CustomEvent('mandala:cart-add', { detail: { items: [[Number(id), Number(qty)]], source: 'product' } }));
  return data;
}
export { addToCart, applyFragments };

// A kártyák AJAX kosárba tétele a WooCommerce add-to-cart.js-én fut; mi az értesítést adjuk.
jq()?.(document.body).on('added_to_cart', (_e, _f, _h, $button) => {
  const btn = $button?.[0];
  const card = btn?.closest('li.product');
  const name = card?.querySelector('.loop-product-title')?.textContent.trim();
  const counter = $('.cart-toggle');
  counter?.classList.remove('bump'); void counter?.offsetWidth; counter?.classList.add('bump');
  if (btn) {
    btn.classList.remove('added');
    btn.parentElement.querySelectorAll('.added_to_cart').forEach((a) => a.remove());
    toast(`<strong>Kosárba tettük:</strong> ${esc(name || 'termék')}`, { action: '<button type="button" class="iu-button" data-open-cart>Kosár</button>' });
  }
});
jq()?.(document.body).on('removed_from_cart', () => toast('Törölted a terméket a kosárból.'));

// ---------- Kedvencek ----------
function updateWishUI() {
  const ids = wishlist.ids();
  $$('[data-wish-count]').forEach((el) => { el.textContent = ids.length; el.hidden = !ids.length; });
  $$('[data-wish]').forEach((b) => b.setAttribute('aria-pressed', String(ids.includes(Number(b.dataset.wish)))));
}
document.addEventListener('wish:change', updateWishUI);

// ---------- Cookie sáv ----------
const COOKIE_KEY = 'mandala.cookie.v1';
function cookieBar(force = false) {
  if (!force && storage.get(COOKIE_KEY, null)) return;
  $('#cookie-bar')?.remove();
  document.body.insertAdjacentHTML('beforeend', `<section class="cookie-bar" id="cookie-bar" role="dialog" aria-labelledby="cookie-title" hidden>
    <h2 id="cookie-title">Sütiket használunk</h2>
    <p style="margin:0">A működéshez szükséges sütik mindig aktívak. Statisztikai és marketing sütiket csak a hozzájárulásoddal használunk.${M.privacy ? ` <a href="${esc(M.privacy)}">Részletek</a>` : ''}</p>
    <div class="cookie-prefs" hidden>
      <label class="check"><input type="checkbox" checked disabled> <span>Szükséges<small>Kosár, bejelentkezés, biztonság</small></span></label>
      <label class="check"><input type="checkbox" name="stats"> <span>Statisztika<small>Anonim látogatottsági adatok</small></span></label>
      <label class="check"><input type="checkbox" name="marketing"> <span>Marketing<small>Hirdetések mérése</small></span></label>
    </div>
    <div class="iu-button-group">
      <button type="button" class="iu-button" data-cookie="all">Mindet elfogadom</button>
      <button type="button" class="iu-button iu-button-outline" data-cookie="necessary">Csak a szükségesek</button>
      <button type="button" class="iu-button iu-button-link" style="--btn-fg:#fff" data-cookie="prefs" aria-expanded="false">Beállítások</button>
    </div>
  </section>`);
  const bar = $('#cookie-bar');
  bar.hidden = false;
  requestAnimationFrame(() => bar.classList.add('is-open'));
}
function saveCookie(choice) {
  const bar = $('#cookie-bar');
  const prefs = choice === 'all' ? { stats: true, marketing: true } : choice === 'custom'
    ? { stats: $('[name="stats"]', bar).checked, marketing: $('[name="marketing"]', bar).checked } : { stats: false, marketing: false };
  storage.set(COOKIE_KEY, { ...prefs, date: new Date().toISOString() });
  // Google Consent Mode v2 (ha van mérőkód), és esemény a saját integrációknak.
  window.gtag?.('consent', 'update', { analytics_storage: prefs.stats ? 'granted' : 'denied', ad_storage: prefs.marketing ? 'granted' : 'denied', ad_user_data: prefs.marketing ? 'granted' : 'denied', ad_personalization: prefs.marketing ? 'granted' : 'denied' });
  document.dispatchEvent(new CustomEvent('mandala:consent', { detail: prefs }));
  bar.classList.remove('is-open');
  setTimeout(() => bar.remove(), 350);
  toast('Sütibeállítás elmentve.');
}

// ---------- Karusszel ----------
function bindCarousel(root) {
  const track = $('.carousel-track', root);
  const prev = $('[data-dir="-1"]', root);
  const next = $('[data-dir="1"]', root);
  const bar = $('.carousel-progress span', root);
  if (!track || !prev || !next) return;
  const update = () => {
    const max = track.scrollWidth - track.clientWidth;
    prev.disabled = track.scrollLeft < 4;
    next.disabled = track.scrollLeft > max - 4;
    const vis = track.clientWidth / track.scrollWidth;
    if (bar) { bar.style.width = `${vis * 100}%`; bar.style.transform = `translateX(${max ? (track.scrollLeft / max) * ((1 - vis) / vis) * 100 : 0}%)`; }
  };
  [prev, next].forEach((b) => b.addEventListener('click', () => track.scrollBy({ left: Number(b.dataset.dir) * track.clientWidth * 0.8, behavior: reducedMotion() ? 'auto' : 'smooth' })));
  track.addEventListener('scroll', update, { passive: true });
  addEventListener('resize', update);
  update();
}
$$('[data-carousel]').forEach(bindCarousel);

// ---------- Fülek (mandala/product-tabs) ----------
$$('.mandala-tabs').forEach((tabs) => {
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

// ---------- Tartalomjegyzék (jogi oldalak, információk) ----------
const tocLinks = $$('.toc a[href^="#"]');
if (tocLinks.length && 'IntersectionObserver' in window) {
  const io = new IntersectionObserver((en) => en.forEach((x) => { if (x.isIntersecting) tocLinks.forEach((l) => l.classList.toggle('is-active', l.hash === `#${x.target.id}`)); }), { rootMargin: '-20% 0px -70% 0px' });
  tocLinks.forEach((l) => { const t = document.getElementById(decodeURIComponent(l.hash.slice(1))); if (t) io.observe(t); });
}

// ---------- Készletértesítő ----------
$$('form[data-mandala-form="stock-notify"]').forEach((form) => {
  bindValidation(form);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = $('.form-message', form);
    const errors = validateForm(form);
    if (errors.length) { msg.className = 'form-message is-error'; msg.innerHTML = `${icon('alert')}<span>${esc(errors[0].msg)}</span>`; errors[0].el.focus(); return; }
    const btn = $('button[type="submit"]', form);
    btn.classList.add('is-loading'); btn.disabled = true;
    const data = Object.fromEntries(new FormData(form));
    const res = await fetch(`${M.rest}stock-notify`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': M.nonce }, body: JSON.stringify({ ...data, accept: !!data.accept }) }).catch(() => null);
    const json = await res?.json().catch(() => null);
    btn.classList.remove('is-loading'); btn.disabled = false;
    if (res?.ok) { msg.className = 'form-message is-success'; msg.innerHTML = `${icon('check-circle')}<span>${esc(form.dataset.success)}</span>`; form.reset(); }
    else { msg.className = 'form-message is-error'; msg.innerHTML = `${icon('alert')}<span>${esc(json?.error || 'Most nem sikerült, próbáld újra.')}</span>`; }
  });
});

// ---------- Hangminta (egy közös lejátszó: egyszerre csak egy szól) ----------
const audio = new Audio();
audio.preload = 'none';
let soundUrl = '';
const fmtTime = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
function syncSound() {
  const playing = !audio.paused && !audio.ended;
  $$('[data-sound]').forEach((b) => b.setAttribute('aria-pressed', String(playing && b.dataset.sound === soundUrl)));
  $$('[data-sound-player]').forEach((pl) => {
    const mine = $('[data-sound]', pl)?.dataset.sound === soundUrl;
    const seek = $('[data-sound-seek]', pl);
    pl.classList.toggle('is-playing', playing && mine);
    if (!mine) return;
    seek.disabled = !audio.duration;
    if (audio.duration) { seek.value = (audio.currentTime / audio.duration) * 100; seek.style.setProperty('--p', `${seek.value}%`); }
    $('[data-sound-time]', pl).textContent = `${fmtTime(audio.currentTime)}${audio.duration ? ` / ${fmtTime(audio.duration)}` : ''}`;
  });
}
['play', 'pause', 'ended', 'timeupdate', 'loadedmetadata'].forEach((ev) => audio.addEventListener(ev, syncSound));
function toggleSound(url) {
  if (soundUrl === url && !audio.paused) { audio.pause(); return; }
  if (soundUrl !== url) { soundUrl = url; audio.src = url; }
  audio.play().catch(() => toast('A hangminta most nem játszható le.'));
}
document.addEventListener('input', (e) => {
  const seek = e.target.closest?.('[data-sound-seek]');
  if (seek && audio.duration && $('[data-sound]', seek.closest('[data-sound-player]'))?.dataset.sound === soundUrl) audio.currentTime = (seek.value / 100) * audio.duration;
});

// ---------- Általános űrlap-ellenőrzés (data-validate-form, pl. értékelés) ----------
$$('form[data-validate-form]').forEach((form) => {
  bindValidation(form);
  form.addEventListener('submit', (e) => {
    const errors = validateForm(form);
    const star = form.querySelector('.star-input');
    if (star && !form.querySelector('.star-input input:checked')) { errors.unshift({ el: star.querySelector('input'), msg: 'Válassz csillagot.' }); star.classList.add('is-invalid'); }
    if (errors.length) { e.preventDefault(); errors[0].el.focus(); toast(esc(errors[0].msg)); }
  });
});

// ---------- Globális kattintáskezelés ----------
document.addEventListener('click', async (e) => {
  const snd = e.target.closest('[data-sound]');
  if (snd) { e.preventDefault(); toggleSound(snd.dataset.sound); return; }
  const t = e.target.closest('[data-open-cart],[data-open-search],[data-close],[data-wish],.quantity [data-step],[data-term],[data-cookie],[data-cookie-settings],[data-lang],[data-copy],[data-map-load],.mobile-toggle,.mega-trigger');
  if (!t) {
    const layer = openLayers.at(-1);
    if (layer && e.target === layer) closeLayer(layer);
    if (panel && !panel.hidden && !panel.contains(e.target)) toggleMega(false);
    return;
  }
  if (t.matches('[data-open-cart]')) { if (minicart) { e.preventDefault(); openCart(); } }
  else if (t.matches('[data-open-search]')) { openLayer($('#search'), '#search-input'); loadProducts(); runSearch($('#search-input').value); }
  else if (t.matches('[data-close]')) closeLayer(t.closest('.drawer, .search-layer, .iu-modal') || undefined);
  else if (t.dataset.wish) {
    const on = wishlist.toggle(t.dataset.wish);
    const name = t.closest('li.product')?.querySelector('.loop-product-title')?.textContent.trim() || $('.product_title')?.textContent.trim() || '';
    toast(on ? `<strong>Kedvencekhez adva:</strong> ${esc(name)}` : `Eltávolítva a kedvencek közül: ${esc(name)}`, { action: on && M.wishlistPage ? `<a class="iu-button" href="${esc(M.wishlistPage)}">Kedvencek</a>` : '' });
    const list = t.closest('[data-wishlist]');
    if (list && !on) { t.closest('li.product')?.remove(); if (!$('li.product', list)) location.reload(); }
  } else if (t.matches('.quantity [data-step]')) {
    const box = t.closest('.quantity');
    const input = $('input', box);
    if (!input) return;
    const v = Math.max(Number(input.min) || 0, Math.min(Number(input.max) || 99, (Number(input.value) || 0) + Number(t.dataset.step)));
    input.value = v;
    if (box.dataset.cartKey) { box.classList.add('is-loading'); await setQty(box.dataset.cartKey, v); }
    else input.dispatchEvent(new Event('change', { bubbles: true }));
  } else if (t.dataset.term) { const q = $('#search-input'); q.value = t.dataset.term; runSearch(q.value); q.focus(); }
  else if (t.dataset.cookie) {
    if (t.dataset.cookie === 'prefs') {
      const prefs = $('.cookie-prefs');
      if (prefs.hidden) { prefs.hidden = false; t.textContent = 'Kiválasztottak mentése'; t.setAttribute('aria-expanded', 'true'); } else saveCookie('custom');
    } else saveCookie(t.dataset.cookie);
  } else if (t.matches('[data-cookie-settings]')) { e.preventDefault(); cookieBar(true); }
  else if (t.dataset.lang) {
    e.preventDefault();
    modal({ id: 'lang-modal', title: 'English version', body: '<p lang="en">The English version of the shop is being translated. Until then, feel free to write to us in English – we are happy to help.</p><div class="iu-button-group"><button class="iu-button" data-close>OK</button></div>' });
  } else if (t.dataset.copy) {
    try { await navigator.clipboard.writeText(t.dataset.copy); toast('Vágólapra másolva.'); } catch { toast(`Másold ki kézzel: ${esc(t.dataset.copy)}`); }
  } else if (t.dataset.mapLoad) {
    t.closest('.map-placeholder').innerHTML = `<iframe src="${esc(t.dataset.mapLoad)}" title="Térkép" loading="lazy" referrerpolicy="no-referrer-when-downgrade" style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>`;
  } else if (t.matches('.mobile-toggle')) {
    const menu = $('#main-menu');
    const opener = $('.mobile-toggle[aria-controls]');
    if (t.classList.contains('iu-menu-close')) { menu.classList.remove('is-open'); document.body.classList.remove('is-locked'); opener?.setAttribute('aria-expanded', 'false'); opener?.focus(); }
    else { menu.classList.add('is-open'); document.body.classList.add('is-locked'); t.setAttribute('aria-expanded', 'true'); setTimeout(() => $('.iu-menu-close')?.focus(), 50); }
  } else if (t.matches('.mega-trigger')) {
    if (matchMedia('(max-width: 991.8px)').matches) {
      const sub = $('#mobile-sub');
      sub.hidden = !sub.hidden;
      t.setAttribute('aria-expanded', String(!sub.hidden));
    } else toggleMega(t.getAttribute('aria-expanded') !== 'true' || Date.now() - megaOpenedAt < 400);
  }
});

// Minikosár mennyiség kézi átírása; kosár oldalon a „Kosár frissítése” automatikusan fut.
let cartTimer;
document.addEventListener('change', (e) => {
  const input = e.target;
  const box = input.closest?.('.quantity');
  if (!box || !input.matches('input')) return;
  if (box.dataset.cartKey) { setQty(box.dataset.cartKey, Math.max(0, Math.round(Number(input.value)) || 1)); return; }
  const cartForm = input.closest('form.woocommerce-cart-form');
  if (cartForm) {
    clearTimeout(cartTimer);
    cartTimer = setTimeout(() => { const btn = $('[name="update_cart"]', cartForm); if (btn) { btn.disabled = false; btn.click(); } }, 600);
  }
});

document.addEventListener('keydown', (e) => {
  trapFocus(e);
  if (e.key === 'Escape') {
    if (panel && !panel.hidden) { toggleMega(false); trigger?.focus(); return; }
    if ($('#main-menu')?.classList.contains('is-open')) { $('.iu-menu-close')?.click(); return; }
    closeLayer();
  }
  if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !openLayers.length && $('[data-open-search]')) { e.preventDefault(); $('[data-open-search]').click(); }
});

updateWishUI();
cookieBar();
refreshReveal();
