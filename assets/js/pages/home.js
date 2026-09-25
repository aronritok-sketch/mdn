import { initPage, productCard, $, esc, icon, refreshReveal } from '../app.js';
import { art, mandala } from '../art.js';
import { postCard, quoteCard, routeMap } from '../blocks.js';
import { INTENTS, ARTICLES, TESTIMONIALS } from '../data.js';
import { loadProducts } from '../store.js';

initPage({ active: 'home' });

const tones = ['saffron', 'sage', 'maroon', 'sand'];
$('[data-intents]').innerHTML = INTENTS.map((i, n) => `
  <a class="intent reveal" href="termekek.html?intent=${i.id}">
    <span class="num">0${n + 1}</span>
    ${art(i.art, tones[n])}
    <h3>${esc(i.label)}</h3>
    <p>${esc(i.text)}</p>
    <span class="go">Válogatás ${icon('arrow')}</span>
  </a>`).join('');

$('[data-hero-mandala]').outerHTML = mandala({ petals: 18, rings: 5, className: 'hero-mandala' });
$('[data-gift-tile]').insertAdjacentHTML('afterbegin', art('gift', 'saffron'));
$('[data-route]').innerHTML = routeMap();
$('[data-quotes]').innerHTML = TESTIMONIALS.map(quoteCard).join('');
$('[data-posts]').innerHTML = ARTICLES.slice(0, 3).map(postCard).join('');

const products = await loadProducts();
$('[data-new-products]').innerHTML = products.filter((p) => p.isNew).slice(0, 8).map(productCard).join('');
$('[data-featured-products]').innerHTML = products.filter((p) => p.featured).slice(0, 4).map(productCard).join('');
refreshReveal();
