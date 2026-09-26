#!/usr/bin/env python3
"""Az egész prototípust egyetlen, önállóan megnyitható HTML fájlba csomagolja.

    python3 tools/pages.py          # előbb az oldalak (ha a forrás változott)
    python3 tools/build-single.py   # → dist/mandala-elonezet.html

A kimenet dupla kattintással megnyitható (file://) és e-mailben elküldhető:
a CSS, a JavaScript és a képek (base64) is benne vannak. Az oldalak közti
navigáció hash-alapú (#termekek.html?cat=...). A betűtípusok a Google Fonts-ról
töltődnek (internet nélkül rendszerbetűre váltanak).
"""
import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'dist' / 'mandala-elonezet.html'
PAGES = ['index', 'termekek', 'termek', 'kosar', 'penztar', 'koszonjuk', 'fiok', 'kedvencek', 'kereses', '404',
         'magazin', 'cikk', 'rolunk', 'viszonteladoknak', 'kapcsolat', 'informaciok', 'jogi', 'stilus']
PAGE_RE = '(?:' + '|'.join(sorted(PAGES, key=len, reverse=True)) + r')\.html'
MODULES = ['data', 'icons', 'art', 'store', 'ui', 'blocks']   # függőségi sorrend
CSS = ['vars', 'iu', 'site', 'shop']


def must_replace(src, old, new, name):
    if old not in src:
        raise SystemExit(f'Nem található a cserélendő részlet ({name}): {old[:70]}')
    return src.replace(old, new)


def images():
    """Képenként a legnagyobb változat, data URI-ként."""
    best = {}
    for f in (ROOT / 'assets/img').glob('*.webp'):
        name, size = f.stem.rsplit('-', 1)
        if name not in best or int(size) > best[name][0]:
            best[name] = (int(size), f)
    return {n: 'data:image/webp;base64,' + base64.b64encode(f.read_bytes()).decode() for n, (_, f) in sorted(best.items())}


def route_links(s):
    """termekek.html?x → #termekek.html?x (idézőjeles és backtick-es szövegekben)."""
    return re.sub(r'(["\'`])(' + PAGE_RE + ')', r'\1#\2', s)


def to_factory(name, src):
    """ES modul → async függvény, amely a saját exports objektumát tölti fel."""
    def imp(m):
        names = m.group(1).replace(' as ', ': ')
        return f'const {{{names}}} = __req({json.dumps(Path(m.group(2)).stem)});'
    src = re.sub(r"^import\s*\{([^}]*)\}\s*from\s*'([^']+)';", imp, src, flags=re.M)
    if re.search(r'^\s*import\s', src, flags=re.M):
        raise SystemExit(f'Nem kezelt import forma: {name}')
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
    if name == 'ui':
        src = must_replace(src, 'new URLSearchParams(location.search)', '__route.params()', name)
    if name == 'store':
        src = src.replace('localStorage.', '__storage.')
    # Az URL-szinkron (szűrők, lapozás) a hash-be írjon, ne a fájl útvonalába.
    src = src.replace('${location.pathname}', '${__route.path()}')
    return route_links(src)


def page_parts(name):
    html = (ROOT / f'{name}.html').read_text()
    title = re.search(r'<title>(.*?)</title>', html, re.S).group(1)
    desc = re.search(r'<meta name="description" content="([^"]*)"', html).group(1)
    body = re.search(r'<body data-active="([^"]*)" class="([^"]*)"', html)
    main = re.search(r'<main id="main" tabindex="-1">(.*)</main>', html, re.S).group(1)
    script = re.search(r'assets/js/pages/(\w+)\.js', html).group(1)
    main = re.sub(r'\s(?:srcset|sizes)="[^"]*"', '', main)
    main = re.sub(r'assets/img/([a-z-]+?)-\d+\.webp', r'{{img:\1}}', main)
    return {'title': title, 'desc': desc, 'active': body.group(1), 'cls': body.group(2), 'script': script, 'main': route_links(main)}


