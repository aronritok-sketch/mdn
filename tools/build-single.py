#!/usr/bin/env python3
"""Az egész oldalt egyetlen, önállóan megnyitható HTML fájlba csomagolja.

    python3 tools/build-single.py  →  dist/mandala-elonezet.html

A kimenet dupla kattintással megnyitható (file://) és e-mailben elküldhető:
a CSS, a JavaScript és a képek (base64) is benne vannak. Az oldalak közti
navigáció hash-alapú (#termekek.html?cat=...), a betűtípusok a Google Fonts-ról
töltődnek (internet nélkül rendszerbetűre váltanak).
"""
import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'dist' / 'mandala-elonezet.html'
PAGES = ['index', 'termekek', 'termek', 'kosar', 'rolunk', 'viszonteladoknak',
         'magazin', 'cikk', 'kapcsolat', 'informaciok']
PAGE_RE = '(?:' + '|'.join(sorted(PAGES, key=len, reverse=True)) + r')\.html'
# Modulok függőségi sorrendben.
MODULES = ['data', 'art', 'store', 'app', 'blocks']


def must_replace(src, old, new, name):
    if old not in src:
        raise SystemExit(f'Nem található a cserélendő részlet ({name}): {old[:60]}')
    return src.replace(old, new)


def images():
    """Képenként a legnagyobb méretű változat, data URI-ként."""
    best = {}
    for f in (ROOT / 'assets/img').glob('*.webp'):
        name, size = f.stem.rsplit('-', 1)
        if name not in best or int(size) > best[name][0]:
            best[name] = (int(size), f)
    return {n: 'data:image/webp;base64,' + base64.b64encode(f.read_bytes()).decode()
            for n, (_, f) in sorted(best.items())}


def route_links(s):
    """termekek.html?x → #termekek.html?x (idézőjeles és backtick-es szövegekben)."""
    return re.sub(r'(["\'`])(' + PAGE_RE + ')', r'\1#\2', s)


def to_factory(name, src):
    """ES modul → függvény, amely a saját exports objektumát tölti fel."""
    def imp(m):
        names = m.group(1).replace(' as ', ': ')
        dep = Path(m.group(2)).stem
        return f'const {{{names}}} = __req({json.dumps(dep)});'

    src = re.sub(r"^import\s*\{([^}]*)\}\s*from\s*'([^']+)';", imp, src, flags=re.M)
    exported = re.findall(r'^export\s+(?:async\s+)?(?:function|const|let)\s+([\w$]+)', src, flags=re.M)
    for group in re.findall(r'^export\s*\{([^}]*)\};?', src, flags=re.M):
        exported += [n.strip() for n in group.split(',') if n.strip()]
    src = re.sub(r'^export\s*\{[^}]*\};?\s*$', '', src, flags=re.M)
    src = re.sub(r'^export\s+', '', src, flags=re.M)
    assign = '\n'.join(f'__exports[{json.dumps(n)}] = {n};' for n in exported)
    return f'__def({json.dumps(name)}, async (__req, __exports) => {{\n{src}\n{assign}\n}});'


def js_source(path):
    src = path.read_text()
    name = path.stem
    if name == 'app':
        src = must_replace(src, 'new URLSearchParams(location.search)', '__route.params()', name)
        src = must_replace(
            src,
            'src="assets/img/${name}-800.webp" srcset="assets/img/${name}-800.webp 800w, assets/img/${name}-${big}.webp ${big}w" sizes="${sizes}"',
            'src="${__IMG[name]}"', name)
        src = must_replace(src, 'action="termekek.html"', 'data-route-search', name)
    if name == 'store':
        src = src.replace('localStorage.', '__storage.')
    if name == 'shop':
        src = must_replace(
            src,
            "history.replaceState(null, '', `${location.pathname}${u.toString() ? `?${u}` : ''}`);",
            "__route.replace('termekek', u.toString());", name)
    if name == 'checkout':
        src = must_replace(src, "location.hash === '#penztar'", "__route.anchor === 'penztar'", name)
    return route_links(src)


def page_parts(name):
    html = (ROOT / f'{name}.html').read_text()
    title = re.search(r'<title>(.*?)</title>', html, re.S).group(1)
    desc = re.search(r'<meta name="description" content="([^"]*)"', html).group(1)
    active = re.search(r'<body[^>]*data-active="([^"]*)"', html)
    main = re.search(r'<main id="main">(.*)</main>', html, re.S).group(1)
    script = re.search(r'assets/js/pages/(\w+)\.js', html).group(1)
    main = re.sub(r'\s(?:srcset|sizes)="[^"]*"', '', main)
    main = re.sub(r'assets/img/([a-z-]+?)-\d+\.webp', r'{{img:\1}}', main)
    main = route_links(main)
    return {'title': title, 'desc': desc, 'active': active.group(1) if active else '', 'script': script, 'main': main}


