// Termékoldal – single_product_content.html sablon blokkjai:
// iu/breadcrumbs, images, iu/title, product-badges, price, stock, short-description,
// add-to-cart, product-attributes, iu/tabs (leírás, használat, szállítás, kérdés űrlap).
import { initPage, productCard, productMedia, $, $$, esc, icon, params, setMeta, jsonLd, breadcrumbs, originHtml, priceBlock, stockHtml, badgesHtml, wishButton, addToCart, formField, acceptField, bindSimpleForm, refreshReveal } from '../ui.js';
import { bindTabs, bindCarousel, carouselNav } from '../blocks.js';
import { CONFIG, ORIGINS } from '../data.js';
import { categoryBySlug, findProduct, fmt, loadProducts, subLabel, maxQty, vatOf } from '../store.js';

initPage({ active: 'shop' });

const products = await loadProducts();
const p = findProduct(products, params().get('p'));
const root = $('[data-product]');

if (!p) {
  setMeta({ title: 'A termék nem található' });
  root.innerHTML = `<section class="iu-section"><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="empty-state">${icon('search', 'ico ico-xl')}
    <h1 class="iu-title" style="font-size:var(--fs-h2)">Ezt a terméket nem találjuk</h1><p>Lehet, hogy elfogyott, vagy megváltozott a címe.</p>
    <div class="iu-button-group iu-button-group-center"><a class="iu-button" href="termekek.html">Vissza a kínálathoz</a><button type="button" class="iu-button iu-button-outline" data-open-search>Keresés</button></div></div></div></div></section>`;
} else {
  const cat = categoryBySlug(p.cat);
  const origin = ORIGINS[p.origin];
  const out = p.stock === 'out';
  const views = [0, ...p.images.map((_, i) => i + 1)];
  setMeta({ title: p.name, description: p.short });

  root.innerHTML = `
  <section class="iu-section product-layout" style="padding-bottom:var(--space-8)">
    <div class="iu-row"><div class="iu-column iu-column-1-1">${breadcrumbs([['Kezdőlap', 'index.html'], [cat?.label, `termekek.html?cat=${p.cat}`], ...(p.sub ? [[subLabel(p.cat, p.sub), `termekek.html?cat=${p.cat}&sub=${p.sub}`]] : []), [p.name]])}</div></div>
    <div class="iu-row">
      <div class="iu-column iu-column-1-2">
        <div class="product-gallery">
          <div class="product-gallery-main" data-gallery-main>${productMedia(p)}${badgesHtml(p)}</div>
          ${views.length > 1 ? `<ul class="product-gallery-thumbs" aria-label="Termékképek">${views.map((v, i) => `<li><button type="button" data-view="${v}" aria-current="${i === 0}" aria-label="${i + 1}. kép">${productMedia(p, v)}</button></li>`).join('')}</ul>` : ''}
        </div>
      </div>
      <div class="iu-column iu-column-1-2"><div class="product-summary">
        <div class="product-meta-row">${originHtml(p)}<span>${esc(subLabel(p.cat, p.sub))}</span><span class="sku_wrapper">Cikkszám: <span class="sku">${esc(p.sku || '–')}</span></span></div>
        <h1 class="product_title iu-title">${esc(p.name)}</h1>
        <div class="price-row">${priceBlock(p)}<span class="tax-note">Tartalmazza a ${CONFIG.vatRate}% ÁFÁ-t (${fmt(vatOf(p.price))})</span></div>
        <p class="woocommerce-product-details__short-description">${esc(p.short)}</p>
        ${stockHtml(p)}
        ${out ? `<div class="product-notify"><strong>Értesítünk, ha újra raktáron lesz</strong>
            <form class="iu-form" data-form-id="keszlet-ertesito" novalidate data-success="Rendben! Írunk, amint újra elérhető.">
              <div class="nl-row">${formField({ id: 'notify-email', name: 'email', label: 'E-mail-cím', type: 'email', auto: 'email', placeholder: 'nev@pelda.hu', validate: 'required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek.' }).replace('<label', '<label class="sr-only"')}<button class="iu-button" type="submit">Értesítést kérek</button></div>
              ${acceptField('notify-accept', 'Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>.')}
              <p class="form-message" role="status"></p></form></div>`
          : `<form class="cart" data-cart-form>
            <div class="quantity" data-qty><button type="button" data-step="-1" aria-label="Eggyel kevesebb">${icon('minus')}</button>
              <input type="number" inputmode="numeric" name="quantity" min="1" max="${maxQty(p)}" value="1" aria-label="Mennyiség"><button type="button" data-step="1" aria-label="Eggyel több">${icon('plus')}</button></div>
            <button type="submit" class="iu-button iu-button-large single_add_to_cart_button">${icon('bag')} Kosárba teszem</button>
            ${wishButton(p, 'iu-button iu-button-outline iu-button-large product-wish')}
          </form>`}
        <div class="origin-card">${icon('pin')}<p><strong>Eredet: ${esc(p.place || origin?.long || '')}</strong>Közvetlenül a készítő műhelytől hozzuk be – minden darabot átnézünk, mielőtt a polcra kerül.</p></div>
        ${Object.keys(p.specs || {}).length ? `<table class="woocommerce-product-attributes shop_attributes"><caption class="sr-only">Termékadatok</caption><tbody>
          ${Object.entries(p.specs).map(([k, v]) => `<tr><th scope="row">${esc(k)}</th><td>${esc(v)}</td></tr>`).join('')}</tbody></table>` : ''}
        <ul class="product-assurance">
          <li>${icon('truck')}<span>${p.price >= CONFIG.freeShippingFrom ? 'Ingyenes szállítás erre a termékre' : `Ingyenes szállítás ${fmt(CONFIG.freeShippingFrom)} felett`}</span></li>
          <li>${icon('store')}<span>Személyesen is átveheted budapesti bemutatótermünkben</span></li>
          <li>${icon('return')}<span>14 napon belül indoklás nélkül visszaküldheted</span></li>
        </ul>
      </div></div>
    </div>
  </section>

  <section class="iu-section" style="background-color:var(--wp--preset--color--white);padding-top:var(--space-8)"><div class="iu-row"><div class="iu-column iu-column-3-4">
    <div class="iu-tabs">
      <div class="iu-tabs-nav" role="tablist" aria-label="Termékinformációk">
        ${['Leírás', 'Használat és gondozás', 'Szállítás és visszaküldés', 'Kérdésed van?'].map((t, i) => `<button type="button" role="tab" id="tab-${i}" aria-controls="panel-${i}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${t}</button>`).join('')}
      </div>
      <div class="iu-tab entry-content" role="tabpanel" id="panel-0" aria-labelledby="tab-0" tabindex="0"><p>${esc(p.description)}</p></div>
      <div class="iu-tab entry-content" role="tabpanel" id="panel-1" aria-labelledby="tab-1" tabindex="0" hidden><p>${esc(p.ritual || 'Száraz, pormentes helyen tárold; puha kendővel tisztítsd.')}</p></div>
      <div class="iu-tab entry-content" role="tabpanel" id="panel-2" aria-labelledby="tab-2" tabindex="0" hidden>
        <div class="table-wrap"><table><thead><tr><th>Szállítási mód</th><th>Idő</th><th>Díj</th></tr></thead><tbody>
          ${CONFIG.shipping.map((s) => `<tr><td>${esc(s.label)}</td><td>${esc(s.note)}</td><td>${s.price ? `${fmt(s.price)} <span class="text-muted">(${fmt(CONFIG.freeShippingFrom)} felett ingyenes)</span>` : 'Ingyenes'}</td></tr>`).join('')}
        </tbody></table></div>
        <p>A terméket az átvételtől számított 14 napon belül indoklás nélkül visszaküldheted. <a href="informaciok.html#visszakuldes">A visszaküldés menete</a></p></div>
      <div class="iu-tab" role="tabpanel" id="panel-3" aria-labelledby="tab-3" tabindex="0" hidden>
        <form class="iu-form" data-form-id="termekkerdes" novalidate data-success="Köszönjük a kérdést! Egy munkanapon belül válaszolunk e-mailben." style="max-width:640px">
          <p class="text-muted" style="margin:0">Hangfelvételt, pontos méretet vagy további fotót is kérhetsz – írunk e-mailben.</p>
          <div class="form-grid">
            ${formField({ id: 'q-name', name: 'name', label: 'Név', auto: 'name', validate: 'required|Add meg a neved.' })}
            ${formField({ id: 'q-email', name: 'email', label: 'E-mail-cím', type: 'email', auto: 'email', validate: 'required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek.' })}
            ${formField({ id: 'q-msg', name: 'message', label: 'Kérdésed', textarea: true, span: 'span-2', validate: 'required|Írd le a kérdésed.', value: '' })}
          </div>
          <input type="hidden" name="product" value="${esc(p.sku)}">
          ${acceptField('q-accept', 'Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>.')}
          <div><button type="submit" class="iu-button">Kérdés elküldése</button></div>
          <p class="form-message" role="status"></p>
        </form></div>
    </div>
  </div></div></section>

  <section class="iu-section" data-related-wrap><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="carousel" data-carousel>
    <div class="section-head"><div><p class="eyebrow">Ehhez illik</p><h2>Hasonló darabok</h2></div><div data-carousel-nav></div></div>
    <ul class="products carousel-track" data-related aria-label="Hasonló termékek"></ul><div class="carousel-progress" aria-hidden="true"><span></span></div>
  </div></div></div></section>

  ${out ? '' : `<div class="sticky-atc" data-sticky-atc aria-hidden="true"><div class="iu-row">
    <span class="thumb">${productMedia(p)}</span><span class="title">${esc(p.name)}</span><strong class="num hide-mobile">${fmt(p.price)}</strong>
    <button type="button" class="iu-button" data-add-sticky tabindex="-1">${icon('bag', 'ico ico-s')} Kosárba · <span class="d-none m-inline-flex">${fmt(p.price)}</span><span class="hide-mobile">${fmt(p.price)}</span></button></div></div>`}`;

  bindTabs(root);
  $$('form[data-form-id]', root).forEach((f) => bindSimpleForm(f));

  // Galéria
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-view]');
    if (!b) return;
    $('[data-gallery-main]').innerHTML = productMedia(p, Number(b.dataset.view)) + badgesHtml(p);
    $$('[data-view]').forEach((x) => x.setAttribute('aria-current', String(x === b)));
  });

  // Kosárba
  const form = $('[data-cart-form]');
  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    addToCart(p.id, Math.max(1, Math.min(maxQty(p), Number(form.quantity.value) || 1)), { openDrawer: true });
  });
  $('[data-add-sticky]')?.addEventListener('click', () => addToCart(p.id, 1, { openDrawer: true }));

  // Ragadós kosárba sáv, ha a gomb kigördült
  const sticky = $('[data-sticky-atc]');
  if (form && sticky && 'IntersectionObserver' in window) {
    new IntersectionObserver(([en]) => {
      const show = !en.isIntersecting && en.boundingClientRect.top < 0;
      sticky.classList.toggle('is-visible', show);
      sticky.setAttribute('aria-hidden', String(!show));
      $('[data-add-sticky]').tabIndex = show ? 0 : -1;
    }).observe(form);
  }

  // Hasonló termékek
  const related = products.filter((x) => x.id !== p.id && x.cat === p.cat && x.stock !== 'out')
    .sort((a, b) => (b.sub === p.sub) - (a.sub === p.sub)).slice(0, 8);
  if (related.length) {
    $('[data-related]').innerHTML = related.map(productCard).join('');
    $('[data-carousel-nav]').innerHTML = carouselNav('Hasonló termékek');
    bindCarousel($('[data-carousel]', root));
  } else $('[data-related-wrap]').remove();
  refreshReveal();

  jsonLd({
    '@context': 'https://schema.org', '@type': 'Product', name: p.name, description: p.short, sku: p.sku, category: cat?.label,
    brand: { '@type': 'Brand', name: 'Mandala' }, countryOfOrigin: origin?.label,
    offers: { '@type': 'Offer', priceCurrency: 'HUF', price: p.price, availability: `https://schema.org/${out ? 'OutOfStock' : p.stock === 'low' ? 'LimitedAvailability' : 'InStock'}` },
  });
}
