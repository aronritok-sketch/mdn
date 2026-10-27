// Termékoldal: galéria, AJAX kosárba tétel (a minikosár kinyílik), ragadós kosárba sáv.
import { $, $$, esc } from './env.js';
import { addToCart, applyFragments, openCart, toast } from 'mandala-site';
import { isElinBlock, isFailure, cartHash, addToCartUrl, probeCart, recoverAddToCart, reportBlocked } from './net.js';

// Galéria: bélyegképre kattintva csere a fő képben.
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-view]');
  if (!b) return;
  const img = $('[data-gallery-main] img');
  if (img) { img.src = b.dataset.view; img.removeAttribute('srcset'); }
  $$('[data-view]').forEach((x) => x.setAttribute('aria-current', String(x === b)));
});

const form = $('form[data-cart-form]');
async function submitCart(qty, fields = null) {
  const btn = $('.single_add_to_cart_button', form);
  btn.classList.add('is-loading'); btn.disabled = true;
  const data = await addToCart(form.dataset.productId, qty, fields);
  if (data?.pending) return; // GET tartalék: az oldal épp átnavigál (net.js)
  if (data && form.dataset.redirect) { location.href = form.dataset.redirect; return; } // pl. utalvány: a kosár oldalára
  btn.classList.remove('is-loading'); btn.disabled = false;
  if (data) openCart();
  else toast('Ebből a termékből most nem tudunk többet a kosárba tenni – a készlet korlátozott.');
}
// Minden kosár űrlap AJAX-szal megy – az extra mezősek is (előfizetés, utalvány): a teljes oldalas POST-ot a tárhely
// tűzfala (ELIN 403) egyes vásárlóknál fehér „403 Forbidden” oldallal utasítja el. A mezők a kéréssel mennek, a
// szerver kosár-szűrői ugyanúgy olvassák őket; ha a POST nem megy, a GET tartalék viszi be őket (net.js).
form?.addEventListener('submit', (e) => {
  e.preventDefault();
  const input = $('input[name="quantity"]', form);
  const qty = Math.max(1, Math.min(Number(input.max) || 99, Number(input.value) || 1));
  submitCart(qty, 'native' in form.dataset ? new FormData(form) : null);
});
$('[data-add-sticky]')?.addEventListener('click', () => {
  if (form && 'native' in form.dataset) { form.scrollIntoView({ behavior: 'smooth', block: 'center' }); $('input, textarea', form)?.focus({ preventScroll: true }); return; }
  submitCart(1);
});

// Változatos termék (a WooCommerce saját űrlapja, teljes oldalas POST): ugyanaz a POST fetch-csel, hogy a tiltás ne
// fehér oldal legyen. A dokumentumon figyelünk, így a WooCommerce saját ellenőrzése (nincs kiválasztott változat)
// előbb lefut, és ha ő megállította a beküldést, mi sem küldjük.
document.addEventListener('submit', async (e) => {
  const vf = e.target.closest?.('form.variations_form');
  if (!vf || e.defaultPrevented) return;
  const vid = Number($('input[name="variation_id"]', vf)?.value || 0);
  const btn = $('.single_add_to_cart_button', vf);
  if (!vid || btn?.classList.contains('disabled')) return;
  e.preventDefault();
  const fields = new FormData(vf);
  const id = Number(fields.get('add-to-cart') || vf.dataset.product_id || 0);
  const qty = Number(fields.get('quantity') || 1);
  btn?.classList.add('is-loading');
  const before = cartHash();
  let res = null;
  try { res = await fetch(vf.action || location.href, { method: 'POST', credentials: 'same-origin', body: fields }); } catch { res = null; }
  if (!res || isFailure(res.status)) {
    const text = res ? await res.text().catch(() => '') : '';
    const blocked = !!res && isElinBlock(res.status, res.headers.get('content-type') || '', text);
    if (blocked) reportBlocked('product_form');
    const out = await recoverAddToCart({ status: res?.status || 0, blocked, before, fallbackUrl: addToCartUrl({ id, qty, fields }), navigate: (u) => location.assign(u),
      onAdded: (fr) => { applyFragments(fr.fragments, fr.cart_hash); openCart(); } });
    if (out === 'added') btn?.classList.remove('is-loading');
    return;
  }
  if (res.redirected) { location.assign(res.url); return; }
  const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
  btn?.classList.remove('is-loading');
  const errors = [...doc.querySelectorAll('.woocommerce-error li, .woocommerce-error')].map((x) => x.textContent.trim()).filter(Boolean);
  if (errors.length) { toast(esc(errors[0]), { ico: 'alert', timeout: 7000 }); return; }
  const fr = await probeCart();
  if (fr?.fragments) applyFragments(fr.fragments, fr.cart_hash);
  document.dispatchEvent(new CustomEvent('mandala:cart-add', { detail: { items: [[vid, qty]], source: 'product' } }));
  openCart();
});

// Ragadós kosárba sáv, ha a gomb kigördült a képből.
const sticky = $('[data-sticky-atc]');
if (form && sticky && 'IntersectionObserver' in window) {
  new IntersectionObserver(([en]) => {
    const show = !en.isIntersecting && en.boundingClientRect.top < 0;
    sticky.classList.toggle('is-visible', show);
    sticky.setAttribute('aria-hidden', String(!show));
    $('[data-add-sticky]').tabIndex = show ? 0 : -1;
  }).observe(form);
}

// Megnézett termék (elhagyott böngészés levél, browse.php): csak feliratkozónak (mandala_k süti) és
// marketing sütikhez adott hozzájárulással jelez; az azonosító httpOnly sütiben van, JS-ből nem olvasható.
(() => {
  const M = window.MANDALA || {};
  let consent = null;
  try { consent = JSON.parse(localStorage.getItem('mandala.cookie.v1') || 'null'); } catch { /* privát mód */ }
  if (!M.productId || !M.rest || !consent?.marketing || !/(?:^|; )mandala_k=1(?:;|$)/.test(document.cookie)) return;
  const body = new Blob([JSON.stringify({ id: M.productId })], { type: 'application/json' });
  if (!navigator.sendBeacon?.(`${M.rest}seen`, body)) fetch(`${M.rest}seen`, { method: 'POST', body, credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' } }).catch(() => {});
})();
