// Kosár oldal – [woocommerce_cart]: table.shop_table.cart, kupon, .cart_totals, cross-sells.
import { initPage, $, esc, icon, productCard, productMedia, productUrl, originHtml, refreshReveal } from '../ui.js';
import { CONFIG } from '../data.js';
import { totals, coupon, fmt, loadProducts, maxQty } from '../store.js';

initPage();
const root = $('[data-cart]');
let couponMsg = null;

function shippingRow(t) {
  if (t.freeShipping) return '<td><strong style="color:var(--c-success)">Ingyenes</strong><span class="includes_tax">GLS, Foxpost vagy személyes átvétel</span></td>';
  return `<td><span class="includes_tax" style="margin:0">A pénztárban választod ki:</span>${CONFIG.shipping.map((s) => `<span class="includes_tax">${esc(s.label)}: ${s.price ? fmt(s.price) : 'ingyenes'}</span>`).join('')}</td>`;
}

async function render(focusSel) {
  const t = await totals();
  if (!t.items.length) {
    const products = await loadProducts();
    root.innerHTML = `<div class="empty-state">${icon('bag', 'ico ico-xl')}<h2 style="font-size:var(--fs-h3)">A kosarad jelenleg üres</h2>
      <p>Mielőtt fizetnél, tegyél a kosárba néhány terméket. Ezeket szeretik most a legtöbben:</p>
      <a class="iu-button" href="termekek.html">Vissza a kínálathoz</a></div>
      <div class="cross-sells"><div class="section-head"><div><p class="eyebrow">Népszerű</p><h2>Kedvenceink</h2></div></div><ul class="products columns-4">${products.filter((p) => p.featured).slice(0, 4).map(productCard).join('')}</ul></div>`;
    refreshReveal();
    return;
  }
  const pct = Math.min(100, ((t.subtotal - t.discount) / CONFIG.freeShippingFrom) * 100);
  root.innerHTML = `<div class="cart-layout">
    <form class="woocommerce-cart-form" onsubmit="return false" aria-label="Kosár tartalma">
      <table class="shop_table shop_table_responsive cart woocommerce-cart-form__contents">
        <caption class="sr-only">A kosárban lévő termékek</caption>
        <thead><tr><th class="product-thumbnail"><span class="sr-only">Kép</span></th><th class="product-name">Termék</th><th class="product-price" style="text-align:right">Ár</th><th class="product-quantity" style="text-align:center">Mennyiség</th><th class="product-subtotal" style="text-align:right">Összesen</th><th class="product-remove"><span class="sr-only">Törlés</span></th></tr></thead>
        <tbody>${t.items.map(({ product: p, qty }) => `<tr class="woocommerce-cart-form__cart-item cart_item">
          <td class="product-thumbnail"><a href="${productUrl(p)}" tabindex="-1" aria-hidden="true">${productMedia(p)}</a></td>
          <td class="product-name" data-title="Termék"><a href="${productUrl(p)}">${esc(p.name)}</a>
            <span class="variation">${originHtml(p)} · Cikkszám: ${esc(p.sku)}</span>
            ${qty >= maxQty(p) ? `<span class="variation" style="color:var(--c-accent-deep)">A készlet miatt legfeljebb ${maxQty(p)} db rendelhető.</span>` : ''}</td>
          <td class="product-price" data-title="Ár">${fmt(p.price)}</td>
          <td class="product-quantity" data-title="Mennyiség"><div class="quantity quantity-s" data-qty="${p.id}">
            <button type="button" data-step="-1" ${qty <= 1 ? 'disabled' : ''} aria-label="Eggyel kevesebb: ${esc(p.name)}">${icon('minus', 'ico ico-s')}</button>
            <input type="number" inputmode="numeric" min="1" max="${maxQty(p)}" value="${qty}" aria-label="Mennyiség: ${esc(p.name)}">
            <button type="button" data-step="1" ${qty >= maxQty(p) ? 'disabled' : ''} aria-label="Eggyel több: ${esc(p.name)}">${icon('plus', 'ico ico-s')}</button></div></td>
          <td class="product-subtotal" data-title="Összesen"><strong>${fmt(p.price * qty)}</strong></td>
          <td class="product-remove"><button type="button" class="remove" data-remove="${p.id}" aria-label="${esc(p.name)} törlése a kosárból">${icon('trash')}</button></td>
        </tr>`).join('')}</tbody>
      </table>
      <div class="cart-actions">
        <div class="coupon">${t.code
          ? `<span class="coupon-applied">${icon('check', 'ico ico-s')} ${esc(t.code)} – ${esc(CONFIG.coupons[t.code].label)}<button type="button" data-coupon-remove aria-label="Kupon eltávolítása">${icon('close', 'ico ico-s')}</button></span>`
          : `<label class="sr-only" for="coupon_code">Kuponkód</label><input type="text" class="input-text" id="coupon_code" placeholder="Kuponkód" autocomplete="off" ${couponMsg && !couponMsg.ok ? 'aria-invalid="true" aria-describedby="coupon-error"' : ''}>
             <button type="button" class="iu-button iu-button-outline" data-coupon-apply>Beváltás</button>`}
          ${couponMsg ? `<p class="${couponMsg.ok ? 'text-small' : 'field-error'}" id="coupon-error" role="status" style="${couponMsg.ok ? 'color:var(--c-success);width:100%;margin:0' : ''}">${couponMsg.ok ? '' : icon('alert', 'ico ico-s')}<span>${esc(couponMsg.message)}</span></p>` : ''}
        </div>
        <a class="iu-button iu-button-link" href="termekek.html">${icon('arrow-left', 'ico ico-s')} Vásárlás folytatása</a>
      </div>
    </form>
    <div class="cart-collaterals"><div class="cart_totals panel">
      <h2>Összesítő</h2>
      <div class="ship-meter ${t.freeShipping ? 'is-done' : ''}"><p>${t.freeShipping ? `${icon('check', 'ico ico-s')} A szállítás ingyenes.` : `Még <strong>${fmt(t.remaining)}</strong>, és ingyen szállítunk.`}</p><div class="meter" role="progressbar" aria-label="Ingyenes szállításig" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(pct)}"><span style="width:${pct}%"></span></div></div>
      <table class="totals-table shop_table"><tbody>
        <tr class="cart-subtotal"><th>Részösszeg (${t.count} db)</th><td>${fmt(t.subtotal)}</td></tr>
        ${t.discount ? `<tr class="discount cart-discount"><th>Kupon: ${esc(t.code)}</th><td>−${fmt(t.discount)}</td></tr>` : ''}
        <tr class="woocommerce-shipping-totals shipping"><th>Szállítás</th>${shippingRow(t)}</tr>
        <tr class="order-total"><th>Végösszeg</th><td>${fmt(t.subtotal - t.discount)}<small class="includes_tax">Tartalmaz ${fmt(Math.round(((t.subtotal - t.discount) * CONFIG.vatRate) / (100 + CONFIG.vatRate)))} ÁFA-t (${CONFIG.vatRate}%)${t.freeShipping ? '' : ', szállítás nélkül'}</small></td></tr>
      </tbody></table>
      <div class="wc-proceed-to-checkout">
        <a href="penztar.html" class="checkout-button iu-button iu-button-large iu-button-block">${icon('lock', 'ico ico-s')} Tovább a pénztárhoz</a>
        <div class="pay-marks" aria-label="Fizetési módok"><span>Barion</span><span>VISA</span><span>Mastercard</span><span>Apple Pay</span><span>Utalás</span><span>Utánvét</span></div>
      </div>
      <ul class="trust-mini" style="margin-top:var(--space-5)"><li>${icon('return', 'ico ico-s')}14 napos visszaküldés</li><li>${icon('store', 'ico ico-s')}Személyes átvétel Budapesten</li><li>${icon('hand', 'ico ico-s')}Kérdés esetén: ${esc(CONFIG.contact.phone)}</li></ul>
    </div></div>
  </div>
  <div class="cross-sells" data-cross></div>`;

  // Cross-sells: ugyanazon kategóriák, még nincs a kosárban
  const products = await loadProducts();
  const inCart = new Set(t.items.map((l) => l.id));
  const cats = new Set(t.items.map((l) => l.product.cat));
  const cross = products.filter((p) => !inCart.has(p.id) && p.stock !== 'out' && cats.has(p.cat)).slice(0, 4);
  if (cross.length) $('[data-cross]').innerHTML = `<div class="section-head"><div><p class="eyebrow">Ehhez illik</p><h2>Talán ezek is érdekelnek</h2></div></div><ul class="products columns-4">${cross.map(productCard).join('')}</ul>`;
  refreshReveal();
  if (focusSel) $(focusSel)?.focus();
}

