import { initPage, $, esc, params, setMeta, productCard, icon, img } from '../app.js';
import { art } from '../art.js';
import { fmtDate, postCard } from '../blocks.js';
import { ARTICLES } from '../data.js';
import { loadProducts } from '../store.js';

initPage({ active: 'mag' });

const a = ARTICLES.find((x) => x.slug === params().get('a'));
const root = $('[data-article]');
// Egyszerű **félkövér** jelölés a cikkszövegben.
const rich = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

if (!a) {
  root.innerHTML = '<div class="confirm"><h1>A cikk nem található</h1><a class="btn btn-primary" href="magazin.html">Vissza a magazinhoz</a></div>';
} else {
  setMeta({ title: a.title, description: a.excerpt });
  root.innerHTML = `<article class="container article">
    <nav class="crumbs" aria-label="Morzsamenü"><ol><li><a href="index.html">Kezdőlap</a></li><li><a href="magazin.html">Magazin</a></li><li aria-current="page">${esc(a.title)}</li></ol></nav>
    <time class="muted small" datetime="${a.date}">${fmtDate(a.date)}</time>
    <h1 style="font-size:clamp(2.2rem,4.4vw,3.4rem);margin-top:.4rem">${esc(a.title)}</h1>
    <p class="lead">${esc(a.excerpt)}</p>
    <div class="article-media">${a.image ? img(a.image, '', { eager: true, sizes: '720px' }) : art(a.art, a.tone)}</div>
    ${a.body.map((p) => `<p>${rich(p)}</p>`).join('')}
    <p><a class="btn btn-ghost" href="magazin.html">${icon('arrow')} Összes cikk</a></p>
  </article>`;

  const products = await loadProducts();
  const related = products.filter((p) => ['rituale-eszkozok', 'hangtalak', 'szobrok'].includes(p.sub)).slice(0, 4);
  $('[data-related]').innerHTML = `<div class="container">
    <div class="section-head"><div><p class="eyebrow">A cikkhez kapcsolódik</p><h2>Tárgyak a kézbe</h2></div></div>
    <div class="grid">${related.map(productCard).join('')}</div>
    <div class="section-head" style="margin-top:4rem"><div><p class="eyebrow">Magazin</p><h2>További cikkek</h2></div></div>
    <div class="grid grid-3">${ARTICLES.filter((x) => x.slug !== a.slug).map(postCard).join('')}</div>
  </div>`;
  document.querySelectorAll('.reveal').forEach((el) => el.classList.add('in'));
}
