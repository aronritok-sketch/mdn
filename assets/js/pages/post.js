// single_post sablon: iu/breadcrumbs, iu/title, meta, iu/featured-image, iu/content, szerző, iu/post-navigation, kapcsolódó iu/query.
import { initPage, $, esc, icon, img, params, setMeta, jsonLd, breadcrumbs, productCard, refreshReveal } from '../ui.js';
import { art } from '../art.js';
import { fmtDate, postCard } from '../blocks.js';
import { ARTICLES } from '../data.js';
import { loadProducts } from '../store.js';

initPage({ active: 'mag' });
const idx = ARTICLES.findIndex((x) => x.slug === params().get('a'));
const a = ARTICLES[idx];
const root = $('[data-post]');
const rich = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

function block([type, ...args]) {
  switch (type) {
    case 'p': return `<p>${rich(args[0])}</p>`;
    case 'h2': case 'h3': case 'h4': return `<${type}>${esc(args[0])}</${type}>`;
    case 'ul': case 'ol': return `<${type}>${args[0].map((li) => `<li>${rich(li)}</li>`).join('')}</${type}>`;
    case 'quote': return `<blockquote><p style="margin:0">${esc(args[0])}</p>${args[1] ? `<cite>— ${esc(args[1])}</cite>` : ''}</blockquote>`;
    case 'figure': return `<figure>${img(args[0], args[1], { sizes: '720px' })}<figcaption>${esc(args[1])}</figcaption></figure>`;
    case 'table': return `<div class="table-wrap"><table><thead><tr>${args[0].map((h) => `<th scope="col">${esc(h)}</th>`).join('')}</tr></thead><tbody>${args[1].map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    case 'button': return `<div class="iu-button-group"><a class="iu-button" href="${esc(args[1])}">${esc(args[0])} ${icon('arrow', 'ico ico-s')}</a></div>`;
    default: return '';
  }
}

if (!a) {
  root.innerHTML = `<section class="iu-section"><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="empty-state"><h1 class="iu-title" style="font-size:var(--fs-h2)">A cikk nem található</h1><a class="iu-button" href="magazin.html">Vissza a magazinhoz</a></div></div></div></section>`;
} else {
  setMeta({ title: a.title, description: a.excerpt });
  const prev = ARTICLES[idx + 1];
  const next = ARTICLES[idx - 1];
  root.innerHTML = `<article>
    <section class="iu-section" style="padding-bottom:var(--space-6)"><div class="iu-row" style="max-width:800px"><div class="iu-column iu-column-1-1">
      ${breadcrumbs([['Kezdőlap', 'index.html'], ['Magazin', 'magazin.html'], [a.title]])}
      <p class="post-meta"><a href="magazin.html?kategoria=${encodeURIComponent(a.category)}" style="color:inherit">${esc(a.category)}</a><time datetime="${a.date}">${fmtDate(a.date)}</time><span>${a.minutes} perc olvasás</span></p>
      <h1 class="iu-title" style="font-size:clamp(2.25rem,4.6vw,3.5rem);margin-top:var(--space-3)">${esc(a.title)}</h1>
      <p class="lead">${esc(a.excerpt)}</p>
    </div></div>
    <div class="iu-row" style="max-width:1040px"><div class="iu-column iu-column-1-1"><div class="media-frame" style="aspect-ratio:16/9">${a.image ? img(a.image, '', { eager: true, sizes: '1040px' }).replace('<img', '<img style="height:100%;object-fit:cover"') : art(a.art, a.tone)}</div></div></div></section>
    <section class="iu-section" style="padding-top:0"><div class="iu-row" style="max-width:720px"><div class="iu-column iu-column-1-1">
      <div class="entry-content">${a.content.map(block).join('')}</div>
      <div class="author-box" style="margin-top:var(--space-7)"><span class="avatar" aria-hidden="true">M</span><p style="margin:0"><strong>${esc(a.author)}</strong><br><span class="text-muted text-small">Nepálban és Indiában járunk a tárgyak után – és megírjuk, amit megtudtunk róluk.</span></p></div>
      <nav class="post-nav" aria-label="Bejegyzések" style="margin-top:var(--space-6)">
        ${prev ? `<a class="prev" href="cikk.html?a=${prev.slug}"><small>← Előző cikk</small>${esc(prev.title)}</a>` : '<span></span>'}
        ${next ? `<a class="next" href="cikk.html?a=${next.slug}"><small>Következő cikk →</small>${esc(next.title)}</a>` : ''}
      </nav>
    </div></div></section>
  </article>
  <section class="iu-section" style="background-color:var(--wp--preset--color--sand)"><div class="iu-row"><div class="iu-column iu-column-1-1">
    <div class="section-head"><div><p class="eyebrow">A cikkhez kapcsolódik</p><h2>Tárgyak a kézbe</h2></div></div><ul class="products columns-4" data-rel-products></ul>
  </div></div></section>
  <section class="iu-section"><div class="iu-row"><div class="iu-column iu-column-1-1">
    <div class="section-head"><div><p class="eyebrow">Magazin</p><h2>További cikkek</h2></div><a class="iu-button iu-button-outline" href="magazin.html">Összes cikk</a></div>
    <div class="iu-query iu-query-col-3">${ARTICLES.filter((x) => x.slug !== a.slug).slice(0, 3).map(postCard).join('')}</div>
  </div></div></section>`;
  const products = await loadProducts();
  const bySub = { Hangszerek: ['hangtalak', 'rituale-eszkozok'], 'Otthon és oltár': ['szobrok', 'spiritualis-dekor', 'rez-kulacsok', 'fustolok'], Szimbólumok: ['szobrok', 'mala-lancok'], Rituálé: ['fustolok', 'fustolotartok'] };
  $('[data-rel-products]').innerHTML = products.filter((p) => (bySub[a.category] || []).includes(p.sub)).slice(0, 4).map(productCard).join('');
  refreshReveal();
  jsonLd({ '@context': 'https://schema.org', '@type': 'Article', headline: a.title, description: a.excerpt, datePublished: a.date, author: { '@type': 'Organization', name: 'Mandala' } });
}
