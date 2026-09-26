// Közös felület: global_header, global_footer, minikosár, kereső, cookie sáv,
// termékkártya (Loop Product minta), űrlap-validáció (iu/form: „szabály|hibaüzenet”).
import { CONFIG, CATEGORIES, INTENTS, ORIGINS, ARTICLES } from './data.js';
import { art, logoMark } from './art.js';
import { icon } from './icons.js';
import { cart, wishlist, loadProducts, totals, fmt, priceHtml, subLabel, maxQty, norm, storage, KEYS } from './store.js';
import { getCorpus, setCorpus } from './search-engine.js';
import { suggestHtml } from './search-ui.js';

export { icon };
export const esc = (s = '') => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const params = () => new URLSearchParams(location.search);
const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

// ---------- Képek ----------
const IMG_BIG = { 'rez-sarkanyok': 976, 'ruhazat-to': 885 };
/** Kép URL; az egyfájlos előnézetben a window.__IMG beágyazott változata. */
export const imgSrc = (name, size = 'big') => window.__IMG?.[name] || `assets/img/${name}-${size === 'small' ? 800 : IMG_BIG[name] || 1600}.webp`;
export function img(name, alt = '', { eager = false, sizes = '100vw', cls = '' } = {}) {
  const set = window.__IMG ? '' : ` srcset="${imgSrc(name, 'small')} 800w, ${imgSrc(name)} ${IMG_BIG[name] || 1600}w" sizes="${sizes}"`;
  return `<img src="${imgSrc(name, 'small')}"${set} alt="${esc(alt)}" ${cls ? `class="${cls}"` : ''} ${eager ? 'fetchpriority="high"' : 'loading="lazy"'} decoding="async">`;
}
export const productMedia = (p, i = 0) => {
  if (p.image) return `<img src="${esc(p.image)}" alt="${esc(p.name)}" loading="lazy" decoding="async">`;
  if (i > 0 && p.images?.[i - 1]) return img(p.images[i - 1], p.name, { sizes: '(max-width: 991px) 100vw, 50vw' });
  return art(p.art, p.tone, { label: p.name });
};
export const productUrl = (p) => `termek.html?p=${encodeURIComponent(p.slug)}`;

// ---------- Termék részletek ----------
export const originHtml = (p) => `<span class="origin origin-${p.origin}">${esc(ORIGINS[p.origin]?.label || '')}</span>`;
export function priceBlock(p) {
  return p.compare > p.price
    ? `<span class="price"><del aria-label="Eredeti ár">${priceHtml(p.compare)}</del> <ins aria-label="Akciós ár">${priceHtml(p.price)}</ins></span>`
    : `<span class="price">${priceHtml(p.price)}</span>`;
}
export function stockHtml(p) {
  if (p.stock === 'out') return '<p class="stock out-of-stock">Elfogyott – értesítést kérhetsz</p>';
  if (p.stock === 'low') return `<p class="stock low-stock">Utolsó ${p.stockQty} db raktáron</p>`;
  return '<p class="stock in-stock">Raktáron, 1–2 munkanapon belül feladjuk</p>';
}
const saleOf = (p) => (p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0);
export function badgesHtml(p) {
  const b = [];
  if (p.stock === 'out') b.push('<span class="badge badge-dark">Elfogyott</span>');
  else if (saleOf(p)) b.push(`<span class="badge badge-sale">−${saleOf(p)}%</span>`);
  if (p.isNew && p.stock !== 'out') b.push('<span class="badge">Új</span>');
  return `<div class="product-badges">${b.join('')}</div>`;
}
export const wishButton = (p, cls = 'wishlist-toggle') => `<button type="button" class="${cls}" data-wish="${p.id}" aria-pressed="${wishlist.has(p.id)}" aria-label="Kedvencekhez: ${esc(p.name)}">${icon('heart')}</button>`;

/** Termékkártya – a „Loop Product” iu_pattern szerkezete (li.product a [products] listában). */
export function productCard(p) {
  const out = p.stock === 'out';
  return `<li class="product type-product ${out ? 'outofstock' : 'instock'}">
    <div class="product-card">
      <div class="loop-product-image">
        <a href="${productUrl(p)}" tabindex="-1" aria-hidden="true">${productMedia(p)}</a>
        ${badgesHtml(p)}
        ${wishButton(p)}
        ${out ? '' : `<div class="loop-quick"><button type="button" class="iu-button" data-add="${p.id}">${icon('plus', 'ico ico-s')} Kosárba</button></div>`}
      </div>
      <div class="loop-product-meta">${originHtml(p)}<span class="text-muted" style="font-size:var(--fs-xs)">${esc(subLabel(p.cat, p.sub))}</span></div>
      <h3 class="loop-product-title"><a href="${productUrl(p)}">${esc(p.name)}</a></h3>
      <div class="loop-product-foot">
        <div class="loop-product-pirce">${priceBlock(p)}</div>
        <div class="loop-product-button"><button type="button" class="icon-button" data-add="${p.id}" ${out ? 'disabled' : ''} aria-label="${out ? 'Elfogyott' : `Kosárba: ${esc(p.name)}`}">${icon(out ? 'close' : 'bag')}</button></div>
      </div>
    </div>
  </li>`;
}

/** iu/breadcrumbs (Schema.org BreadcrumbList). */
export function breadcrumbs(items) {
  return `<nav class="iu-breadcrumbs" aria-label="Morzsamenü"><ol itemscope itemtype="https://schema.org/BreadcrumbList">
    ${items.map(([label, href], i) => `<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">${href && i < items.length - 1
      ? `<a itemprop="item" href="${href}"><span itemprop="name">${esc(label)}</span></a>`
      : `<span itemprop="name" aria-current="page">${esc(label)}</span>`}<meta itemprop="position" content="${i + 1}"></li>`).join('')}
  </ol></nav>`;
}