RUNTIME = r'''
const __mods = {}, __cache = {};
function __def(name, fn) { __mods[name] = fn; }
async function __load(name) {
  if (!__cache[name]) { __cache[name] = {}; await __mods[name]((n) => __cache[n], __cache[name]); }
  return __cache[name];
}
// file:// alatt vagy privát módban a localStorage hiányozhat: ilyenkor window.name őrzi az adatokat újratöltésen át.
const __storage = (() => {
  try { localStorage.setItem('__t', '1'); localStorage.removeItem('__t'); return localStorage; } catch {}
  let data = {};
  try { data = JSON.parse(window.name || '{}'); } catch {}
  const save = () => { window.name = JSON.stringify(data); };
  return { getItem: (k) => (k in data ? data[k] : null), setItem: (k, v) => { data[k] = String(v); save(); }, removeItem: (k) => { delete data[k]; save(); } };
})();
const __route = (() => {
  const raw = location.hash.slice(1);
  const m = raw.match(/^([a-z0-9]+)\.html(\?[^#]*)?(?:#(.*))?$/);
  const page = m ? (__PAGES[m[1]] ? m[1] : '404') : 'index';
  return {
    page,
    anchor: m ? m[3] || '' : raw,
    params: () => new URLSearchParams(m && m[2] ? m[2] : ''),
    path: () => `#${page}.html`,
  };
})();
'''

BOOT = r'''
(async () => {
  const p = __PAGES[__route.page];
  document.title = p.title;
  document.querySelector('meta[name="description"]').setAttribute('content', p.desc);
  document.body.className = p.cls;
  document.body.dataset.active = p.active;
  document.body.innerHTML = `<header id="site-header"></header><main id="main" tabindex="-1">${p.main.replace(/\{\{img:([\w-]+)\}\}/g, (_, n) => window.__IMG[n] || '')}</main><footer id="site-footer"></footer>`;
  for (const m of __ORDER) await __load(m);
  await __mods['page:' + p.script]((n) => __cache[n], {});
  if (__route.anchor) setTimeout(() => document.getElementById(__route.anchor)?.scrollIntoView(), 50);
})();
// Másik oldalra mutató hash: újratöltés, mintha külön oldal lenne.
addEventListener('hashchange', () => { if (/^#[a-z0-9]+\.html/.test(location.hash)) { scrollTo(0, 0); location.reload(); } });
// Oldalon belüli horgony (#szallitas, #main): görgetés, a route megtartásával.
document.addEventListener('click', (e) => {
  const a = e.target.closest('a[href^="#"]');
  if (!a || e.defaultPrevented || /^#[a-z0-9]+\.html/.test(a.getAttribute('href'))) return;
  const target = document.getElementById(a.getAttribute('href').slice(1));
  if (target) { e.preventDefault(); target.scrollIntoView({ behavior: 'smooth' }); target.focus?.({ preventScroll: true }); }
});
// GET űrlapok (kereső): a paraméterek a hash-be kerülnek.
document.addEventListener('submit', (e) => {
  const action = e.target.getAttribute('action') || '';
  const m = action.match(/^#?([a-z0-9]+\.html)$/);
  if (!m) return;
  e.preventDefault();
  location.hash = `#${m[1]}?${new URLSearchParams(new FormData(e.target))}`;
});
'''


def build():
    pages = {n: page_parts(n) for n in PAGES}
    mods = [to_factory(m, js_source(ROOT / f'assets/js/{m}.js')) for m in MODULES]
    for s in sorted({p['script'] for p in pages.values()}):
        mods.append(to_factory('page:' + s, js_source(ROOT / f'assets/js/pages/{s}.js')))
    js = '\n'.join([
        'const __PAGES = ' + json.dumps(pages, ensure_ascii=False) + ';',
        'window.__IMG = ' + json.dumps(images()) + ';',
        'const __ORDER = ' + json.dumps(MODULES) + ';',
        RUNTIME, *mods, BOOT,
    ]).replace('</script', '<\\/script')
    css = '\n'.join((ROOT / f'assets/css/{c}.css').read_text() for c in CSS)
    favicon = 'data:image/svg+xml;base64,' + base64.b64encode((ROOT / 'assets/favicon.svg').read_bytes()).decode()
    html = f'''<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Mandala</title>
  <meta name="description" content="">
  <meta name="theme-color" content="#16120F">
  <link rel="icon" href="{favicon}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap">
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
