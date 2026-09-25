import { initPage, productCard, productMedia, $, esc, icon, params, setMeta, addToCart } from '../app.js';
import { CONFIG, ORIGINS } from '../data.js';
import { categoryBySlug, findProduct, fmt, loadProducts, subLabel } from '../store.js';

initPage({ active: 'shop' });

const products = await loadProducts();
const p = findProduct(products, params().get('p'));
const root = $('[data-product]');

if (!p) {
  root.innerHTML = `<div class="confirm"><h1>Ezt a terméket nem találjuk</h1>
    <p class="lead" style="margin:0 auto 2rem">Lehet, hogy elfogyott vagy megváltozott a címe.</p>
    <a class="btn btn-primary" href="termekek.html">Vissza a kínálathoz</a></div>`;
  setMeta({ title: 'Nem található termék' });
} else {
  const cat = categoryBySlug(p.cat);
  const origin = ORIGINS[p.origin];
  const sale = p.compare > p.price;
  setMeta({ title: p.name, description: p.short });

  root.innerHTML = `
    <nav class="crumbs container" aria-label="Morzsamenü" style="padding-top:1.5rem"><ol>
      <li><a href="index.html">Kezdőlap</a></li>
      <li><a href="termekek.html?cat=${p.cat}">${esc(cat?.label)}</a></li>
      ${p.sub ? `<li><a href="termekek.html?cat=${p.cat}&sub=${p.sub}">${esc(subLabel(p.cat, p.sub))}</a></li>` : ''}
      <li aria-current="page">${esc(p.name)}</li>
    </ol></nav>
    <div class="container pdp">
      <div class="pdp-media"><div class="pdp-main">${productMedia(p, { large: true })}</div></div>
      <div class="pdp-info">
        <p class="card-meta"><span class="origin-dot" style="--dot:${origin?.tone}"></span>${esc(origin?.label)} · ${esc(subLabel(p.cat, p.sub))}</p>
        <h1>${esc(p.name)}</h1>
        <div class="pdp-price">${fmt(p.price)}${sale ? `<s>${fmt(p.compare)}</s>` : ''}</div>
        <p class="muted small">Az ár az ÁFÁ-t tartalmazza. ${p.price >= CONFIG.freeShippingFrom ? 'A szállítás ingyenes.' : ''}</p>
        <p class="pdp-short">${esc(p.short)}</p>
        <span class="stock ${p.stock === 'out' ? 'out' : ''}">${p.stock === 'out' ? 'Jelenleg nincs készleten' : 'Raktáron – 1–3 munkanapon belül szállítjuk'}</span>
        <form class="buy" data-buy>
          <div class="qty qty-lg" data-qty>
            <button type="button" data-step="-1" aria-label="Eggyel kevesebb">${icon('minus')}</button>
            <input type="number" name="qty" min="1" max="99" value="1" aria-label="Mennyiség">
            <button type="button" data-step="1" aria-label="Eggyel több">${icon('plus')}</button>
          </div>
          <button class="btn btn-primary" type="submit" ${p.stock === 'out' ? 'disabled' : ''}>${icon('bag')} Kosárba teszem</button>
        </form>
        <div class="origin-card">${icon('pin')}<p><strong>Eredet: ${esc(p.place || origin?.long || '')}</strong>
          Közvetlenül a készítő műhelyektől hozzuk be – minden darabot átnézünk, mielőtt a polcra kerül.</p></div>
        ${Object.keys(p.specs || {}).length ? `<table class="specs"><caption class="sr-only">Termékadatok</caption><tbody>
          ${Object.entries(p.specs).map(([k, v]) => `<tr><th scope="row">${esc(k)}</th><td>${esc(v)}</td></tr>`).join('')}
        </tbody></table>` : ''}
        <div class="acc">
          <details open><summary>Leírás ${icon('plus')}</summary><div class="acc-body"><p>${esc(p.description)}</p></div></details>
          ${p.ritual ? `<details><summary>Használat és gondozás ${icon('plus')}</summary><div class="acc-body"><p>${esc(p.ritual)}</p></div></details>` : ''}
          <details><summary>Szállítás és visszaküldés ${icon('plus')}</summary><div class="acc-body">
            <p>${CONFIG.shipping.map((s) => `${esc(s.label)}: ${s.price ? fmt(s.price) : 'ingyenes'}`).join(' · ')}. ${fmt(CONFIG.freeShippingFrom)} feletti rendelésnél a szállítás ingyenes.</p>
            <p>A terméket 14 napon belül indoklás nélkül visszaküldheted. <a href="informaciok.html#visszakuldes">Részletek</a></p>
          </div></details>
        </div>
        <ul class="perks">
          <li>${icon('truck')} Ingyenes szállítás ${fmt(CONFIG.freeShippingFrom)} felett</li>
          <li>${icon('return')} 14 napos visszaküldés</li>
          <li>${icon('hand')}<span>Kérdésed van? <a href="kapcsolat.html">Írj nekünk</a>, segítünk választani.</span></li>
        </ul>
      </div>
    </div>`;

  const form = $('[data-buy]');
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    addToCart(p.id, Math.max(1, Math.min(99, Number(form.qty.value) || 1)));
  });

  const related = products.filter((x) => x.id !== p.id && x.cat === p.cat)
    .sort((a, b) => (b.sub === p.sub) - (a.sub === p.sub)).slice(0, 4);
  if (related.length) {
    $('[data-related]').innerHTML = `<div class="container">
      <div class="section-head"><div><p class="eyebrow">Ehhez illik</p><h2>Hasonló darabok</h2></div></div>
      <div class="grid">${related.map(productCard).join('')}</div></div>`;
  }

  const ld = document.createElement('script');
  ld.type = 'application/ld+json';
  ld.textContent = JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: p.name,
    description: p.short,
    sku: String(p.id),
    category: cat?.label,
    countryOfOrigin: origin?.label,
    ...(p.image ? { image: p.image } : {}),
    offers: {
      '@type': 'Offer',
      priceCurrency: 'HUF',
      price: p.price,
      availability: p.stock === 'out' ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
    },
  });
  document.head.append(ld);
}