// ---------- Űrlap-validáció (iu/form validate attribútum formátuma) ----------
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
/** Magyar adószám: 8-1-2 számjegy, a törzsszám 8. jegye ellenőrző (9,7,3,1,9,7,3 súlyok). */
export function validTaxNumber(v) {
  const m = String(v).replace(/\s/g, '').match(/^(\d{8})-?([1-5])-?(\d{2})$/);
  if (!m) return false;
  const d = m[1].split('').map(Number);
  const sum = [9, 7, 3, 1, 9, 7, 3].reduce((s, w, i) => s + w * d[i], 0);
  return (10 - (sum % 10)) % 10 === d[7];
}
export const normPhone = (v) => {
  const digits = String(v).replace(/[^\d+]/g, '');
  if (/^\+36\d{8,9}$/.test(digits)) return digits;
  if (/^06\d{8,9}$/.test(digits)) return `+36${digits.slice(2)}`;
  return null;
};
const RULES = {
  required: (v, el) => (el.type === 'checkbox' ? el.checked : v.trim() !== ''),
  email: (v) => !v || EMAIL_RE.test(v.trim()),
  phone: (v) => !v || normPhone(v) !== null,
  zip: (v) => !v || /^[1-9]\d{3}$/.test(v.trim()),
  taxno: (v) => !v || validTaxNumber(v),
  min8: (v) => !v || v.length >= 8,
};
const TYPO_DOMAINS = { 'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gmail.hu': 'gmail.com', 'gamil.com': 'gmail.com', 'gmail.co': 'gmail.com', 'freemal.hu': 'freemail.hu', 'fremail.hu': 'freemail.hu', 'citromal.hu': 'citromail.hu', 'hotmial.com': 'hotmail.com', 'yahho.com': 'yahoo.com', 'outlok.com': 'outlook.com' };

export function fieldError(el) {
  const spec = el.dataset.validate || '';
  for (const line of spec.split('\n').map((l) => l.trim()).filter(Boolean)) {
    const [rule, msg] = line.split('|');
    const fn = RULES[rule];
    if (fn && !fn(el.value || '', el)) return msg || 'Hibás érték.';
  }
  return '';
}
export function showFieldState(el, msg) {
  const wrap = el.closest('.form-row, .iu-form-field, .iu-form-accept, .check-row') || el.parentElement;
  const errId = `${el.id}-error`;
  let err = document.getElementById(errId);
  if (!err) {
    err = document.createElement('p');
    err.className = 'field-error';
    err.id = errId;
    err.setAttribute('aria-live', 'polite');
    wrap.append(err);
  }
  err.innerHTML = msg ? `${icon('alert', 'ico ico-s')}<span>${esc(msg)}</span>` : '';
  wrap.classList.toggle('is-invalid', !!msg);
  wrap.classList.toggle('woocommerce-invalid', !!msg);
  wrap.classList.toggle('woocommerce-validated', !msg && !!el.value && el.type !== 'checkbox');
  if (msg) { el.setAttribute('aria-invalid', 'true'); el.setAttribute('aria-describedby', [errId, el.dataset.hint].filter(Boolean).join(' ')); }
  else { el.removeAttribute('aria-invalid'); if (el.dataset.hint) el.setAttribute('aria-describedby', el.dataset.hint); else el.removeAttribute('aria-describedby'); }
}
function emailSuggestion(el) {
  const at = el.value.trim().split('@');
  const fix = at.length === 2 && TYPO_DOMAINS[at[1].toLowerCase()];
  let box = document.getElementById(`${el.id}-suggest`);
  if (!fix) { box?.remove(); return; }
  if (!box) { box = document.createElement('p'); box.id = `${el.id}-suggest`; box.className = 'field-suggest'; el.closest('.form-row, .iu-form-field').append(box); }
  const next = `${at[0]}@${fix}`;
  box.innerHTML = `Erre gondoltál: <button type="button">${esc(next)}</button>?`;
  box.querySelector('button').onclick = () => { el.value = next; box.remove(); showFieldState(el, fieldError(el)); el.dispatchEvent(new Event('input', { bubbles: true })); };
}
/** Mezőnkénti validáció: elhagyáskor, hibás mezőnél gépelés közben is. */
export function bindValidation(form) {
  form.addEventListener('focusout', (e) => {
    const el = e.target;
    if (!el.matches?.('[data-validate]')) return;
    if (el.type === 'checkbox') return;
    const before = el.value;
    if (el.dataset.format === 'phone') { const n = normPhone(el.value); if (n) el.value = n.replace(/^(\+36)(\d{2})(\d{3})(\d{3,4})$/, '$1 $2 $3 $4'); }
    if (el.dataset.format === 'taxno') { const d = el.value.replace(/\D/g, ''); if (d.length === 11) el.value = `${d.slice(0, 8)}-${d[8]}-${d.slice(9)}`; }
    if (el.value !== before) el.dispatchEvent(new Event('input', { bubbles: true })); // a formázott érték a piszkozatba is bekerül
    if (el.value || el.closest('.is-invalid')) showFieldState(el, fieldError(el));
    if (el.type === 'email') emailSuggestion(el);
  });
  form.addEventListener('input', (e) => { const el = e.target; if (el.matches?.('[data-validate]') && el.closest('.is-invalid')) showFieldState(el, fieldError(el)); });
  form.addEventListener('change', (e) => { const el = e.target; if (el.type === 'checkbox' && el.dataset.validate) showFieldState(el, fieldError(el)); });
}
/** A teljes űrlap ellenőrzése. Csak a látható mezőket nézi. */
export function validateForm(form) {
  const errors = [];
  $$('[data-validate]', form).forEach((el) => {
    if (el.closest('[hidden]') || el.disabled) return;
    const msg = fieldError(el);
    showFieldState(el, msg);
    if (msg) errors.push({ el, msg, label: el.dataset.label || el.closest('.form-row, .iu-form-field')?.querySelector('label')?.textContent.replace('*', '').trim() || '' });
  });
  return errors;
}