// A léptetők és a törlés a ui.js-ben frissítik a kosarat; itt csak újrarajzolunk, a fókuszt megtartva.
let pendingFocus = null;
document.addEventListener('click', (e) => {
  const step = e.target.closest('[data-step]');
  if (step) { const id = step.closest('[data-qty]')?.dataset.qty; pendingFocus = `[data-qty="${id}"] [data-step="${step.dataset.step}"]:not([disabled]), [data-qty="${id}"] input`; }
  if (e.target.closest('[data-remove]')) pendingFocus = '.remove, .checkout-button, .empty-state a';
  if (e.target.closest('[data-coupon-apply]')) applyCoupon();
  if (e.target.closest('[data-coupon-remove]')) { couponMsg = { ok: true, message: 'A kupont eltávolítottuk.' }; pendingFocus = '#coupon_code'; coupon.remove(); }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'coupon_code') { e.preventDefault(); applyCoupon(); } });
async function applyCoupon() {
  const t = await totals();
  couponMsg = coupon.apply($('#coupon_code').value, t.subtotal);
  pendingFocus = couponMsg.ok ? '[data-coupon-remove]' : '#coupon_code';
  if (!couponMsg.ok) render(pendingFocus);
}
document.addEventListener('cart:change', () => { const f = pendingFocus; pendingFocus = null; render(f); });
render();