# Futásidejű segédek: modulbetöltő, hash-router, tárolás file:// alatt is.
RUNTIME = r'''
const __mods = {}, __cache = {};
function __def(name, fn) { __mods[name] = fn; }
async function __load(name) {
  if (!__cache[name]) { __cache[name] = {}; await __mods[name]((n) => __cache[n], __cache[name]); }
  return __cache[name];
}

// A localStorage file:// alatt vagy privát módban hiányozhat: ilyenkor window.name őrzi a kosarat újratöltésen át.
const __storage = (() => {
  try { localStorage.setItem('__t', '1'); localStorage.removeItem('__t'); return localStorage; } catch {}
  let data = {};
  try { data = JSON.parse(window.name || '{}'); } catch {}
  const save = () => { window.name = JSON.stringify(data); };
  return { getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v); save(); }, removeItem: (k) => { delete data[k]; save(); } };
})();

const __route = (() => {
  const raw = location.hash.slice(1);
  const m = raw.match(/^([a-z]+)\.html(\?[^#]*)?(?:#(.*))?$/);
  const page = m && __PAGES[m[1]] ? m[1] : 'index';
  return {
    page,
    anchor: m ? m[3] || '' : raw,
    params: () => new URLSearchParams(m && m[2] ? m[2] : ''),
    replace: (page, query) => history.replaceState(null, '', `#${page}.html${query ? `?${query}` : ''}`),
  };
})();
'''

BOOT = r'''
(async () => {
  const p = __PAGES[__route.page];
  document.title = p.title;
  document.querySelector('meta[name="description"]').setAttribute('content', p.desc);
  if (p.active) document.body.dataset.active = p.active;
  document.body.innerHTML = `<header id="site-header"></header><main id="main">${p.main.replace(/\{\{img:([\w-]+)\}\}/g, (_, n) => __IMG[n] || '')}</main><footer id="site-footer"></footer>`;
  for (const m of __ORDER) await __load(m);
  await __mods['page:' + p.script]((n) => __cache[n], {});
  if (__route.anchor) document.getElementById(__route.anchor)?.scrollIntoView();
})();

// Másik oldalra mutató hash: újratöltés, mintha külön oldal lenne.
addEventListener('hashchange', () => {
  if (/^#[a-z]+\.html/.test(location.hash)) { location.reload(); scrollTo(0, 0); }
});
// Oldalon belüli horgony (#szallitas, #main): görgetés, a route megtartásával.
document.addEventListener('click', (e) => {
  const a = e.target.closest('a[href^="#"]');
  if (!a || /^#[a-z]+\.html/.test(a.getAttribute('href'))) return;
  const target = document.getElementById(a.getAttribute('href').slice(1));
  if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); target.focus?.({ preventScroll: true }); }
});
// A fejléc keresője a kínálat oldalra visz.
document.addEventListener('submit', (e) => {
  if (!e.target.matches('[data-route-search]')) return;
  e.preventDefault();
  location.hash = `#termekek.html?q=${encodeURIComponent(e.target.q.value.trim())}`;
});
'''


def build():
    pages = {n: page_parts(n) for n in PAGES}
    mods = [to_factory(m, js_source(ROOT / f'assets/js/{m}.js')) for m in MODULES]
    page_scripts = sorted({p['script'] for p in pages.values()})
    for s in page_scripts:
        src = to_factory('page:' + s, js_source(ROOT / f'assets/js/pages/{s}.js'))
        mods.append(src)
    js = '\n'.join([
        'const __PAGES = ' + json.dumps(pages, ensure_ascii=False) + ';',
        'const __IMG = ' + json.dumps(images()) + ';',
        'const __ORDER = ' + json.dumps(MODULES) + ';',
        RUNTIME, *mods, BOOT,
    ])
    # A beágyazott szkriptben nem szerepelhet lezáró </script>.
    js = js.replace('</script', '<\\/script')

    css = (ROOT / 'assets/css/styles.css').read_text()
    favicon = 'data:image/svg+xml;base64,' + base64.b64encode((ROOT / 'assets/favicon.svg').read_bytes()).decode()
    html = f'''<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mandala</title>
  <meta name="description" content="">
  <meta name="theme-color" content="#1A1512">
  <link rel="icon" href="{favicon}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@400;500;600&display=swap">
  <style>
{css}
  </style>
</head>
<body>
  <noscript><p style="padding:2rem;font-family:sans-serif">Az előnézethez engedélyezd a JavaScriptet.</p></noscript>
  <script>
{js}
  </script>
</body>
</html>
'''
    OUT.parent.mkdir(exist_ok=True)
    OUT.write_text(html)
    print(f'{OUT.relative_to(ROOT)}  {OUT.stat().st_size / 1024:.0f} KB')


if __name__ == '__main__':
    build()