/** iu/form mező. */
export function formField({ id, name = id, label, type = 'text', validate = '', auto = '', placeholder = '', hint = '', span = '', value = '', options = null, textarea = false, required = /required/.test(validate) }) {
  const hintId = hint ? `${id}-hint` : '';
  const attrs = `id="${id}" name="${name}" ${validate ? `data-validate="${esc(validate)}"` : ''} ${auto ? `autocomplete="${auto}"` : ''} ${placeholder ? `placeholder="${esc(placeholder)}"` : ''} ${required ? 'required aria-required="true"' : ''} ${hintId ? `data-hint="${hintId}" aria-describedby="${hintId}"` : ''}`;
  let control;
  if (options) control = `<select ${attrs}>${options.map((o) => `<option ${o === value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
  else if (textarea) control = `<textarea ${attrs}>${esc(value)}</textarea>`;
  else control = `<input type="${type}" ${attrs} value="${esc(value)}">`;
  return `<div class="iu-form-field ${span}"><label for="${id}">${esc(label)}${required ? '<abbr class="required" title="kötelező">*</abbr>' : ' <span class="optional">(nem kötelező)</span>'}</label>${control}${hint ? `<p class="field-hint" id="${hintId}">${esc(hint)}</p>` : ''}</div>`;
}
export const acceptField = (id, html, msg = 'Az elküldéshez fogadd el az adatkezelési tájékoztatót.') =>
  `<div class="check-row"><label class="iu-form-accept" for="${id}"><input type="checkbox" id="${id}" name="${id}" data-validate="required|${esc(msg)}"> <span>${html}</span></label></div>`;

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

/** iu/modal */
export function modal({ id, title, body, size = '' }) {
  $(`#${id}`)?.remove();
  document.body.insertAdjacentHTML('beforeend', `<div class="iu-modal" id="${id}" hidden><div class="iu-modal-dialog ${size}" role="dialog" aria-modal="true" aria-labelledby="${id}-title">
    <div class="iu-modal-head"><h2 id="${id}-title">${esc(title)}</h2><button type="button" class="icon-button" data-close aria-label="Bezárás">${icon('close')}</button></div>
    <div class="iu-modal-body">${body}</div></div></div>`);
  const el = $(`#${id}`);
  openLayer(el);
  return el;
}

// ---------- Toast ----------
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
  t.addEventListener('click', (e) => { if (e.target.closest('button')) { clearTimeout(timer); kill(); } });
  return t;
}

// ---------- Kosár műveletek ----------
export async function addToCart(id, qty = 1, { openDrawer = false } = {}) {
  const p = (await loadProducts()).find((x) => x.id === id);
  if (!p || p.stock === 'out') return;
  const limit = maxQty(p);
  const before = cart.qtyOf(id);
  if (before >= limit) { toast(`Ebből a termékből legfeljebb <strong>${limit} db</strong> rendelhető – mind a kosaradban van.`); return; }
  const now = cart.add(id, qty, limit);
  const btn = $('.cart-toggle');
  btn?.classList.remove('bump'); void btn?.offsetWidth; btn?.classList.add('bump');
  if (openDrawer) { openCart(); return; }
  const capped = now - before < qty ? ` (készlet miatt ${now - before} db)` : '';
  toast(`<strong>Kosárba tettük:</strong> ${esc(p.name)}${capped}`, { action: '<button type="button" class="iu-button" data-open-cart>Kosár</button>' });
}

