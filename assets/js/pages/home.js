import { initPage, productCard, productMedia, productUrl, $, $$, esc, icon, refreshReveal, jsonLd } from '../ui.js';
import { art, mandala } from '../art.js';
import { postCard, quoteCard, routeMap, bindCarousel, carouselNav } from '../blocks.js';
import { INTENTS, ARTICLES, TESTIMONIALS, CONFIG } from '../data.js';
import { loadProducts, fmt } from '../store.js';

initPage({ active: 'home' });

const tones = ['saffron', 'sage', 'maroon', 'sand'];
$('[data-intents]').innerHTML = INTENTS.map((it, n) => `<div class="iu-column iu-column-1-4">
  <a class="iu-card intent-card reveal" href="termekek.html?intent=${it.id}">
    <div class="iu-card-image">${art(it.art, tones[n])}</div>
    <div class="iu-card-body"><span class="num">0${n + 1}</span><h3>${esc(it.label)}</h3><p>${esc(it.text)}</p><span class="go">Válogatás ${icon('arrow', 'ico ico-s')}</span></div>
  </a></div>`).join('');

$('[data-hero-mandala]').outerHTML = mandala({ petals: 18, rings: 5, className: 'hero-mandala' });
$('[data-gift-tile]').insertAdjacentHTML('afterbegin', art('gift', 'saffron'));
$('[data-route]').innerHTML = routeMap();
$('[data-quotes]').innerHTML = TESTIMONIALS.map((q) => `<div class="iu-column iu-column-1-3">${quoteCard(q)}</div>`).join('');
$('[data-posts]').innerHTML = ARTICLES.slice(0, 3).map(postCard).join('');

const products = await loadProducts();
$$('[data-count]').forEach((el) => { el.textContent = `${products.filter((p) => p.cat === el.dataset.count).length} termék`; });

// Heti kiemelt (hős jobb alsó sarok)
const pick = products.find((p) => p.slug === 'mintas-hangtal-560g-390hz') || products[0];
$('[data-hero-pick]').innerHTML = `<a class="hero-pick" href="${productUrl(pick)}"><span class="thumb">${productMedia(pick)}</span>
  <span><small>A hét hangtála</small><strong>${esc(pick.name)}</strong><span class="num">${fmt(pick.price)} · ${esc(pick.specs.Frekvencia || '')}</span></span></a>`;

$('[data-new-products]').innerHTML = products.filter((p) => p.isNew).slice(0, 12).map(productCard).join('');
$('[data-carousel-nav]').insertAdjacentHTML('beforeend', carouselNav('Újdonságok'));
bindCarousel($('[data-carousel]'));
$('[data-featured-products]').innerHTML = products.filter((p) => p.featured && p.stock !== 'out').slice(0, 4).map(productCard).join('');
refreshReveal();

jsonLd({ '@context': 'https://schema.org', '@type': 'Store', name: 'Mandala', url: 'https://mandala.hu/', email: CONFIG.contact.email, telephone: CONFIG.contact.phone, sameAs: [CONFIG.contact.facebook] });
