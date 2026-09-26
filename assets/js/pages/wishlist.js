// Kedvencek oldal (saját blokk: mandala/wishlist, cookie/localStorage alapú).
import { initPage, $, icon, productCard } from '../ui.js';
import { loadProducts, wishlist } from '../store.js';

initPage();
const products = await loadProducts();
function render() {
  const list = wishlist.ids().map((id) => products.find((p) => p.id === id)).filter(Boolean);
  $('[data-wishlist]').innerHTML = list.length
    ? `<ul class="products columns-4">${list.map(productCard).join('')}</ul>`
    : `<div class="empty-state">${icon('heart', 'ico ico-xl')}<h2 style="font-size:var(--fs-h3)">Még nincs kedvenced</h2><p>A termékkártyák szív ikonjával gyűjtheted ide azokat a darabokat, amelyekre később visszatérnél.</p><a class="iu-button" href="termekek.html">Kínálat böngészése</a></div>`;
}
document.addEventListener('wish:change', render);
render();