async function renderMiniCart() {
  const body = $('#minicart .drawer-body');
  const foot = $('#minicart .drawer-foot');
  if (!body) return;
  const t = await totals();
  $('#minicart-count').textContent = t.count ? `(${t.count})` : '';
  if (!t.items.length) {
    const products = await loadProducts();
    body.innerHTML = `<div class="empty-state" style="margin-top:var(--space-5)">${icon('bag', 'ico ico-xl')}<h3>A kosarad üres</h3><p>Kezdd egy népszerű darabbal:</p>
      <ul class="search-hits" style="width:100%;text-align:left">${products.filter((p) => p.featured && p.stock !== 'out').slice(0, 3).map((p) => `<li><a href="${productUrl(p)}"><span class="thumb">${productMedia(p)}</span><span>${esc(p.name)}<small>${fmt(p.price)}</small></span>${icon('chevron-right')}</a></li>`).join('')}</ul></div>`;
    foot.hidden = true;
    return;
  }
  foot.hidden = false;
  const pct = Math.min(100, ((t.subtotal - t.discount) / CONFIG.freeShippingFrom) * 100);
  body.innerHTML = `<div class="ship-meter ${t.freeShipping ? 'is-done' : ''}"><p>${t.freeShipping ? `${icon('check', 'ico ico-s')} A szállítás ingyenes.` : `Még <strong>${fmt(t.remaining)}</strong>, és ingyen szállítunk.`}</p><div class="meter" role="progressbar" aria-label="Ingyenes szállításig" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(pct)}"><span style="width:${pct}%"></span></div></div>
    <ul class="review-items" style="border:0">${t.items.map(({ product: p, qty }) => `<li>
      <a class="thumb" href="${productUrl(p)}">${productMedia(p)}</a>
      <div><a class="name" href="${productUrl(p)}" style="color:inherit;text-decoration:none">${esc(p.name)}</a><small>${fmt(p.price)} / db</small>
        <div class="quantity quantity-s" data-qty="${p.id}" style="margin-top:var(--space-2)">
          <button type="button" data-step="-1" aria-label="Eggyel kevesebb: ${esc(p.name)}">${icon('minus', 'ico ico-s')}</button>
          <input type="number" inputmode="numeric" min="1" max="${maxQty(p)}" value="${qty}" aria-label="Mennyiség: ${esc(p.name)}">
          <button type="button" data-step="1" ${qty >= maxQty(p) ? 'disabled' : ''} aria-label="Eggyel több: ${esc(p.name)}">${icon('plus', 'ico ico-s')}</button>
        </div></div>
      <div style="display:grid;justify-items:end;gap:var(--space-2)"><strong class="num">${fmt(p.price * qty)}</strong><button type="button" class="remove" data-remove="${p.id}" aria-label="Törlés: ${esc(p.name)}">${icon('trash', 'ico ico-s')}</button></div>
    </li>`).join('')}</ul>`;
  foot.innerHTML = `<table class="totals-table"><tbody>
      <tr><th>Részösszeg</th><td>${fmt(t.subtotal)}</td></tr>
      ${t.discount ? `<tr class="discount"><th>Kupon (${esc(t.code)})</th><td>−${fmt(t.discount)}</td></tr>` : ''}
    </tbody></table>
    <p class="text-muted text-small" style="margin:0">A szállítási díjat a pénztárban választod ki. Az árak az ÁFÁ-t tartalmazzák.</p>
    <a class="iu-button iu-button-large iu-button-block" href="penztar.html">${icon('lock', 'ico ico-s')} Tovább a pénztárhoz</a>
    <a class="iu-button iu-button-outline iu-button-block" href="kosar.html">Kosár megtekintése</a>`;
}
export const openCart = () => { openLayer($('#minicart')); renderMiniCart(); };

function updateCounts() {
  const n = cart.count();
  $$('[data-cart-count]').forEach((el) => { el.textContent = n; el.hidden = !n; });
  const w = wishlist.ids().length;
  $$('[data-wish-count]').forEach((el) => { el.textContent = w; el.hidden = !w; });
  $$('[data-wish]').forEach((b) => b.setAttribute('aria-pressed', wishlist.has(Number(b.dataset.wish))));
}

