#!/usr/bin/env python3
"""Oldal-generátor: a közös <head>-del és az iu_theme szerkezettel (iu/section >
iu/row > iu/column) legyártja a prototípus HTML oldalait.

    python3 tools/pages.py

A statikus szöveg itt van (SEO: a tartalom JavaScript nélkül is a HTML-ben
van), a termék- és kosárfüggő részeket az assets/js/pages/*.js tölti ki.
Minden szekció egy iu/section-nek felel meg; a megfeleltetés a
docs/IU-BLOKKTERKEP.md-ben van.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

HEAD = '''<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{title}</title>
  <meta name="description" content="{desc}">
  <meta name="theme-color" content="#16120F">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Mandala">
  <meta property="og:title" content="{title}">
  <meta property="og:description" content="{desc}">
  <meta property="og:image" content="assets/img/hangtalak-gyertyafeny-1600.webp">
  {robots}<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap">
  <link rel="stylesheet" href="assets/css/vars.css">
  <link rel="stylesheet" href="assets/css/iu.css">
  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/shop.css">
</head>
<body class="{body}">
  <header id="site-header"></header>
  <main id="main" tabindex="-1">
{main}
  </main>
  <footer id="site-footer"></footer>
  <script type="module" src="assets/js/pages/{script}.js"></script>
</body>
</html>
'''

ICON = {
    'pin': '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    'truck': '<path d="M3 6.5h11v10H3zM14 10h4l3 3.2v3.3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
    'hand': '<path d="M7 11V6.5a1.5 1.5 0 0 1 3 0V11m0-1V5a1.5 1.5 0 0 1 3 0v5m0 0V6.5a1.5 1.5 0 0 1 3 0V13c0 4-2.5 7-6 7s-5-2-6.5-4.5L5 12.5a1.5 1.5 0 0 1 2.5-1.5z"/>',
    'return': '<path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
    'arrow': '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'plus': '<path d="M12 5v14M5 12h14"/>',
    'search': '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
}
def ico(name, cls='ico'):
    return f'<svg class="{cls}" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">{ICON[name]}</svg>'

def pic(name, alt, sizes='100vw', eager=False, style=''):
    big = {'rez-sarkanyok': 976, 'ruhazat-to': 885}.get(name, 1600)
    load = 'fetchpriority="high"' if eager else 'loading="lazy"'
    st = f' style="{style}"' if style else ''
    return (f'<img src="assets/img/{name}-800.webp" srcset="assets/img/{name}-800.webp 800w, assets/img/{name}-{big}.webp {big}w" '
            f'sizes="{sizes}" alt="{alt}" {load} decoding="async"{st}>')

def head(eyebrow, title, text='', crumbs=None, extra=''):
    c = ''
    if crumbs:
        items = ''.join(
            f'<li><a href="{h}">{l}</a></li>' if h else f'<li><span aria-current="page">{l}</span></li>' for l, h in crumbs)
        c = f'<nav class="iu-breadcrumbs" aria-label="Morzsamenü"><ol>{items}</ol></nav>'
    eb = f'<p class="eyebrow">{eyebrow}</p>' if eyebrow else ''
    tx = f'<p>{text}</p>' if text else ''
    return f'''    <section class="iu-section page-head"><div class="iu-row"><div class="iu-column iu-column-1-1">
      {c}{eb}<h1 class="iu-title">{title}</h1>{tx}{extra}
    </div></div></section>'''

HOME = '''    <!-- iu/section: hős (bgImage + lightText) -->
    <section class="iu-section iu-section-light hero" aria-labelledby="hero-title">
      <div class="hero-media">''' + pic('hangtalak-gyertyafeny', 'Nepáli hangtálak gyertyafényben, füstölővel, mandala falikép előtt', eager=True) + '''</div>
      <div data-hero-mandala></div>
      <div class="iu-row">
        <div class="iu-column iu-column-2-3">
          <p class="eyebrow">Kézzel válogatva Nepálból és Indiából</p>
          <h1 id="hero-title">Lassulj le, <em>érkezz meg.</em></h1>
          <p class="lead">Hangtálak, füstölők, mala láncok és rituális tárgyak azoknak, akik a hétköznapokban is helyet adnának a csendnek, a figyelemnek és a belső egyensúlynak.</p>
          <div class="iu-button-group">
            <a class="iu-button iu-button-large" href="termekek.html?cat=szakralis-targyak">Szakrális tárgyak</a>
            <a class="iu-button iu-button-outline iu-button-large" href="rolunk.html">Honnan érkeznek?</a>
          </div>
        </div>
        <div class="iu-column iu-column-1-3" data-hero-pick></div>
      </div>
    </section>

    <!-- iu/section: bizalmi sáv (4 × iu/column 1-4) -->
    <section class="iu-section trust" aria-label="Amiért nálunk vásárolnak">
      <div class="iu-row">
        <div class="iu-column iu-column-1-4">''' + ico('pin') + '''<p><strong>Közvetlen import</strong>Nepál és India műhelyeiből</p></div>
        <div class="iu-column iu-column-1-4">''' + ico('truck') + '''<p><strong>Ingyenes szállítás</strong>25 000 Ft feletti rendelésnél</p></div>
        <div class="iu-column iu-column-1-4">''' + ico('hand') + '''<p><strong>Személyes tanács</strong>Segítünk a választásban</p></div>
        <div class="iu-column iu-column-1-4">''' + ico('return') + '''<p><strong>14 napos visszaküldés</strong>Indoklás nélkül</p></div>
      </div>
    </section>

    <!-- iu/section: szándék szerint (iu/card × 4) -->
    <section class="iu-section" aria-labelledby="intent-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="section-head reveal"><div>
        <p class="eyebrow">Szándék szerint</p>
        <h2 id="intent-title">Mire van most szükséged?</h2>
        <p>Nem mindig tudjuk, milyen tárgyat keresünk – de azt igen, mit szeretnénk érezni. Kezdd innen.</p>
      </div></div></div></div>
      <div class="iu-row" data-intents></div>
    </section>

    <!-- iu/section: kategóriák (2-3|1-3, majd 1-1) -->
    <section class="iu-section" style="background-color:var(--wp--preset--color--sand)" aria-labelledby="cat-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="section-head reveal"><div>
        <p class="eyebrow">Kínálat</p><h2 id="cat-title">Négy világ, egy hangulat</h2>
      </div><div class="iu-button-group"><a class="iu-button iu-button-outline" href="termekek.html">Teljes kínálat</a></div></div></div></div>
      <div class="iu-row">
        <div class="iu-column iu-column-2-3">
          <a class="cat-tile cat-tile-tall reveal" href="termekek.html?cat=szakralis-targyak">''' + pic('fustolok-csakra', 'Füstölők, csakra illatok, lótuszvirág és backflow füstölőtartó', '(max-width: 991px) 100vw, 66vw') + '''
            <div class="cat-body"><div><h3>Szakrális tárgyak</h3><p>Hangtálak, füstölők, mala láncok, csengők és imazászlók a csendesebb pillanatokhoz.</p></div><span class="count" data-count="szakralis-targyak"></span></div></a>
        </div>
        <div class="iu-column iu-column-1-3"><div class="iu-group iu-group-column" style="height:100%;gap:var(--gutter)">
          <a class="cat-tile cat-tile-half reveal" href="termekek.html?cat=lakberendezes">''' + pic('rez-sarkanyok', 'Réz sárkány- és főnixszobor bambusz alátéten', '(max-width: 991px) 100vw, 33vw') + '''
            <div class="cat-body"><div><h3>Lakberendezés</h3><p>Szobrok, szélcsengők, textilek.</p></div><span class="count" data-count="lakberendezes"></span></div></a>
          <a class="cat-tile cat-tile-half reveal" href="termekek.html?cat=ruhazat-es-kiegeszitok">''' + pic('ruhazat-to', 'Mintás indiai tunikát viselő nő a vízparton', '(max-width: 991px) 100vw, 33vw', style='object-position:50% 22%') + '''
            <div class="cat-body"><div><h3>Ruházat és ékszer</h3><p>Könnyű indiai textilek.</p></div><span class="count" data-count="ruhazat-es-kiegeszitok"></span></div></a>
        </div></div>
      </div>
      <div class="iu-row"><div class="iu-column iu-column-1-1">
        <a class="cat-tile cat-tile-wide reveal" href="termekek.html?cat=ajandektargyak" data-gift-tile>
          <div class="cat-body"><div><p class="eyebrow">Ajándéktárgyak</p><h3>Ajándék, ami jelent is valamit</h3><p>Réz kulacsok, teák és összeállított ajándékcsomagok – kérésre kézzel írt kártyával, díszdobozban.</p></div></div></a>
      </div></div>
    </section>

    <!-- iu-woocommerce/product-carousel: újdonságok -->
    <section class="iu-section" aria-labelledby="new-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="carousel" data-carousel>
        <div class="section-head"><div><p class="eyebrow">Frissen érkezett</p><h2 id="new-title">Újdonságok</h2></div><div class="iu-group iu-group-vertical-center" data-carousel-nav><a class="iu-button iu-button-link" href="termekek.html?orderby=date">Összes újdonság</a></div></div>
        <ul class="products carousel-track" data-new-products aria-label="Újdonságok"></ul>
        <div class="carousel-progress" aria-hidden="true"><span></span></div>
      </div></div></div>
    </section>

    <!-- iu/section: eredet (bgColor night + lightText, 1-2|1-2) -->
    <section class="iu-section iu-section-light origin-band" style="background-color:var(--wp--preset--color--night)" aria-labelledby="origin-title">
      <div class="iu-row iu-row-vertical-center">
        <div class="iu-column iu-column-1-2 reveal">
          <p class="eyebrow">Eredetünk</p>
          <h2 id="origin-title">Katmandutól Budapestig</h2>
          <p class="lead">Minden tárgyunknak van egy helye és egy keze, amely elkészítette. Nepál és India kis műhelyeivel dolgozunk – ahol a mesterség generációkon át öröklődik.</p>
          <div class="origin-facts">
            <div><h3><span class="origin origin-nepal" aria-hidden="true"></span>Nepál</h3><p>Hangtálak, csengők és tingsha Patan öntőműhelyeiből, kézzel sodort tibeti füstölők, mala láncok, imazászlók.</p></div>
            <div><h3><span class="origin origin-india" aria-hidden="true"></span>India</h3><p>Moradabad rézművessége, jaipuri blokknyomott textilek és ékszerek, dél-indiai kézzel sodort füstölők.</p></div>
          </div>
          <div class="iu-button-group"><a class="iu-button" href="rolunk.html">Ismerd meg az utat</a></div>
        </div>
        <div class="iu-column iu-column-1-2 reveal" data-route></div>
      </div>
    </section>

    <!-- iu/section: hangtál-kalauz (1-2|1-2) -->
    <section class="iu-section" aria-labelledby="bowl-title">
      <div class="iu-row iu-row-vertical-center">
        <div class="iu-column iu-column-1-2 reveal"><div class="media-frame">''' + pic('hangtalak-studio', 'Két nepáli hangtál párnán, filcütőkkel és acél nyelvdobbal', '(max-width: 991px) 100vw, 50vw') + '''</div></div>
        <div class="iu-column iu-column-1-2 reveal">
          <p class="eyebrow">Hangtál-kalauz</p>
          <h2 id="bowl-title">Minden tálnak saját hangja van</h2>
          <p class="lead">Hangtálainkat egyenként, meghallgatva választjuk ki, és minden tálnál megadjuk, amit egy gyakorló tudni szeretne – hogy ne a leírás, hanem a hang alapján dönthess.</p>
          <ul class="spec-grid">
            <li><strong>Hz</strong><span>Mért alapfrekvencia</span></li>
            <li><strong>G#, C…</strong><span>Zenei hang</span></li>
            <li><strong>Csakra</strong><span>Hagyományos megfeleltetés</span></li>
            <li><strong>Gramm</strong><span>Súly és ötvözet</span></li>
          </ul>
          <div class="iu-button-group"><a class="iu-button" href="termekek.html?cat=szakralis-targyak&amp;sub=hangtalak">Hangtálak</a><a class="iu-button iu-button-link" href="cikk.html?a=hangtal-valasztas">Hogyan válassz?</a></div>
        </div>
      </div>
    </section>

    <!-- mandala/product-selection (saját blokk): kedvenceink -->
    <section class="iu-section" style="background-color:var(--wp--preset--color--sand)" aria-labelledby="featured-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="section-head reveal"><div>
        <p class="eyebrow">A tudatos választás</p><h2 id="featured-title">Kedvenceink</h2><p>Darabok, amelyekhez mi magunk is újra és újra visszatérünk.</p>
      </div></div><ul class="products columns-4" data-featured-products></ul></div></div>
    </section>

    <!-- iu/section: filozófia (1-2|1-2) -->
    <section class="iu-section" aria-labelledby="calm-title">
      <div class="iu-row iu-row-vertical-center">
        <div class="iu-column iu-column-1-2 reveal"><div class="media-frame" style="aspect-ratio:4/3">''' + pic('meditacio-erdo', 'Csukott szemmel meditáló nő az erdőben', '(max-width: 991px) 100vw, 50vw', style='height:100%;object-fit:cover;object-position:40% 50%') + '''</div></div>
        <div class="iu-column iu-column-1-2 reveal">
          <p class="eyebrow">A Mandala világa</p>
          <h2 id="calm-title">Tárgyak, amelyek túlmutatnak a funkciójukon</h2>
          <p class="lead">Egy hangtál, egy füstölő vagy egy Buddha-szobor nem dísz, hanem emlékeztető: hogy megállj, figyelj, és jobban érezd magad a saját teredben.</p>
          <p>Ezért nem a „mindent egy helyen” elv szerint válogatunk. Csak azt hozzuk el, amit mi magunk is használnánk – és amiről el tudjuk mondani, honnan jön, ki készítette, és mire való.</p>
          <div class="iu-button-group"><a class="iu-button iu-button-outline" href="rolunk.html">Rólunk</a></div>
        </div>
      </div>
    </section>

    <!-- iu/section: vélemények (1-3 × 3) -->
    <section class="iu-section" style="background-color:var(--wp--preset--color--sand)" aria-labelledby="quotes-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="section-head reveal"><div><p class="eyebrow">Vásárlóink mondták</p><h2 id="quotes-title">Több, mint egy vásárlás</h2></div></div></div></div>
      <div class="iu-row" data-quotes></div>
    </section>

    <!-- iu/query: magazin (3 oszlop) -->
    <section class="iu-section" aria-labelledby="mag-title">
      <div class="iu-row"><div class="iu-column iu-column-1-1">
        <div class="section-head reveal"><div><p class="eyebrow">Magazin</p><h2 id="mag-title">Tudni, mit tartasz a kezedben</h2></div><div class="iu-button-group"><a class="iu-button iu-button-outline" href="magazin.html">Összes cikk</a></div></div>
        <div class="iu-query iu-query-col-3" data-posts></div>
      </div></div>
    </section>'''

SHOP = '''    <section class="iu-section page-head"><div class="iu-row"><div class="iu-column iu-column-1-1">
      <nav class="iu-breadcrumbs" aria-label="Morzsamenü"><ol><li><a href="index.html">Kezdőlap</a></li><li><a href="termekek.html">Kínálat</a></li><li><span aria-current="page" data-crumb>Teljes kínálat</span></li></ol></nav>
      <h1 class="iu-title" data-title>Teljes kínálat</h1>
      <p data-lead>Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.</p>
      <div class="chip-row" data-subnav style="margin-top:var(--space-5)"></div>
    </div></div></section>
    <!-- 1-4|3-4: iu-woocommerce/filter + [products class="mainquery"] -->
    <section class="iu-section" style="padding-top:var(--space-6)"><div class="iu-row">
      <div class="iu-column iu-column-1-4"><aside class="iu-woocommerce-filter" data-filters aria-label="Szűrők"></aside></div>
      <div class="iu-column iu-column-3-4">
        <div class="shop-toolbar">
          <button class="iu-button iu-button-outline filter-toggle" type="button" data-filters-open aria-controls="filters">Szűrők <span data-filter-count></span></button>
          <p class="woocommerce-result-count" data-count aria-live="polite"></p>
          <form class="woocommerce-ordering" onsubmit="return false"><label class="sr-only" for="orderby">Rendezés</label>
            <select name="orderby" id="orderby" class="orderby" data-sort>
              <option value="menu_order">Ajánlott sorrend</option><option value="date">Legújabb elöl</option><option value="popularity">Legnépszerűbb</option>
              <option value="price">Ár szerint növekvő</option><option value="price-desc">Ár szerint csökkenő</option>
            </select></form>
        </div>
        <div class="active-filters" data-chips></div>
        <ul class="products columns-3" data-results></ul>
        <div data-more></div>
      </div>
    </div></section>'''

CART = '''    <section class="iu-section page-head" style="border:0;padding-bottom:0"><div class="iu-row"><div class="iu-column iu-column-1-1">
      <ol class="checkout-progress" aria-label="Rendelés lépései"><li class="is-current" aria-current="step"><span class="step-dot">1</span>Kosár</li><li><span class="step-dot">2</span>Pénztár</li><li><span class="step-dot">3</span>Visszaigazolás</li></ol>
      <h1 class="iu-title" style="text-align:center">Kosár</h1>
    </div></div></section>
    <section class="iu-section" style="padding-top:var(--space-6)"><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="woocommerce" data-cart></div></div></div></section>'''

CHECKOUT = '''    <section class="iu-section" style="padding:var(--space-6) 0 var(--space-9)"><div class="iu-row"><div class="iu-column iu-column-1-1">
      <ol class="checkout-progress" aria-label="Rendelés lépései"><li class="is-done"><a href="kosar.html" style="display:flex;gap:8px;align-items:center;color:inherit"><span class="step-dot">✓</span>Kosár</a></li><li class="is-current" aria-current="step"><span class="step-dot">2</span>Pénztár</li><li><span class="step-dot">3</span>Visszaigazolás</li></ol>
      <h1 class="sr-only">Pénztár</h1>
      <div class="woocommerce" data-checkout></div>
    </div></div></section>'''

THANKS = '''    <section class="iu-section" style="padding:var(--space-6) 0 var(--space-9)"><div class="iu-row"><div class="iu-column iu-column-1-1">
      <ol class="checkout-progress" aria-label="Rendelés lépései"><li class="is-done"><span class="step-dot">✓</span>Kosár</li><li class="is-done"><span class="step-dot">✓</span>Pénztár</li><li class="is-current" aria-current="step"><span class="step-dot">3</span>Visszaigazolás</li></ol>
      <div class="woocommerce" data-thankyou></div>
    </div></div></section>'''

ABOUT = '''    <section class="iu-section iu-section-light page-hero">''' + pic('hangtalak-gyertyafeny', '', eager=True) + '''
      <div class="iu-row"><div class="iu-column iu-column-2-3">
        <nav class="iu-breadcrumbs" aria-label="Morzsamenü" style="color:var(--c-on-dark-muted)"><ol><li><a href="index.html" style="color:inherit">Kezdőlap</a></li><li><span aria-current="page" style="color:#fff">Eredetünk</span></li></ol></nav>
        <p class="eyebrow">Eredetünk</p>
        <h1 class="iu-title">Minden tárgynak van egy helye és egy keze</h1>
        <p class="lead">A Mandala tárgyai Nepál és India műhelyeiből érkeznek. Nem nagykereskedelmi katalógusból válogatunk, hanem onnan, ahol ezek a tárgyak ma is a mindennapok részei.</p>
      </div></div>
    </section>
    <section class="iu-section"><div class="iu-row iu-row-vertical-center">
      <div class="iu-column iu-column-1-2 reveal"><p class="eyebrow">Nepál</p><h2>A Katmandu-völgy műhelyei</h2>
        <p class="lead">Patan évszázadok óta a fémöntés és a szoborkészítés központja. Innen érkeznek hangtálaink, csengőink, tingsháink és réz szobraink.</p>
        <p>A tibeti közösségek kézzel sodort, pálca nélküli füstölői, a mala láncok és az imazászlók szintén nepáli kézművesek munkái. A hangtálakat egyenként meghallgatjuk, és megmérjük az alapfrekvenciájukat, mielőtt kiválasztjuk őket.</p></div>
      <div class="iu-column iu-column-1-2 reveal"><div class="media-frame">''' + pic('hangtalak-studio', 'Nepáli hangtálak ütőkkel', '(max-width: 991px) 100vw, 50vw') + '''</div></div>
    </div></section>
    <section class="iu-section" style="background-color:var(--wp--preset--color--sand)"><div class="iu-row iu-row-vertical-center">
      <div class="iu-column iu-column-1-2 reveal"><div class="media-frame" style="aspect-ratio:4/5">''' + pic('ruhazat-to', 'Mintás indiai tunikát viselő nő', '(max-width: 991px) 100vw, 50vw', style='height:100%;object-fit:cover') + '''</div></div>
      <div class="iu-column iu-column-1-2 reveal"><p class="eyebrow">India</p><h2>Réz, textil és illat</h2>
        <p class="lead">Moradabad a „réz városa”: szélcsengőink, mécsestartóink, kulacsaink és füstölőtartóink innen jönnek.</p>
        <p>Rádzsasztán fővárosa, Jaipur a blokknyomott textilek és a kézműves ékszerek otthona – sálaink, ruháink és gyűrűink nagy része itt készül. Kézzel sodort füstölőinket dél-indiai családi manufaktúrák készítik.</p></div>
    </div></section>
    <section class="iu-section">
      <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="section-head reveal"><div><p class="eyebrow">Így dolgozunk</p><h2>Az út a műhelytől hozzád</h2></div></div></div></div>
      <ol class="iu-row steps">
        <li class="iu-column iu-column-1-4 reveal"><h3>Kapcsolat</h3><p>Hosszú távú kapcsolatot építünk kisebb műhelyekkel – ismerjük a kezeket, amelyek a tárgyakat készítik.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Válogatás</h3><p>Egyenként választunk: a hangtálat meghallgatjuk, a textilt megfogjuk, a füstölőt meggyújtjuk.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Ellenőrzés</h3><p>Budapesten minden darabot átnézünk, lemérünk, és pontos adatokkal töltünk fel.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Csomagolás</h3><p>Gondosan, lehetőség szerint újrahasznosított anyagokkal csomagolunk – ajándékba kézzel írt kártyával.</p></li>
      </ol>
    </section>
    <section class="iu-section iu-section-light" style="background-color:var(--wp--preset--color--night)"><div class="iu-row">
      <div class="iu-column iu-column-1-3 reveal"><h3>Tisztelet</h3><p>A szakrális tárgyakat jelentésükkel együtt adjuk tovább – ezért írjuk a magazint.</p></div>
      <div class="iu-column iu-column-1-3 reveal"><h3>Átláthatóság</h3><p>Minden terméknél megadjuk, honnan érkezik, miből készült, és hogyan érdemes használni.</p></div>
      <div class="iu-column iu-column-1-3 reveal"><h3>Személyesség</h3><p>Kérdezz bátran: segítünk kiválasztani a hozzád illő hangtálat vagy ajándékot.</p></div>
    </div>
    <div class="iu-row"><div class="iu-column iu-column-1-1"><div class="iu-button-group"><a class="iu-button" href="termekek.html">A kínálat felfedezése</a><a class="iu-button iu-button-outline" href="kapcsolat.html">Írj nekünk</a></div></div></div></section>'''

B2B = '''    <section class="iu-section iu-section-light page-hero">''' + pic('fustolok-csakra', '', eager=True) + '''
      <div class="iu-row"><div class="iu-column iu-column-2-3">
        <p class="eyebrow">Viszonteladóknak</p>
        <h1 class="iu-title">Közvetlen import, nagykereskedelmi áron</h1>
        <p class="lead">Jógastúdióknak, ajándék- és lakberendezési üzleteknek, masszőröknek és hangterapeutáknak. Jóváhagyás után a webshopban a viszonteladói árakat látod, és a megszokott kosárral rendelhetsz.</p>
      </div></div>
    </section>
    <section class="iu-section">
      <ol class="iu-row steps">
        <li class="iu-column iu-column-1-4 reveal"><h3>Jelentkezés</h3><p>Töltsd ki a háromlépéses űrlapot a céges adataiddal.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Jóváhagyás</h3><p>1–2 munkanapon belül visszajelzünk, és aktiváljuk a viszonteladói fiókodat.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Rendelés</h3><p>Belépés után minden terméknél a nagykereskedelmi ár látszik; ÁFÁ-s számlát állítunk ki.</p></li>
        <li class="iu-column iu-column-1-4 reveal"><h3>Utánrendelés</h3><p>Új érkezésekről elsőként értesítünk, a keresett tételeket félre is tesszük.</p></li>
      </ol>
    </section>
    <section class="iu-section" style="background-color:var(--wp--preset--color--sand)"><div class="iu-row">
      <div class="iu-column iu-column-1-3"><p class="eyebrow">Jelentkezés</p><h2>Legyünk partnerek</h2>
        <p class="lead">A minimális rendelési érték és a kedvezménysávok a jóváhagyás után, a fiókodban jelennek meg.</p>
        <ul class="product-assurance" data-b2b-perks></ul></div>
      <div class="iu-column iu-column-2-3"><div class="panel" data-b2b-form></div></div>
    </div></section>'''

CONTACT = head('Kapcsolat', 'Írj nekünk', 'Kérdésed van egy termékről, vagy nem tudod, melyik hangtál illik hozzád? Általában egy munkanapon belül válaszolunk.', [('Kezdőlap', 'index.html'), ('Kapcsolat', None)]) + '''
    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row">
      <div class="iu-column iu-column-1-3"><div class="iu-group iu-group-column" style="gap:var(--space-6)">
        <ul class="contact-list product-assurance" data-contact style="font-size:1rem"></ul>
        <div class="map-placeholder" data-map></div>
      </div></div>
      <div class="iu-column iu-column-2-3"><div class="panel" data-contact-form></div></div>
    </div></section>'''

INFO = head('Vásárlási információk', 'Szállítás, fizetés, visszaküldés', 'Minden, amit a rendelésről tudni érdemes – röviden.', [('Kezdőlap', 'index.html'), ('Vásárlási információk', None)]) + '''
    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row">
      <div class="iu-column iu-column-1-4"><nav class="toc" aria-label="Tartalom"><ol>
        <li><a href="#szallitas">Szállítás és átvétel</a></li><li><a href="#fizetes">Fizetési módok</a></li><li><a href="#visszakuldes">Visszaküldés, elállás</a></li><li><a href="#gyik">Gyakori kérdések</a></li></ol></nav></div>
      <div class="iu-column iu-column-3-4" data-info></div>
    </div></section>'''

PAGES = {
    'index': dict(title='Mandala – Hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából', desc='Kézzel válogatott hangtálak, füstölők, mala láncok, Buddha szobrok, textilek és ajándékok Nepál és India műhelyeiből. Ingyenes szállítás 25 000 Ft felett.', script='home', body='home front-page', main=HOME),
    'termekek': dict(title='Kínálat – Mandala', desc='Hangtálak, füstölők, mala láncok, szobrok, textilek és ajándékok – szűrhető kategória, szándék, eredet és ár szerint.', script='shop', body='archive woocommerce-shop', main=SHOP),
    'termek': dict(title='Termék – Mandala', desc='Mandala termék', script='product', body='single-product', main='    <div data-product></div>'),
    'kosar': dict(title='Kosár – Mandala', desc='A kosarad tartalma.', script='cart', body='woocommerce-cart', main=CART, robots='<meta name="robots" content="noindex">\n  '),
    'penztar': dict(title='Pénztár – Mandala', desc='Rendelés leadása.', script='checkout', body='woocommerce-checkout-page', main=CHECKOUT, robots='<meta name="robots" content="noindex">\n  '),
    'koszonjuk': dict(title='Köszönjük a rendelést – Mandala', desc='Rendelés visszaigazolása.', script='thankyou', body='woocommerce-order-received', main=THANKS, robots='<meta name="robots" content="noindex">\n  '),
    'fiok': dict(title='Fiókom – Mandala', desc='Belépés vagy regisztráció.', script='account', body='woocommerce-account', main=head('', 'Fiókom', 'Lépj be a rendeléseid követéséhez, vagy hozz létre fiókot a gyorsabb vásárláshoz.', [('Kezdőlap', 'index.html'), ('Fiókom', None)]) + '\n    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row"><div class="iu-column iu-column-1-1" data-account></div></div></section>', robots='<meta name="robots" content="noindex">\n  '),
    'kedvencek': dict(title='Kedvencek – Mandala', desc='A kedvencnek jelölt termékeid.', script='wishlist', body='wishlist', main=head('', 'Kedvencek', 'A szívvel jelölt termékeid. A lista ezen az eszközön marad meg.', [('Kezdőlap', 'index.html'), ('Kedvencek', None)]) + '\n    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row"><div class="iu-column iu-column-1-1" data-wishlist></div></div></section>'),
    'kereses': dict(title='Keresés – Mandala', desc='Keresés a termékek és cikkek között.', script='search', body='search', main=head('', '<span data-search-title>Keresés</span>', '', [('Kezdőlap', 'index.html'), ('Keresés', None)], '<form class="search-form" action="kereses.html" role="search" style="margin-top:var(--space-5);max-width:720px"><label class="sr-only" for="s">Keresőkifejezés</label><input id="s" name="s" type="search" placeholder="Mit keresel?"><button class="iu-button" type="submit">Keresés</button></form>') + '\n    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row"><div class="iu-column iu-column-1-1" data-search></div></div></section>'),
    '404': dict(title='Az oldal nem található – Mandala', desc='A keresett oldal nem található.', script='simple', body='error404', robots='<meta name="robots" content="noindex">\n  ', main='''    <section class="iu-section"><div class="iu-row iu-row-vertical-center">
      <div class="iu-column iu-column-1-2"><p class="error-code" aria-hidden="true">404</p></div>
      <div class="iu-column iu-column-1-2"><p class="eyebrow">Hiba 404</p><h1 class="iu-title">Ez az oldal elcsendesedett</h1>
        <p class="lead">Lehet, hogy elköltözött, vagy elgépelődött a cím. Keress rá arra, amit szerettél volna, vagy induljunk újra a kezdőlapról.</p>
        <form class="search-form" action="kereses.html" role="search" style="margin:var(--space-5) 0"><label class="sr-only" for="s404">Keresés</label><input id="s404" name="s" type="search" placeholder="Hangtál, füstölő, mala…"><button class="iu-button" type="submit">Keresés</button></form>
        <div class="iu-button-group"><a class="iu-button iu-button-outline" href="index.html">Kezdőlap</a><a class="iu-button iu-button-link" href="termekek.html">Teljes kínálat</a><a class="iu-button iu-button-link" href="kapcsolat.html">Kapcsolat</a></div></div>
    </div></section>'''),
    'magazin': dict(title='Magazin – Mandala', desc='Cikkek a hangtálakról, a buddhista oltárról, a szimbólumokról és a tárgyak jelentéséről.', script='blog', body='blog', main=head('Magazin', 'Tudni, mit tartasz a kezedben', 'Hetente új cikk arról, honnan jön, mit jelent, és hogyan használják a hagyományban azt, amit a kezedben tartasz.', [('Kezdőlap', 'index.html'), ('Magazin', None)]) + '\n    <section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row"><div class="iu-column iu-column-1-1"><div class="iu-terms" data-terms role="group" aria-label="Kategória szűrő"></div><div class="iu-query iu-query-col-3" data-posts></div><nav class="pagination" data-paging aria-label="Lapozás"></nav></div></div></section>'),
    'cikk': dict(title='Magazin – Mandala', desc='Mandala magazin', script='post', body='single-post', main='    <div data-post></div>'),
    'rolunk': dict(title='Eredetünk – Mandala', desc='Honnan érkeznek a Mandala tárgyai? Nepál és India kis műhelyeitől Budapestig – így válogatunk.', script='simple', body='page', main=ABOUT),
    'viszonteladoknak': dict(title='Viszonteladóknak – Mandala', desc='Nagykereskedelmi együttműködés jógastúdióknak, ajándékboltoknak és terapeutáknak – közvetlen import Nepálból és Indiából.', script='b2b', body='page', main=B2B),
    'kapcsolat': dict(title='Kapcsolat – Mandala', desc='Írj nekünk: segítünk kiválasztani a hozzád illő hangtálat, szobrot vagy ajándékot.', script='contact', body='page', main=CONTACT),
    'informaciok': dict(title='Vásárlási információk – Mandala', desc='Szállítás, fizetés, visszaküldés és gyakori kérdések.', script='info', body='page', main=INFO),
    'jogi': dict(title='Jogi információk – Mandala', desc='ÁSZF, adatkezelési tájékoztató és impresszum.', script='legal', body='page legal', main='    <div data-legal></div>'),
    'stilus': dict(title='Design system – Mandala', desc='A Mandala design system: tokenek, tipográfia, komponensek és állapotok.', script='styleguide', body='styleguide', main='    <div data-styleguide></div>', robots='<meta name="robots" content="noindex">\n  '),
}

ACTIVE = {'termekek': 'shop', 'termek': 'shop', 'rolunk': 'about', 'magazin': 'mag', 'cikk': 'mag', 'viszonteladoknak': 'b2b', 'fiok': 'account'}

if __name__ == '__main__':
    for name, p in PAGES.items():
        html = HEAD.format(title=p['title'], desc=p['desc'], main=p['main'], script=p['script'], body=p['body'], robots=p.get('robots', ''))
        html = html.replace('<body class="', f'<body data-active="{ACTIVE.get(name, "")}" class="', 1)
        (ROOT / f'{name}.html').write_text(html)
    print(f'{len(PAGES)} oldal legenerálva')