// ---------- Fejléc ----------
const NAV = [
  ['shop', 'Kínálat'],
  ['new', 'Újdonságok', 'termekek.html?orderby=date'],
  ['about', 'Eredetünk', 'rolunk.html'],
  ['mag', 'Magazin', 'magazin.html'],
  ['b2b', 'Viszonteladóknak', 'viszonteladoknak.html'],
];
function megaPanel() {
  return `<div class="mega-panel" id="mega-panel" hidden><div class="iu-row">
    ${CATEGORIES.map((c) => `<div class="iu-column iu-column-1-4" style="width:calc((100% - 4 * var(--gutter)) / 5)">
      <a class="mega-title" href="termekek.html?cat=${c.slug}">${esc(c.label)}</a>
      <ul class="mega-list">${c.subs.map(([s, l]) => `<li><a href="termekek.html?cat=${c.slug}&amp;sub=${s}">${esc(l)}</a></li>`).join('')}</ul></div>`).join('')}
    <div class="iu-column iu-column-1-4" style="width:calc((100% - 4 * var(--gutter)) / 5)">
      <span class="mega-title">Szándék szerint</span>
      <ul class="mega-list">${INTENTS.map((i) => `<li><a href="termekek.html?intent=${i.id}">${esc(i.label)}</a></li>`).join('')}</ul>
      <a class="mega-feature" href="termekek.html?cat=szakralis-targyak&amp;sub=hangtalak" style="margin-top:var(--space-5);height:auto">${img('hangtalak-studio', '', { sizes: '260px' })}<span><strong>Hangtál-kalauz</strong>Hz, hang és csakra szerint</span></a>
    </div>
  </div></div>`;
}
function mobileSub() {
  return `<ul class="mobile-sub" id="mobile-sub" hidden>
    ${CATEGORIES.map((c) => `<li><a href="termekek.html?cat=${c.slug}">${esc(c.label)}</a></li>`).join('')}
    <li><a href="termekek.html">Teljes kínálat</a></li></ul>`;
}
function header(active, variant) {
  if (variant === 'checkout') {
    return `<a class="skip-link" href="#main">Ugrás a tartalomra</a>
    <section class="iu-section site-masthead"><div class="iu-row"><div class="iu-column iu-column-1-1">
      <div class="iu-group iu-group-horizontal-space-between iu-group-vertical-center">
        <a class="site-logo" href="index.html" aria-label="Mandala – kezdőlap">${logoMark}<span>Mandala</span></a>
        <span class="checkout-header-note">${icon('lock', 'ico ico-s')} Biztonságos pénztár</span>
        <a class="iu-button iu-button-link" href="kosar.html">${icon('arrow-left', 'ico ico-s')} <span class="hide-mobile">Vissza a kosárhoz</span><span class="d-none m-inline-flex">Kosár</span></a>
      </div></div></div></section>`;
  }
  const link = ([key, label, href]) => key === 'shop'
    ? `<li class="menu-item menu-item-has-children mega ${active === key ? 'current-menu-item' : ''}"><button type="button" class="mega-trigger" aria-expanded="false" aria-controls="mega-panel">${label} ${icon('chevron', 'ico ico-s')}</button>${mobileSub()}</li>`
    : `<li class="menu-item ${active === key ? 'current-menu-item' : ''}"><a href="${href}" ${active === key ? 'aria-current="page"' : ''}>${label}</a></li>`;
  return `<a class="skip-link" href="#main">Ugrás a tartalomra</a>
  <section class="iu-section site-notice" aria-label="Közlemény"><div class="iu-row"><div class="iu-column iu-column-1-1">
    <p><span>${icon('truck', 'ico ico-s')}</span><span>Ingyenes szállítás <strong>${fmt(CONFIG.freeShippingFrom)}</strong> felett</span><span class="sep hide-mobile">·</span><span class="hide-mobile">Közvetlen import Nepálból és Indiából</span><span class="sep hide-mobile">·</span><span class="hide-mobile">14 napos visszaküldés</span></p>
  </div></div></section>
  <section class="iu-section site-masthead"><div class="iu-row"><div class="iu-column iu-column-1-1">
    <div class="iu-group iu-group-horizontal-space-between iu-group-vertical-center">
      <div class="iu-menu-block-wrapper">
        <button type="button" class="mobile-toggle icon-button" aria-expanded="false" aria-controls="main-menu" aria-label="Menü megnyitása">${icon('menu')}</button>
        <a class="site-logo" href="index.html" aria-label="Mandala – kezdőlap">${logoMark}<span>Mandala</span></a>
      </div>
      <nav class="iu-menu-container" id="main-menu" aria-label="Fő menü">
        <button type="button" class="mobile-toggle iu-menu-close icon-button" aria-label="Menü bezárása">${icon('close')}</button>
        <ul class="iu-menu-block">${NAV.map(link).join('')}</ul>
        <div class="mobile-menu-foot d-none m-block">
          <a class="iu-button iu-button-outline" href="fiok.html">${icon('user', 'ico ico-s')} Fiókom</a>
          <a class="iu-button iu-button-outline" href="kedvencek.html">${icon('heart', 'ico ico-s')} Kedvencek</a>
          <p>${icon('phone', 'ico ico-s')} ${CONFIG.contact.phone} · ${CONFIG.contact.hours}</p>
        </div>
      </nav>
      <div class="header-actions">
        <button type="button" class="icon-button" data-open-search aria-label="Keresés (/)">${icon('search')}</button>
        <div class="lang-switch hide-mobile" role="group" aria-label="Nyelv"><span aria-current="true">HU</span><a href="#" data-lang="en" lang="en">EN</a></div>
        <a class="icon-button hide-mobile" href="fiok.html" aria-label="Fiókom" ${active === 'account' ? 'aria-current="page"' : ''}>${icon('user')}</a>
        <a class="icon-button hide-mobile" href="kedvencek.html" aria-label="Kedvencek">${icon('heart')}<span class="count" data-wish-count hidden>0</span></a>
        <button type="button" class="icon-button cart-toggle" data-open-cart aria-label="Kosár">${icon('bag')}<span class="count" data-cart-count hidden>0</span></button>
      </div>
    </div>
  </div></div>${megaPanel()}</section>`;
}

// ---------- Lábléc ----------
function newsletterSection() {
  return `<section class="iu-section newsletter" aria-labelledby="nl-title"><div class="iu-row iu-row-vertical-center">
    <div class="iu-column iu-column-1-2">
      <p class="eyebrow">Hírlevél</p>
      <h2 id="nl-title">Csendes levelek, havonta kétszer</h2>
      <p class="lead" style="margin:0">Új érkezések Nepálból és Indiából, magazincikkek és előfizetői kedvezmények. Nincs zaj – csak ami számít.</p>
    </div>
    <div class="iu-column iu-column-1-2">
      <form class="iu-form" data-form-id="hirlevel" novalidate data-success="Köszönjük! Küldtünk egy megerősítő levelet – kattints a benne lévő linkre.">
        <div class="nl-row">${formField({ id: 'nl-email', name: 'email', label: 'E-mail-cím', type: 'email', auto: 'email', placeholder: 'nev@pelda.hu', validate: 'required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek.' }).replace('<label', '<label class="sr-only"')}
          <button class="iu-button iu-button-large" type="submit">Feliratkozom</button></div>
        ${acceptField('nl-accept', 'Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>, és bármikor leiratkozhatok.')}
        <p class="form-message" role="status"></p>
      </form>
    </div>
  </div></section>`;
}
function footer(variant) {
  const c = CONFIG.contact;
  const legal = `<nav aria-label="Jogi információk"><a href="jogi.html?d=aszf">ÁSZF</a><a href="jogi.html?d=adatkezeles">Adatkezelés</a><a href="jogi.html?d=impresszum">Impresszum</a><a href="#" data-cookie-settings>Sütibeállítások</a></nav>`;
  if (variant === 'checkout') {
    return `<div class="iu-section site-footer" style="padding-top:0"><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="footer-bottom" style="margin-top:0">
      <p>© <span data-year></span> Mandala · ${icon('lock', 'ico ico-s')} Titkosított kapcsolat</p>${legal}</div></div></div></div>`;
  }
  return `<section class="iu-section site-footer"><div class="iu-row">
      <div class="iu-column iu-column-1-4">
        <a class="site-logo" href="index.html">${logoMark}<span>Mandala</span></a>
        <p>Hangtálak, füstölők, szobrok, textilek és ajándékok – kézzel válogatva Nepál és India műhelyeiből, hogy a csendnek otthon is helye legyen.</p>
        <div class="iu-icon-group"><a class="iu-icon iu-icon-inverted" href="${c.facebook}" target="_blank" rel="noopener" aria-label="Facebook">${icon('facebook')}</a><a class="iu-icon iu-icon-inverted" href="${c.instagram}" aria-label="Instagram">${icon('instagram')}</a></div>
      </div>
      <div class="iu-column iu-column-1-4"><h2>Kínálat</h2><ul>
        ${CATEGORIES.map((cat) => `<li><a href="termekek.html?cat=${cat.slug}">${esc(cat.label)}</a></li>`).join('')}
        <li><a href="termekek.html?orderby=date">Újdonságok</a></li><li><a href="termekek.html?sale=1">Akciók</a></li></ul></div>
      <div class="iu-column iu-column-1-4"><h2>Vásárlás</h2><ul>
        <li><a href="informaciok.html#szallitas">Szállítás és átvétel</a></li><li><a href="informaciok.html#fizetes">Fizetési módok</a></li>
        <li><a href="informaciok.html#visszakuldes">Visszaküldés, elállás</a></li><li><a href="fiok.html">Fiókom és rendeléseim</a></li>
        <li><a href="viszonteladoknak.html">Viszonteladóknak</a></li><li><a href="kapcsolat.html">Kapcsolat</a></li></ul></div>
      <div class="iu-column iu-column-1-4"><h2>Elérhetőség</h2><ul class="contact-list">
        <li>${icon('mail', 'ico ico-s')}<a href="mailto:${c.email}">${c.email}</a></li>
        <li>${icon('phone', 'ico ico-s')}<a href="tel:${c.phone.replace(/\s/g, '')}">${c.phone}</a></li>
        <li>${icon('pin', 'ico ico-s')}<span>${esc(c.address)}</span></li>
        <li>${icon('clock', 'ico ico-s')}<span>${esc(c.hours)}</span></li></ul></div>
    </div>
    <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="footer-bottom">
      <p>© <span data-year></span> Mandala. Minden jog fenntartva.</p>${legal}
      <div class="payment-marks" aria-label="Elfogadott fizetési módok"><span>Teya</span><span>VISA</span><span>Mastercard</span><span>Apple Pay</span><span>Utalás</span></div>
    </div></div></div>
  </section>`;
}

// ---------- Rétegek (kereső, minikosár, cookie) ----------
function layers() {
  return `<div class="search-layer" id="search" hidden>
    <div class="search-panel" role="dialog" aria-modal="true" aria-label="Keresés">
      <div class="iu-row"><div class="iu-column iu-column-1-1">
        <form class="search-form" action="kereses.html" role="search">
          ${icon('search', 'ico ico-l')}
          <label class="sr-only" for="search-input">Keresés termékek és cikkek között</label>
          <input id="search-input" name="s" type="search" placeholder="Hangtál, füstölő, mala, cikkszám…" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list">
          <button type="button" class="icon-button" data-close aria-label="Keresés bezárása">${icon('close')}</button>
        </form>
        <div id="search-results" class="search-suggest" aria-live="polite"></div>
      </div></div>
    </div>
  </div>
  <div class="drawer" id="minicart" hidden>
    <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="minicart-title">
      <div class="drawer-head"><h2 id="minicart-title">Kosár <span id="minicart-count" class="text-muted" style="font-size:1.25rem"></span></h2>
        <button type="button" class="icon-button" data-close aria-label="Kosár bezárása">${icon('close')}</button></div>
      <div class="drawer-body"></div>
      <div class="drawer-foot" hidden></div>
    </aside>
  </div>`;
}

function cookieBar() {
  if (storage.get(KEYS.cookie, null)) return;
  document.body.insertAdjacentHTML('beforeend', `<section class="cookie-bar" id="cookie-bar" role="dialog" aria-labelledby="cookie-title" hidden>
    <h2 id="cookie-title">Sütiket használunk</h2>
    <p style="margin:0">A működéshez szükséges sütik mindig aktívak. Statisztikai és marketing sütiket csak a hozzájárulásoddal használunk. <a href="jogi.html?d=adatkezeles">Részletek</a></p>
    <div class="cookie-prefs" hidden>
      <label class="check"><input type="checkbox" checked disabled> <span>Szükséges<small>Kosár, bejelentkezés, biztonság</small></span></label>
      <label class="check"><input type="checkbox" name="stats"> <span>Statisztika<small>Anonim látogatottsági adatok (Google Analytics)</small></span></label>
      <label class="check"><input type="checkbox" name="marketing"> <span>Marketing<small>Hirdetések mérése (Meta, Google Ads)</small></span></label>
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
  storage.set(KEYS.cookie, { ...prefs, date: new Date().toISOString() });
  bar.classList.remove('is-open');
  setTimeout(() => bar.remove(), 350);
  toast('Sütibeállítás elmentve.');
}

// ---------- Kereső ----------
// A motor (search-engine.js): ragozás, elírás, szinonimák, kérdés-értelmezés, rangsor.
const engineFor = (products) => getCorpus() || setCorpus(products);
/** Keresés eredménye (a találati oldal is ezt használja). */
export const searchResult = (products, q) => engineFor(products).search(q.trim());
export const searchProducts = (products, q) => (q.trim() ? searchResult(products, q).items : []);
export const searchPosts = (q) => {
  const t = norm(q.trim());
  if (!t) return [];
  const words = t.split(/\s+/).filter((w) => w.length > 1);
  return ARTICLES.filter((a) => { const hay = norm(`${a.title} ${a.excerpt}`); return words.every((w) => hay.includes(w.length > 4 ? w.slice(0, -1) : w)); });
};
/** A megjelenítő segédfüggvényei (search-ui.js). */
export const searchHelpers = (q) => ({
  esc, icon, fmt,
  productUrl,
  thumb: (p) => productMedia(p),
  searchUrl: (query) => `kereses.html?s=${encodeURIComponent(query)}`,
  shopUrl: (query) => (query ? `termekek.html?q=${encodeURIComponent(query)}` : 'termekek.html'),
  highlight: (text) => (getCorpus() ? getCorpus().highlight(text, q) : esc(text)),
});

async function runSearch(q) {
  const box = $('#search-results');
  const input = $('#search-input');
  const term = q.trim();
  if (term.length < 2) {
    input.setAttribute('aria-expanded', 'false');
    box.innerHTML = `<div><h2>Népszerű keresések</h2><div class="chip-row">${['hangtál', 'tibeti füstölő', 'mala', 'Buddha szobor', 'réz kulacs', 'hangtál 500 g alatt'].map((x) => `<button type="button" class="chip" data-term="${x}">${x}</button>`).join('')}</div></div>
      <div><h2>Kategóriák</h2><ul class="mega-list">${CATEGORIES.map((c) => `<li><a href="termekek.html?cat=${c.slug}">${esc(c.label)}</a></li>`).join('')}</ul></div>`;
    return;
  }
  const r = searchResult(await loadProducts(), term);
  input.setAttribute('aria-expanded', String(r.items.length > 0));
  box.innerHTML = suggestHtml(r, term, searchHelpers(term), searchPosts(term).map((a) => ({ title: a.title, url: `cikk.html?a=${a.slug}` })));
}

// ---------- Oldal inicializálás ----------
export function setMeta({ title, description }) {
  if (title) document.title = `${title} – Mandala`;
  if (description) $('meta[name="description"]')?.setAttribute('content', description);
}
export function jsonLd(obj) {
  const s = document.createElement('script');
  s.type = 'application/ld+json';
  s.textContent = JSON.stringify(obj);
  document.head.append(s);
}
export function refreshReveal() {
  const els = $$('.reveal:not(.is-in)');
  if (!('IntersectionObserver' in window) || reducedMotion()) { els.forEach((e) => e.classList.add('is-in')); return; }
  const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -6% 0px' });
  els.forEach((e) => io.observe(e));
}

/** Egyszerű iu/form beküldés (hírlevél, kapcsolat…): validáció, töltés állapot, siker/hiba üzenet. */
export function bindSimpleForm(form, onSuccess) {
  bindValidation(form);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = $('.form-message', form);
    const errors = validateForm(form);
    if (errors.length) {
      msg.className = 'form-message is-error';
      msg.innerHTML = `${icon('alert')}<span>${errors.length === 1 ? 'Egy mezőt javítani kell.' : `${errors.length} mezőt javítani kell.`}</span>`;
      errors[0].el.focus();
      return;
    }
    const btn = $('button[type="submit"]', form);
    btn.classList.add('is-loading'); btn.disabled = true;
    // Élesben: POST az iu_theme űrlapkezelőjének (iu_form_id), válasz: { success: 1 } vagy { errors: {…} }.
    setTimeout(() => {
      btn.classList.remove('is-loading'); btn.disabled = false;
      msg.className = 'form-message is-success';
      msg.innerHTML = `${icon('check-circle')}<span>${esc(form.dataset.success || 'Köszönjük, megkaptuk.')}</span>`;
      form.reset();
      $$('.woocommerce-validated, .is-invalid', form).forEach((x) => x.classList.remove('woocommerce-validated', 'is-invalid'));
      onSuccess?.(form);
    }, 700);
  });
}

export function initPage({ active = '', variant = 'default', newsletter = true } = {}) {
  const head = $('#site-header');
  head.className = 'site-header';
  head.innerHTML = header(active, variant);
  $('#site-footer').outerHTML = `<footer id="site-footer">${variant === 'default' && newsletter ? newsletterSection() : ''}${footer(variant)}</footer>`;
  document.body.insertAdjacentHTML('beforeend', layers());
  $$('[data-year]').forEach((el) => { el.textContent = new Date().getFullYear(); });
  if (variant === 'checkout') document.body.classList.add('woocommerce-checkout');

  updateCounts();
  document.addEventListener('cart:change', () => { updateCounts(); if (!$('#minicart').hidden) renderMiniCart(); });
  document.addEventListener('wish:change', updateCounts);

  // Globális kattintáskezelés (delegálva)
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-open-cart],[data-open-search],[data-close],[data-add],[data-wish],[data-step],[data-remove],[data-term],[data-cookie],[data-cookie-settings],[data-lang],.mobile-toggle,.mega-trigger');
    if (!t) {
      const layer = openLayers.at(-1);
      if (layer && e.target === layer) closeLayer(layer);
      return;
    }
    if (t.matches('[data-open-cart]')) { e.preventDefault(); if (!$('#minicart').classList.contains('is-open')) openCart(); }
    else if (t.matches('[data-open-search]')) { openLayer($('#search'), '#search-input'); runSearch($('#search-input').value); }
    else if (t.matches('[data-close]')) closeLayer(t.closest('.drawer, .search-layer, .iu-modal') || undefined);
    else if (t.dataset.add) addToCart(Number(t.dataset.add), 1);
    else if (t.dataset.wish) {
      const id = Number(t.dataset.wish);
      const on = wishlist.toggle(id);
      loadProducts().then((ps) => { const p = ps.find((x) => x.id === id); toast(on ? `<strong>Kedvencekhez adva:</strong> ${esc(p?.name)}` : `Eltávolítva a kedvencek közül: ${esc(p?.name)}`, { action: on ? '<a class="iu-button" href="kedvencek.html">Kedvencek</a>' : '' }); });
    } else if (t.dataset.step) {
      const box = t.closest('[data-qty]');
      const input = $('input', box);
      const v = Math.max(1, Math.min(Number(input.max) || 99, Number(input.value) + Number(t.dataset.step)));
      input.value = v;
      if (box.dataset.qty) cart.set(Number(box.dataset.qty), v);
      else box.dispatchEvent(new CustomEvent('qty', { detail: v, bubbles: true }));
    } else if (t.dataset.remove) {
      const id = Number(t.dataset.remove);
      const qty = cart.qtyOf(id);
      cart.remove(id);
      loadProducts().then((ps) => {
        const p = ps.find((x) => x.id === id);
        const tt = toast(`Törölted: ${esc(p?.name)}`, { action: '<button type="button" class="iu-button" data-undo>Visszavonás</button>', timeout: 6000 });
        $('[data-undo]', tt).addEventListener('click', () => cart.add(id, qty, maxQty(p)));
      });
    } else if (t.dataset.term) { const q = $('#search-input'); q.value = t.dataset.term; runSearch(q.value); q.focus(); }
    else if (t.dataset.cookie) {
      if (t.dataset.cookie === 'prefs') {
        const prefs = $('.cookie-prefs');
        if (prefs.hidden) { prefs.hidden = false; t.textContent = 'Kiválasztottak mentése'; t.setAttribute('aria-expanded', 'true'); } else saveCookie('custom');
      } else saveCookie(t.dataset.cookie);
    } else if (t.matches('[data-cookie-settings]')) { e.preventDefault(); storage.set(KEYS.cookie, null); cookieBar(); }
    else if (t.dataset.lang) {
      e.preventDefault();
      modal({ id: 'lang-modal', title: 'English version', body: '<p lang="en">The English version of the shop is being translated. Until then, feel free to write to us in English – we are happy to help.</p><p>Az angol nyelvű változat (WPML) a magyar oldal jóváhagyása után készül el.</p><div class="iu-button-group"><button class="iu-button" data-close>Rendben</button></div>' });
    } else if (t.matches('.mobile-toggle')) {
      const menu = $('#main-menu');
      if (t.classList.contains('iu-menu-close')) { menu.classList.remove('is-open'); document.body.classList.remove('is-locked'); $('.mobile-toggle[aria-controls]').setAttribute('aria-expanded', 'false'); $('.mobile-toggle[aria-controls]').focus(); }
      else { menu.classList.add('is-open'); document.body.classList.add('is-locked'); t.setAttribute('aria-expanded', 'true'); setTimeout(() => $('.iu-menu-close').focus(), 50); }
    } else if (t.matches('.mega-trigger')) {
      if (matchMedia('(max-width: 991.8px)').matches) {
        const sub = $('#mobile-sub');
        sub.hidden = !sub.hidden;
        t.setAttribute('aria-expanded', String(!sub.hidden));
      } else toggleMega(t.getAttribute('aria-expanded') !== 'true' || Date.now() - megaOpenedAt < 400);
    }
  });

  // Mennyiség mező kézi átírása
  document.addEventListener('change', (e) => {
    const box = e.target.closest('[data-qty]');
    if (!box || !e.target.matches('input')) return;
    const v = Math.max(1, Math.min(Number(e.target.max) || 99, Math.round(Number(e.target.value)) || 1));
    e.target.value = v;
    if (box.dataset.qty) cart.set(Number(box.dataset.qty), v);
    else box.dispatchEvent(new CustomEvent('qty', { detail: v, bubbles: true }));
  });

  // Megamenü: hover (egér) + kattintás + Escape
  const trigger = $('.mega-trigger');
  const panel = $('#mega-panel');
  let megaTimer; let megaOpenedAt = 0;
  function toggleMega(open) {
    if (!panel) return;
    clearTimeout(megaTimer);
    if (open) { if (panel.hidden) megaOpenedAt = Date.now(); panel.hidden = false; requestAnimationFrame(() => panel.classList.add('is-open')); }
    else { panel.classList.remove('is-open'); megaTimer = setTimeout(() => { panel.hidden = true; }, 180); }
    trigger.setAttribute('aria-expanded', String(open));
  }
  if (trigger && matchMedia('(hover: hover) and (min-width: 992px)').matches) {
    [trigger, panel].forEach((el) => {
      el.addEventListener('mouseenter', () => toggleMega(true));
      el.addEventListener('mouseleave', () => { megaTimer = setTimeout(() => toggleMega(false), 160); });
    });
  }
  document.addEventListener('click', (e) => { if (panel && !panel.hidden && !panel.contains(e.target) && !trigger.contains(e.target)) toggleMega(false); });

  document.addEventListener('keydown', (e) => {
    trapFocus(e);
    if (e.key === 'Escape') {
      if (panel && !panel.hidden) { toggleMega(false); trigger.focus(); return; }
      if ($('#main-menu')?.classList.contains('is-open')) { $('.iu-menu-close').click(); return; }
      closeLayer();
    }
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !openLayers.length) { e.preventDefault(); $('[data-open-search]')?.click(); }
  });

  // Kereső: élő találatok + nyilas navigáció
  const input = $('#search-input');
  let deb;
  input.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => runSearch(input.value), 120); });
  input.addEventListener('keydown', (e) => {
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

  $$('form[data-form-id="hirlevel"]').forEach((f) => bindSimpleForm(f));

  // Ragadós fejléc állapot
  const onScroll = () => head.classList.toggle('is-scrolled', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  cookieBar();
  refreshReveal();
}
