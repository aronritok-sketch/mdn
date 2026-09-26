#!/usr/bin/env python3
"""A Mandala child téma (wp-theme/mandala) összeállítása a prototípusból.

    python3 tools/build-theme.py             # CSS, JS, adatok, képek + dist/mandala-tema.zip
    python3 tools/build-theme.py --content   # a blokk-markup (templates/, setup/content/) újragenerálása is

Mit csinál:
  1. CSS: vars.css, style.css (téma fejléc + site.css + wp.css), assets/css/shop.css
  2. Adatok és képek: setup/data/*.json, assets/art/*.svg, assets/img/*.webp (tools/export-data.mjs)
  3. JS: a szűrő motorja (facets.js) és felülete (filter.js) a prototípusból, WordPress
     adatforrásra (env.js) átírva
  4. Blokk-markup: templates/*.html és setup/content/*.html egy generátorból.
     FIGYELEM (iu-theme skill): a statikus iu blokkok (section, row, column, group,
     button, form…) HTML-je vázlat; élesítés előtt a valódi szerkesztőben kanonizálni
     kell (dev/canon.mjs), amíg a szerkesztő „invalid”-ot jelez.
  5. dist/mandala-tema.zip
"""
import json
import re
import shutil
import subprocess
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
THEME = ROOT / 'wp-theme' / 'mandala'
DIST = ROOT / 'dist' / 'mandala-tema.zip'
VERSION = '1.0.0'

HEADER = f'''/*
Theme Name: Mandala
Theme URI: https://mandala.hu
Description: A mandala.hu webáruház child témája az Infinite Unity (iu_theme) keretrendszerre: saját blokkok (termékszűrő, válogatások, fejléc, termékoldal), klasszikus 5 lépéses WooCommerce pénztár, magyar mezők és adószám-ellenőrzés, kedvencek, űrlapok, telepítő.
Author: Mandala
Template: iu_theme
Version: {VERSION}
Requires at least: 6.5
Requires PHP: 8.1
Text Domain: mandala
License: Proprietary
*/

'''


def must_replace(src, old, new, name):
    if old not in src:
        raise SystemExit(f'Nem található a cserélendő részlet ({name}): {old[:80]}')
    return src.replace(old, new)


# ---------------------------------------------------------------------------
# 1–3. Eszközök
# ---------------------------------------------------------------------------

def build_assets():
    css = ROOT / 'assets/css'
    (THEME / 'assets/css').mkdir(parents=True, exist_ok=True)
    shutil.copy(css / 'vars.css', THEME / 'vars.css')
    wp_css = (THEME / 'src/wp.css').read_text()
    (THEME / 'style.css').write_text(HEADER + (css / 'site.css').read_text() + '\n\n' + wp_css)
    shutil.copy(css / 'shop.css', THEME / 'assets/css/shop.css')
    shutil.copy(ROOT / 'theme/theme.json', THEME / 'theme.json')
    img = THEME / 'assets/img'
    if img.exists():
        shutil.rmtree(img)
    shutil.copytree(ROOT / 'assets/img', img)
    shutil.copy(ROOT / 'assets/favicon.svg', THEME / 'assets/favicon.svg')
    subprocess.run(['node', str(ROOT / 'tools/export-data.mjs'), str(THEME)], check=True)


def build_js():
    js = THEME / 'assets/js'
    js.mkdir(parents=True, exist_ok=True)
    note = '// GENERÁLT FÁJL – forrás: {src} (tools/build-theme.py). Ne szerkeszd közvetlenül.\n'
    facets = (ROOT / 'assets/js/facets.js').read_text()
    facets = must_replace(facets, "import { INTENTS } from './data.js';\nimport { norm } from './store.js';", "import { INTENTS, norm, COLORS as SITE_COLORS } from './env.js';", 'facets')
    # A színkódokat az adminban (pa_szin kifejezés meta) is meg lehet adni.
    facets = must_replace(facets, "  többszínű: 'conic-gradient(#B03A2E, #D8A106, #3E8E4F, #3A78B5, #8E4FA8, #B03A2E)',\n};",
                          "  többszínű: 'conic-gradient(#B03A2E, #D8A106, #3E8E4F, #3A78B5, #8E4FA8, #B03A2E)',\n  ...SITE_COLORS,\n};", 'facets colors')
    (js / 'facets.js').write_text(note.format(src='assets/js/facets.js') + facets)

    shop = (ROOT / 'assets/js/pages/shop.js').read_text()
    shop = must_replace(shop, "import { initPage, productCard, $, $$, esc, icon, params, setMeta, refreshReveal } from '../ui.js';\nimport { CATEGORIES, INTENTS } from '../data.js';\nimport { categoryBySlug, fmt, fmtNum, loadProducts, subLabel, storage } from '../store.js';\nimport {",
                        "import { initPage, productCard, $, $$, esc, icon, params, setMeta, refreshReveal, CATEGORIES, INTENTS, categoryBySlug, fmt, fmtNum, loadProducts, subLabel, storage, shopUrl, contextState, contactUrl } from './env.js';\nimport {", 'shop imports')
    shop = shop.replace("from '../facets.js';", "from './facets.js';")
    # Kiinduló állapot: az archívum kategóriája (pl. /kategoria/hangtalak/) + URL paraméterek.
    shop = must_replace(shop, 'let state = parseState(params());', 'let state = contextState(parseState(params()));', 'shop state')
    shop = must_replace(shop, "addEventListener('popstate', () => { state = parseState(params());", "addEventListener('popstate', () => { state = contextState(parseState(params()));", 'shop popstate')
    # URL: kategóriaváltáskor a bolt oldalára, különben az aktuális útvonalra.
    shop = must_replace(shop, "  const url = `${location.pathname}${qs ? `?${qs}` : ''}`;", '  const url = shopUrl(state, qs);', 'shop url')
    shop = must_replace(shop, '<a class="iu-button iu-button-outline" href="kapcsolat.html">', '<a class="iu-button iu-button-outline" href="${contactUrl()}">', 'shop contact')
    if 'termekek.html' in shop or 'kapcsolat.html' in shop:
        raise SystemExit('shop.js: maradt prototípus link')
    (js / 'filter.js').write_text(note.format(src='assets/js/pages/shop.js') + shop)


# ---------------------------------------------------------------------------
# 4. Blokk-markup generátor
# ---------------------------------------------------------------------------

def attrs_json(a):
    """Gutenberg serializeAttributes(): JSON + a komment-biztos escape-ek."""
    s = json.dumps(a, ensure_ascii=False, separators=(',', ':'))
    return (s.replace('\\"', '\\u0022').replace('--', '\\u002d\\u002d')
            .replace('<', '\\u003c').replace('>', '\\u003e').replace('&', '\\u0026'))


def block(name, attrs=None, inner=None, open_html='', close_html=''):
    name = name.removeprefix('core/')
    a = f' {attrs_json(attrs)}' if attrs else ''
    if inner is None:
        return f'<!-- wp:{name}{a} /-->'
    body = inner if isinstance(inner, str) else '\n'.join(inner)
    return f'<!-- wp:{name}{a} -->\n{open_html}{body}{close_html}\n<!-- /wp:{name} -->'


def cls(*names):
    return ' '.join(n for n in names if n)


def section(*rows, className='', light=False, bg='', full=False):
    a = {}
    if light:
        a['lightText'] = True
    if full:
        a['fullWidth'] = True
    if bg:
        a['bgColor'] = bg
        className = cls(className, f'bg-{bg}')
    if className:
        a['className'] = className
    c = cls('iu-section', 'iu-section-light' if light else '', 'iu-section-fullwidth' if full else '', className)
    return block('iu/section', a or None, list(rows), f'<section class="{c}">', '</section>')


def row(columns, *cols, v='', className=''):
    a = {'columns': columns}
    if v:
        a['vertical'] = v
    if className:
        a['className'] = className
    c = cls('iu-row', f'iu-row-vertical-{v}' if v else '', className)
    return block('iu/row', a, list(cols), f'<div class="{c}">', '</div>')


def col(width, *content, className=''):
    a = {'width': width}
    if className:
        a['className'] = className
    return block('iu/column', a, list(content), f'<div class="{cls("iu-column", f"iu-column-{width}", className)}">', '</div>')


def one(*content, **kw):
    """Egyoszlopos sor (a hierarchia miatt tartalom csak oszlopban lehet)."""
    return row('1-1', col('1-1', *content), **kw)


def group(*content, direction='', horizontal='', vertical='', className=''):
    a = {k: v for k, v in dict(direction=direction, horizontal=horizontal, vertical=vertical, className=className).items() if v}
    c = cls('iu-group', f'iu-group-{direction}' if direction else '', f'iu-group-horizontal-{horizontal}' if horizontal else '',
            f'iu-group-vertical-{vertical}' if vertical else '', className)
    return block('iu/group', a or None, list(content), f'<div class="{c}">', '</div>')


def h(text, level=2, className='', anchor=''):
    a = {}
    if level != 2:
        a['level'] = level
    if anchor:
        a['anchor'] = anchor
    if className:
        a['className'] = className
    attrs = f' class="{cls("wp-block-heading", className)}"' + (f' id="{anchor}"' if anchor else '')
    return block('heading', a or None, f'<h{level}{attrs}>{text}</h{level}>')


def p(text, className=''):
    return block('paragraph', {'className': className} if className else None, f'<p{f" class={chr(34)}{className}{chr(34)}" if className else ""}>{text}</p>')


def eyebrow(text):
    return p(text, 'eyebrow')


def lead(text):
    return p(text, 'lead')


def ul(items, className='', ordered=False):
    tag = 'ol' if ordered else 'ul'
    a = {}
    if ordered:
        a['ordered'] = True
    if className:
        a['className'] = className
    lis = [block('list-item', None, f'<li>{i}</li>') for i in items]
    return block('list', a or None, lis, f'<{tag} class="{cls("wp-block-list", className)}">', f'</{tag}>')


def html(raw):
    return block('html', None, raw)


def buttons(*items, align=''):
    btns = []
    for text, url, *rest in items:
        kind = rest[0] if rest else 'default'
        a = {'text': text, 'linkUrl': url}
        if kind != 'default':
            a['type'] = kind
        btns.append(block('iu/button', a, f'<a class="iu-button iu-button-{kind}" href="{url}">{text}</a>'))
    return block('iu/button-group', {'align': align} if align else None, btns, f'<div class="{cls("iu-button-group", f"iu-button-group-{align}" if align else "")}">', '</div>')


def dyn(block_name, **attrs):
    return block(block_name, attrs or None)


def section_head(eyebrow_text, title, text='', button=None, anchor=''):
    left = [eyebrow(eyebrow_text), h(title, anchor=anchor)]
    if text:
        left.append(p(text))
    parts = [group(*left, direction='column', className='section-head-text')]
    if button:
        parts.append(buttons(button))
    return group(*parts, horizontal='space-between', vertical='flex-end', className='section-head reveal')


def accordion(items):
    rows = [block('iu/accordion-item', {'title': q}, [p(a)], f'<div class="iu-accordion-item"><div class="iu-accordion-item-head">{q}</div><div class="iu-accordion-item-body">', '</div></div>')
            for q, a in items]
    return block('iu/accordion', None, rows, '<div class="iu-accordion">', '</div>')


# --- iu/form ---

def f_text(name, label, validate='', placeholder='', kind='text'):
    a = {'name': name, 'label': label}
    if placeholder:
        a['placeholder'] = placeholder
    if validate:
        a['validate'] = validate
    if kind != 'text':
        a['type'] = kind
    star = '<abbr class="required" title="kötelező">*</abbr>' if 'required' in validate else ''
    return block('iu/form-text', a, f'<div class="iu-form-field"><label>{label}{star}</label><input type="{kind}" name="{name}" placeholder="{placeholder}"/></div>')


def f_textarea(name, label, validate='', placeholder=''):
    a = {'name': name, 'label': label}
    if placeholder:
        a['placeholder'] = placeholder
    if validate:
        a['validate'] = validate
    return block('iu/form-textarea', a, f'<div class="iu-form-field span-2"><label>{label}</label><textarea name="{name}" placeholder="{placeholder}"></textarea></div>')


def f_select(name, label, options, default=''):
    a = {'name': name, 'label': label, 'options': '\n'.join(options)}
    if default:
        a['default'] = default
    opts = ''.join(f'<option{" selected" if o == default else ""}>{o}</option>' for o in options)
    return block('iu/form-select', a, f'<div class="iu-form-field"><label>{label}</label><select name="{name}">{opts}</select></div>')


PRIVACY = 'Elfogadom az <a href="[mandala_url page=privacy]">adatkezelési tájékoztatót</a>.'


def f_accept(name='adatkezeles', label=PRIVACY, error='Az elküldéshez fogadd el az adatkezelési tájékoztatót.'):
    return block('iu/form-accept', {'name': name, 'label': label, 'error': error},
                 f'<div class="iu-form-accept"><label><input type="checkbox" name="{name}" value="1"/> <span>{label}</span></label></div>')


def form(form_id, fields, button, success, className=''):
    a = {'formId': form_id, 'buttonLabel': button, 'success': success}
    if className:
        a['className'] = className
    return block('iu/form', a, fields, f'<form class="{cls("iu-form", className)}" data-form-id="{form_id}" novalidate>',
                 f'<div class="iu-form-submit"><button type="submit" class="iu-button">{button}</button></div><p class="form-message" role="status"></p></form>')


def form_steps(steps):
    inner = [block('iu/form-step', {'label': label}, fields, f'<fieldset class="iu-form-step"><legend>{label}</legend>', '</fieldset>') for label, fields in steps]
    return block('iu/form-steps', {'steps': '\n'.join(l for l, _ in steps)}, inner, '<div class="iu-form-steps">', '</div>')


V_EMAIL = 'required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek.'
S = '[iu_site_url]'


def icon(name):
    return dyn('mandala/icon', name=name)


def page_head(eyebrow_text, title_is_template=True, extra=None):
    """Oldalfej sablonokban: morzsamenü + H1 (iu/title) + kivonat."""
    content = [dyn('iu/breadcrumbs', separator='/')]
    if eyebrow_text:
        content.append(eyebrow(eyebrow_text))
    content += [dyn('iu/title', heading='h1'), dyn('mandala/page-lead')]
    if extra:
        content += extra
    return section(one(*content), className='page-head')


# --- Sablonok -------------------------------------------------------------

def templates():
    t = {}
    t['global_header'] = '\n\n'.join([
        section(one(dyn('mandala/notice')), className='site-notice'),
        section(one(dyn('mandala/header')), className='site-masthead'),
    ])
    t['global_footer'] = '\n\n'.join([
        section(row('1-2|1-2',
                    col('1-2', eyebrow('Hírlevél'), h('Csendes levelek, havonta kétszer', anchor='nl-title'),
                        lead('Új érkezések Nepálból és Indiából, magazincikkek és előfizetői kedvezmények. Nincs zaj – csak ami számít.')),
                    col('1-2', form('hirlevel', [
                        group(f_text('email', 'E-mail-cím', V_EMAIL, 'nev@pelda.hu', 'email'), className='nl-row'),
                        f_accept(label='Elfogadom az <a href="[mandala_url page=privacy]">adatkezelési tájékoztatót</a>, és bármikor leiratkozhatok.'),
                    ], 'Feliratkozom', 'Köszönjük! Küldtünk egy megerősítő levelet – kattints a benne lévő linkre.', className='nl-form')),
                    v='center'), className='newsletter'),
        section(
            row('1-4|1-4|1-4|1-4',
                col('1-4', dyn('mandala/logo'), p('Hangtálak, füstölők, szobrok, textilek és ajándékok – kézzel válogatva Nepál és India műhelyeiből, hogy a csendnek otthon is helye legyen.'), dyn('mandala/contact', variant='social')),
                col('1-4', h('Kínálat'), dyn('mandala/menu', location='mandala-footer-shop')),
                col('1-4', h('Vásárlás'), dyn('mandala/menu', location='mandala-footer-help')),
                col('1-4', h('Elérhetőség'), dyn('mandala/contact'))),
            one(dyn('mandala/footer-bottom')),
            className='site-footer', light=True),
    ])
    # Oldalak: saját fej (egy H1), a tartalom a szerkesztőből.
    t['single_page_content'] = '\n\n'.join([page_head(''), dyn('iu/content')])
    t['front_page_content'] = dyn('iu/content')
    t['404_content'] = section(row('1-2|1-2',
        col('1-2', p('404', 'error-code')),
        col('1-2', eyebrow('Hiba 404'), h('Ez az oldal elcsendesedett', 1, 'iu-title'),
            lead('Lehet, hogy elköltözött, vagy elgépelődött a cím. Keress rá arra, amit szerettél volna, vagy induljunk újra a kezdőlapról.'),
            dyn('iu/search', placeholder='Hangtál, füstölő, mala…'),
            buttons(('Kezdőlap', S, 'outline'), ('Teljes kínálat', '[mandala_url page=shop]', 'link'), ('Kapcsolat', '[mandala_url page=kapcsolat]', 'link'))),
        v='center'))
    t['search_content'] = '\n\n'.join([
        section(one(dyn('iu/breadcrumbs'), dyn('iu/title', heading='h1'), dyn('iu/search', placeholder='Mit keresel?')), className='page-head'),
        section(one(dyn('mandala/search-results')), className='pt-7'),
    ])
    blog_list = section(one(
        dyn('iu/terms', taxonomy='category', all_text='Összes', buttons=False),
        dyn('iu/query', main_query=True, template='[mandala_post_card]', columns='3', paging=True)), className='pt-7')
    t['blog_page_content'] = '\n\n'.join([
        section(one(dyn('iu/breadcrumbs'), eyebrow('Magazin'), dyn('iu/title', heading='h1'),
                    lead('Hetente új cikk arról, honnan jön, mit jelent, és hogyan használják a hagyományban azt, amit a kezedben tartasz.')), className='page-head'),
        blog_list])
    t['tax_category_content'] = '\n\n'.join([
        section(one(dyn('iu/breadcrumbs'), eyebrow('Magazin'), dyn('iu/title', heading='h1')), className='page-head'), blog_list])
    t['single_post_content'] = '\n\n'.join([
        section(row('1-1', col('1-1', dyn('iu/breadcrumbs'), dyn('mandala/post-meta'), dyn('iu/title', heading='h1'))), className='post-head'),
        section(one(dyn('mandala/post-hero')), className='post-hero'),
        section(one(dyn('iu/content')), className='post-body'),
        section(one(dyn('iu/post-navigation', params='"post_type": "post", "orderby": "date"', previous='', next='')), className='post-nav'),
        section(one(section_head('Magazin', 'Olvass tovább'),
                    dyn('iu/query', main_query=False, params='"post_type": "post", "posts_per_page": 3, "orderby": "rand"', template='[mandala_post_card]', columns='3')),
                bg='sand'),
    ])
    shop = '\n\n'.join([
        section(one(dyn('mandala/shop-head')), className='page-head'),
        section(row('1-4|3-4', col('1-4', dyn('mandala/filter')), col('3-4', dyn('mandala/product-results'))), className='pt-6 shop-layout'),
    ])
    t['archive_product_content'] = shop
    t['tax_product_cat_content'] = shop
    t['tax_product_tag_content'] = shop
    t['single_product_content'] = '\n\n'.join([
        section(one(dyn('iu/breadcrumbs', separator='/')),
                row('1-2|1-2', col('1-2', dyn('mandala/product-gallery')), col('1-2', dyn('mandala/product-summary'))),
                className='product-layout pb-8'),
        section(row('3-4|1-4', col('3-4', dyn('mandala/product-tabs')), col('1-4')), bg='white', className='pt-8'),
        section(row('1-3|2-3',
                    col('1-3', eyebrow('Kérdésed van?'), h('Kérdezz a termékről', anchor='kerdes'),
                        p('Hangfelvételt, pontos méretet vagy további fotót is kérhetsz – egy munkanapon belül válaszolunk e-mailben.', 'text-muted')),
                    col('2-3', form('termekkerdes', [
                        group(f_text('nev', 'Név', 'required|Add meg a neved.'), f_text('email', 'E-mail-cím', V_EMAIL, '', 'email'), className='form-grid'),
                        f_textarea('message', 'Kérdésed', 'required|Írd le a kérdésed.'),
                        f_accept(),
                    ], 'Kérdés elküldése', 'Köszönjük a kérdést! Egy munkanapon belül válaszolunk e-mailben.', className='panel'))),
                className='product-question'),
        section(one(dyn('mandala/products', mode='related', layout='carousel', limit='8', eyebrow='Ehhez illik', heading='Hasonló darabok'))),
    ])
    return t


# --- Oldaltartalmak ------------------------------------------------------------

def page_home():
    return '\n\n'.join([
        section(row('2-3|1-3',
                    col('2-3',
                        dyn('mandala/picture', image='hangtalak-gyertyafeny', alt='Nepáli hangtálak gyertyafényben, füstölővel, mandala falikép előtt', frame='hero', eager=True),
                        dyn('mandala/hero-mandala'),
                        eyebrow('Kézzel válogatva Nepálból és Indiából'),
                        h('Lassulj le, <em>érkezz meg.</em>', 1, anchor='hero-title'),
                        lead('Hangtálak, füstölők, mala láncok és rituális tárgyak azoknak, akik a hétköznapokban is helyet adnának a csendnek, a figyelemnek és a belső egyensúlynak.'),
                        buttons(('Szakrális tárgyak', '[mandala_url cat=szakralis-targyak]'), ('Honnan érkeznek?', '[mandala_url page=rolunk]', 'outline'))),
                    col('1-3', dyn('mandala/hero-pick', sku='MND-HT-0560', label='A hét hangtála'))),
                className='hero', light=True, bg='night'),
        section(row('1-4|1-4|1-4|1-4',
                    col('1-4', icon('pin'), p('<strong>Közvetlen import</strong>Nepál és India műhelyeiből')),
                    col('1-4', icon('truck'), p('<strong>Ingyenes szállítás</strong>25 000 Ft feletti rendelésnél')),
                    col('1-4', icon('hand'), p('<strong>Személyes tanács</strong>Segítünk a választásban')),
                    col('1-4', icon('return'), p('<strong>14 napos visszaküldés</strong>Indoklás nélkül'))),
                className='trust'),
        section(one(section_head('Szándék szerint', 'Mire van most szükséged?', 'Nem mindig tudjuk, milyen tárgyat keresünk – de azt igen, mit szeretnénk érezni. Kezdd innen.')),
                one(dyn('mandala/intents'))),
        section(one(section_head('Kínálat', 'Négy világ, egy hangulat', button=('Teljes kínálat', '[mandala_url page=shop]', 'outline'))),
                row('2-3|1-3',
                    col('2-3', dyn('mandala/category-tile', category='szakralis-targyak', size='tall', image='fustolok-csakra', alt='Füstölők, csakra illatok, lótuszvirág és backflow füstölőtartó',
                                   text='Hangtálak, füstölők, mala láncok, csengők és imazászlók a csendesebb pillanatokhoz.')),
                    col('1-3', group(
                        dyn('mandala/category-tile', category='lakberendezes', size='half', image='rez-sarkanyok', alt='Réz sárkány- és főnixszobor bambusz alátéten', text='Szobrok, szélcsengők, textilek.'),
                        dyn('mandala/category-tile', category='ruhazat-es-kiegeszitok', size='half', image='ruhazat-to', alt='Mintás indiai tunikát viselő nő a vízparton', text='Könnyű indiai textilek.', position='50% 22%'),
                        direction='column', className='cat-stack'))),
                one(dyn('mandala/category-tile', category='ajandektargyak', size='wide', art='gift-saffron', eyebrow='Ajándéktárgyak', heading='Ajándék, ami jelent is valamit',
                        text='Réz kulacsok, teák és összeállított ajándékcsomagok – kérésre kézzel írt kártyával, díszdobozban.')),
                bg='sand'),
        section(one(dyn('mandala/products', mode='new', layout='carousel', limit='12', eyebrow='Frissen érkezett', heading='Újdonságok', linkText='Összes újdonság', linkUrl='[mandala_url page=shop]?orderby=date'))),
        section(row('1-2|1-2',
                    col('1-2', eyebrow('Eredetünk'), h('Katmandutól Budapestig', anchor='origin-title'),
                        lead('Minden tárgyunknak van egy helye és egy keze, amely elkészítette. Nepál és India kis műhelyeivel dolgozunk – ahol a mesterség generációkon át öröklődik.'),
                        html('<div class="origin-facts"><div><h3><span class="origin origin-nepal" aria-hidden="true"></span>Nepál</h3><p>Hangtálak, csengők és tingsha Patan öntőműhelyeiből, kézzel sodort tibeti füstölők, mala láncok, imazászlók.</p></div><div><h3><span class="origin origin-india" aria-hidden="true"></span>India</h3><p>Moradabad rézművessége, jaipuri blokknyomott textilek és ékszerek, dél-indiai kézzel sodort füstölők.</p></div></div>'),
                        buttons(('Ismerd meg az utat', '[mandala_url page=rolunk]')), className='reveal'),
                    col('1-2', dyn('mandala/route-map'), className='reveal'), v='center'),
                className='origin-band', light=True, bg='night'),
        section(row('1-2|1-2',
                    col('1-2', dyn('mandala/picture', image='hangtalak-studio', alt='Két nepáli hangtál párnán, filcütőkkel és acél nyelvdobbal', sizes='(max-width: 991px) 100vw, 50vw'), className='reveal'),
                    col('1-2', eyebrow('Hangtál-kalauz'), h('Minden tálnak saját hangja van', anchor='bowl-title'),
                        lead('Hangtálainkat egyenként, meghallgatva választjuk ki, és minden tálnál megadjuk, amit egy gyakorló tudni szeretne – hogy ne a leírás, hanem a hang alapján dönthess.'),
                        ul(['<strong>Hz</strong> Mért alapfrekvencia', '<strong>G#, C…</strong> Zenei hang', '<strong>Csakra</strong> Hagyományos megfeleltetés', '<strong>Gramm</strong> Súly és ötvözet'], 'spec-grid'),
                        buttons(('Hangtálak', '[mandala_url cat=hangtalak]'), ('Hogyan válassz?', '[mandala_url post=hangtal-valasztas]', 'link')), className='reveal'),
                    v='center')),
        section(one(section_head('A tudatos választás', 'Kedvenceink', 'Darabok, amelyekhez mi magunk is újra és újra visszatérünk.'),
                    dyn('mandala/products', mode='featured', limit='4', columns='4')), bg='sand'),
        section(row('1-2|1-2',
                    col('1-2', dyn('mandala/picture', image='meditacio-erdo', alt='Csukott szemmel meditáló nő az erdőben', sizes='(max-width: 991px) 100vw, 50vw', position='40% 50%', className='media-frame-43'), className='reveal'),
                    col('1-2', eyebrow('A Mandala világa'), h('Tárgyak, amelyek túlmutatnak a funkciójukon', anchor='calm-title'),
                        lead('Egy hangtál, egy füstölő vagy egy Buddha-szobor nem dísz, hanem emlékeztető: hogy megállj, figyelj, és jobban érezd magad a saját teredben.'),
                        p('Ezért nem a „mindent egy helyen” elv szerint válogatunk. Csak azt hozzuk el, amit mi magunk is használnánk – és amiről el tudjuk mondani, honnan jön, ki készítette, és mire való.'),
                        buttons(('Rólunk', '[mandala_url page=rolunk]', 'outline')), className='reveal'),
                    v='center')),
        section(one(section_head('Vásárlóink mondták', 'Több, mint egy vásárlás'), dyn('mandala/testimonials')), bg='sand'),
        section(one(section_head('Magazin', 'Tudni, mit tartasz a kezedben', button=('Összes cikk', '[mandala_url page=magazin]', 'outline')),
                    dyn('iu/query', main_query=False, params='"post_type": "post", "posts_per_page": 3', template='[mandala_post_card]', columns='3'))),
    ])


def steps(items):
    return row('1-4|1-4|1-4|1-4', *[col('1-4', h(t, 3), p(x), className='reveal') for t, x in items], className='steps')


def page_about():
    return '\n\n'.join([
        section(row('2-3|1-3',
                    col('2-3', dyn('mandala/picture', image='hangtalak-gyertyafeny', frame='page-hero', eager=True),
                        eyebrow('Eredetünk'), h('Minden tárgynak van egy helye és egy keze', 2, 'display-title'),
                        lead('A Mandala tárgyai Nepál és India műhelyeiből érkeznek. Nem nagykereskedelmi katalógusból válogatunk, hanem onnan, ahol ezek a tárgyak ma is a mindennapok részei.')),
                    col('1-3')),
                className='page-hero', light=True, bg='night'),
        section(row('1-2|1-2',
                    col('1-2', eyebrow('Nepál'), h('A Katmandu-völgy műhelyei'),
                        lead('Patan évszázadok óta a fémöntés és a szoborkészítés központja. Innen érkeznek hangtálaink, csengőink, tingsháink és réz szobraink.'),
                        p('A tibeti közösségek kézzel sodort, pálca nélküli füstölői, a mala láncok és az imazászlók szintén nepáli kézművesek munkái. A hangtálakat egyenként meghallgatjuk, és megmérjük az alapfrekvenciájukat, mielőtt kiválasztjuk őket.'), className='reveal'),
                    col('1-2', dyn('mandala/picture', image='hangtalak-studio', alt='Nepáli hangtálak ütőkkel', sizes='(max-width: 991px) 100vw, 50vw'), className='reveal'),
                    v='center')),
        section(row('1-2|1-2',
                    col('1-2', dyn('mandala/picture', image='ruhazat-to', alt='Mintás indiai tunikát viselő nő', sizes='(max-width: 991px) 100vw, 50vw', className='media-frame-45'), className='reveal'),
                    col('1-2', eyebrow('India'), h('Réz, textil és illat'),
                        lead('Moradabad a „réz városa”: szélcsengőink, mécsestartóink, kulacsaink és füstölőtartóink innen jönnek.'),
                        p('Rádzsasztán fővárosa, Jaipur a blokknyomott textilek és a kézműves ékszerek otthona – sálaink, ruháink és gyűrűink nagy része itt készül. Kézzel sodort füstölőinket dél-indiai családi manufaktúrák készítik.'), className='reveal'),
                    v='center'), bg='sand'),
        section(one(section_head('Így dolgozunk', 'Az út a műhelytől hozzád')),
                steps([('Kapcsolat', 'Hosszú távú kapcsolatot építünk kisebb műhelyekkel – ismerjük a kezeket, amelyek a tárgyakat készítik.'),
                       ('Válogatás', 'Egyenként választunk: a hangtálat meghallgatjuk, a textilt megfogjuk, a füstölőt meggyújtjuk.'),
                       ('Ellenőrzés', 'Budapesten minden darabot átnézünk, lemérünk, és pontos adatokkal töltünk fel.'),
                       ('Csomagolás', 'Gondosan, lehetőség szerint újrahasznosított anyagokkal csomagolunk – ajándékba kézzel írt kártyával.')])),
        section(row('1-3|1-3|1-3',
                    col('1-3', h('Tisztelet', 3), p('A szakrális tárgyakat jelentésükkel együtt adjuk tovább – ezért írjuk a magazint.'), className='reveal'),
                    col('1-3', h('Átláthatóság', 3), p('Minden terméknél megadjuk, honnan érkezik, miből készült, és hogyan érdemes használni.'), className='reveal'),
                    col('1-3', h('Személyesség', 3), p('Kérdezz bátran: segítünk kiválasztani a hozzád illő hangtálat vagy ajándékot.'), className='reveal')),
                one(buttons(('A kínálat felfedezése', '[mandala_url page=shop]'), ('Írj nekünk', '[mandala_url page=kapcsolat]', 'outline'))),
                light=True, bg='night'),
    ])


def page_b2b():
    return '\n\n'.join([
        section(steps([('Jelentkezés', 'Töltsd ki a háromlépéses űrlapot a céges adataiddal.'),
                       ('Jóváhagyás', '1–2 munkanapon belül visszajelzünk, és aktiváljuk a viszonteladói fiókodat.'),
                       ('Rendelés', 'Belépés után minden terméknél a nagykereskedelmi ár látszik; ÁFÁ-s számlát állítunk ki.'),
                       ('Utánrendelés', 'Új érkezésekről elsőként értesítünk, a keresett tételeket félre is tesszük.')])),
        section(row('1-3|2-3',
                    col('1-3', eyebrow('Jelentkezés'), h('Legyünk partnerek'),
                        lead('A minimális rendelési érték és a kedvezménysávok a jóváhagyás után, a fiókodban jelennek meg.'),
                        ul(['Hangtálak mért frekvencia- és súlyadatokkal', 'Termékfotók és leírások a saját felületeidre', 'ÁFÁ-s számla, személyes átvétel vagy futár', 'Utánrendelés és félretétel új érkezéskor'], 'check-list')),
                    col('2-3', form('viszontelado', [form_steps([
                        ('Cég adatai', [group(
                            f_text('ceg', 'Cégnév', 'required|Add meg a cég nevét.'),
                            f_text('adoszam', 'Adószám', 'required|Add meg az adószámot.', '12345676-2-41'),
                            f_select('tevekenyseg', 'Tevékenység', ['Jógastúdió', 'Ajándék- vagy lakberendezési bolt', 'Masszázs / terápia', 'Webáruház', 'Egyéb'], 'Jógastúdió'),
                            f_text('telepules', 'Település', 'required|Add meg a települést.'),
                            f_text('weboldal', 'Weboldal vagy közösségi oldal'), className='form-grid')]),
                        ('Kapcsolattartó', [group(
                            f_text('nev', 'Név', 'required|Add meg a kapcsolattartó nevét.'),
                            f_text('telefon', 'Telefonszám', 'required|Add meg a telefonszámot.', '+36 30 123 4567', 'tel'),
                            f_text('email', 'E-mail-cím', V_EMAIL, '', 'email'), className='form-grid')]),
                        ('Érdeklődés', [group(
                            f_select('forgalom', 'Várható havi rendelés', ['50 000 Ft alatt', '50 000 – 200 000 Ft', '200 000 Ft felett'], '50 000 – 200 000 Ft'),
                            f_select('erdeklodes', 'Érdeklődési kör', ['Hangtálak és hangszerek', 'Füstölők', 'Szobrok és dekor', 'Textil és ékszer', 'Vegyes'], 'Vegyes'),
                            f_textarea('message', 'Megjegyzés'), className='form-grid'), f_accept()]),
                    ])], 'Jelentkezés elküldése', 'Köszönjük a jelentkezést! 1–2 munkanapon belül jelentkezünk a megadott e-mail-címen.', className='panel'))),
                bg='sand'),
    ])


def page_contact():
    return section(row('1-3|2-3',
        col('1-3', dyn('mandala/contact', variant='cards'), dyn('mandala/map')),
        col('2-3', form('kapcsolat', [
            h('Üzenet küldése', 2, 'h3'),
            group(f_text('nev', 'Név', 'required|Add meg a neved.'),
                  f_text('email', 'E-mail-cím', V_EMAIL, '', 'email'),
                  f_text('telefon', 'Telefonszám', '', '+36 30 123 4567', 'tel'),
                  f_select('tema', 'Téma', ['Termékkérdés', 'Rendelésem állapota', 'Visszaküldés', 'Személyes átvétel / bemutatóterem', 'Egyéb'], 'Termékkérdés'),
                  className='form-grid'),
            f_textarea('message', 'Üzenet', 'required|Írd meg az üzeneted.'),
            f_accept(),
        ], 'Üzenet küldése', 'Köszönjük az üzenetet! Egy munkanapon belül válaszolunk a megadott e-mail-címre.', className='panel'))), className='pt-7')


FAQ = [
    ('Mennyi idő alatt érkezik meg a csomag?', 'A raktáron lévő termékeket 1–2 munkanapon belül feladjuk; a GLS és a Foxpost általában a feladást követő munkanapon kézbesít.'),
    ('Meghallgathatom a hangtálat vásárlás előtt?', 'Igen: a bemutatóteremben előzetes egyeztetéssel, vagy a termékoldalon kérhetsz hangfelvételt.'),
    ('Kérhetek számlát cégnévre?', 'Igen, a pénztárban jelöld be a „Cégként vásárolok” lehetőséget, és add meg a cégnevet és az adószámot.'),
    ('Csomagoltok ajándékba?', 'Kérésre díszdobozba tesszük a terméket, és kézzel írt kártyát teszünk mellé – a pénztárban a megjegyzésnél írd meg a szöveget.'),
    ('Mi történik, ha sérülten érkezik a termék?', 'Fotózd le a csomagot és a terméket, és írj nekünk 3 napon belül – kicseréljük vagy visszatérítjük az árát.'),
]


def page_info(config):
    free_note = '<br><span class="text-muted text-small">' + fmt(config['freeShippingFrom']) + ' felett ingyenes</span>'
    ship_rows = ''.join(f'<tr><td><strong>{s["label"]}</strong></td><td>{s["note"]}</td><td>{fmt(s["price"]) + free_note if s["price"] else "Ingyenes"}</td></tr>' for s in config['shipping'])
    pay = [f'<strong>{x["label"]}</strong> – {x["note"]}' + (f' Díja: {fmt(x["fee"])} (személyes átvételnél nincs).' if x.get('fee') else '') for x in config['payment']]
    toc = html('<nav class="toc" aria-label="Tartalom"><ol><li><a href="#szallitas">Szállítás és átvétel</a></li><li><a href="#fizetes">Fizetési módok</a></li><li><a href="#visszakuldes">Visszaküldés, elállás</a></li><li><a href="#gyik">Gyakori kérdések</a></li></ol></nav>')
    return section(row('1-4|3-4', col('1-4', toc), col('3-4', group(
        h('Szállítás és átvétel', anchor='szallitas'),
        html(f'<div class="table-wrap"><table><thead><tr><th>Mód</th><th>Idő</th><th>Díj</th></tr></thead><tbody>{ship_rows}</tbody></table></div>'),
        p('A díjak bruttó árak, az ÁFÁ-t tartalmazzák. Személyes átvételnél e-mailben értesítünk, amikor a csomag átvehető.'),
        h('Fizetési módok', anchor='fizetes'), ul(pay),
        h('Visszaküldés és elállás', anchor='visszakuldes'),
        p(f'A termék átvételétől számított <strong>14 napon belül</strong> indoklás nélkül elállhatsz a vásárlástól. Jelezd e-mailben ({config["contact"]["email"]}), majd küldd vissza a terméket sértetlenül; a vételárat a visszaérkezéstől számított 14 napon belül visszautaljuk.'),
        ul(['Írj nekünk a rendelésszámmal.', 'Csomagold vissza a terméket (lehetőleg az eredeti csomagolásban).', 'Küldd vissza, vagy hozd be személyesen.'], ordered=True),
        h('Gyakori kérdések', anchor='gyik'), accordion(FAQ),
        direction='column', className='entry-content'))), className='pt-7')


LEGAL = {
    'aszf': ('Általános Szerződési Feltételek', [
        ('Szolgáltató adatai', 'Cégnév, székhely, cégjegyzékszám, adószám, e-mail, telefon – [kitöltendő a cégadatokból].'),
        ('A szerződés tárgya', 'A webáruházban kínált termékek adásvétele. A termékek főbb tulajdonságait a termékoldal tartalmazza.'),
        ('Árak', 'A feltüntetett árak forintban értendők, és tartalmazzák a 27% ÁFÁ-t. A szállítási díjat a pénztár külön sorban mutatja.'),
        ('A rendelés menete', 'Kosár → pénztár → a „Fizetési kötelezettséggel járó megrendelés” gomb megnyomása. A rendelésről automatikus visszaigazoló e-mailt küldünk.'),
        ('Fizetés és szállítás', 'Bankkártya (Barion), előre utalás, utánvét; GLS futár, Foxpost csomagautomata vagy személyes átvétel.'),
        ('Elállási jog', 'A fogyasztó a termék átvételétől számított 14 napon belül indoklás nélkül elállhat a szerződéstől (45/2014. Korm. rendelet).'),
        ('Szavatosság, jótállás', '[kitöltendő – jogászi átnézéssel]'),
        ('Panaszkezelés', 'Panaszodat e-mailben vagy postán jelezheted; 30 napon belül írásban válaszolunk. [Békéltető testület adatai – kitöltendő]'),
    ]),
    'adatkezelesi-tajekoztato': ('Adatkezelési tájékoztató', [
        ('Adatkezelő', '[Cégadatok – kitöltendő]'),
        ('Kezelt adatok', 'Rendelés teljesítéséhez: név, cím, e-mail, telefonszám, számlázási adatok. Hírlevélhez: e-mail-cím.'),
        ('Adatfeldolgozók', 'Tárhelyszolgáltató, futárszolgálat (GLS, Foxpost), fizetési szolgáltató (Barion), számlázó – [pontosítandó].'),
        ('Sütik', 'A szükséges sütik a működéshez kellenek; statisztikai és marketing sütiket csak hozzájárulással használunk. A beállítás a lábléc „Sütibeállítások” linkjén módosítható.'),
        ('Jogaid', 'Hozzáférés, helyesbítés, törlés, korlátozás, adathordozhatóság, tiltakozás; panasz a NAIH-nál.'),
    ]),
    'impresszum': ('Impresszum', [
        ('Üzemeltető', '[Cégnév, székhely, cégjegyzékszám, adószám – kitöltendő]'),
        ('Kapcsolat', '[e-mail] · [telefon]'),
        ('Tárhelyszolgáltató', '[Név, cím, elérhetőség – kitöltendő]'),
    ]),
}


def page_legal(key):
    title, sections = LEGAL[key]
    toc = html('<nav class="toc" aria-label="Tartalom"><ol>' + ''.join(f'<li><a href="#s{i + 1}">{i + 1}. {t}</a></li>' for i, (t, _) in enumerate(sections)) + '</ol></nav>')
    body = [p('Hatályos: 2026. október 1-től. <span class="text-muted">A szöveg helykitöltő – a végleges változat jogászi átnézés után kerül fel.</span>')]
    for i, (t, b) in enumerate(sections):
        body += [h(f'{i + 1}. {t}', anchor=f's{i + 1}'), p(b)]
    return section(row('1-4|3-4', col('1-4', toc), col('3-4', group(*body, direction='column', className='entry-content'))), className='pt-7')


def fmt(n):
    return f'{n:,.0f}'.replace(',', ' ') + ' Ft'


def rich(s):
    return re.sub(r'\*\*(.+?)\*\*', r'<strong>\1</strong>', s)


def article_content(a):
    out = []
    for b in a.get('content', []):
        kind, *args = b
        if kind == 'p':
            out.append(p(rich(args[0])))
        elif kind in ('h2', 'h3', 'h4'):
            out.append(h(args[0], int(kind[1])))
        elif kind in ('ul', 'ol'):
            out.append(ul([rich(x) for x in args[0]], ordered=kind == 'ol'))
        elif kind == 'quote':
            cite = f'<cite>— {args[1]}</cite>' if len(args) > 1 and args[1] else ''
            out.append(html(f'<blockquote><p>{args[0]}</p>{cite}</blockquote>'))
        elif kind == 'figure':
            out.append(dyn('mandala/picture', image=args[0], alt=args[1], sizes='720px', frame='none', className='figure-img'))
            out.append(p(args[1], 'figure-caption'))
        elif kind == 'table':
            head = ''.join(f'<th scope="col">{x}</th>' for x in args[0])
            rows = ''.join('<tr>' + ''.join(f'<td>{c}</td>' for c in r) + '</tr>' for r in args[1])
            out.append(html(f'<div class="table-wrap"><table><thead><tr>{head}</tr></thead><tbody>{rows}</tbody></table></div>'))
        elif kind == 'button':
            url = args[1]
            m = re.match(r'termekek\.html\?cat=([\w-]+)(?:&sub=([\w-]+))?', url)
            if m:
                url = f'[mandala_url cat={m.group(2) or m.group(1)}]'
            elif url.startswith('cikk.html?a='):
                url = f'[mandala_url post={url.split("=", 1)[1]}]'
            out.append(buttons((args[0], url)))
    return '\n\n'.join(out)


def build_content():
    tdir = THEME / 'templates'
    tdir.mkdir(exist_ok=True)
    for old in tdir.glob('*.html'):
        old.unlink()
    for name, markup in templates().items():
        (tdir / f'{name}.html').write_text(markup + '\n')
    cdir = THEME / 'setup/content'
    cdir.mkdir(parents=True, exist_ok=True)
    config = json.loads((THEME / 'setup/data/config.json').read_text())
    pages = {
        'kezdolap': page_home(), 'rolunk': page_about(), 'viszonteladoknak': page_b2b(), 'kapcsolat': page_contact(),
        'informaciok': page_info(config),
        **{k: page_legal(k) for k in LEGAL},
        'kedvencek': section(one(dyn('mandala/wishlist')), className='pt-7'),
        'kosar': section(one(dyn('mandala/checkout-progress'), block('shortcode', None, '[woocommerce_cart]')), className='pt-6') + '\n\n' + section(one(dyn('mandala/products', mode='cart', layout='carousel', limit='8', eyebrow='Ehhez illik', heading='Tedd teljessé'))),
        'penztar': section(one(dyn('mandala/checkout-progress'), block('shortcode', None, '[woocommerce_checkout]')), className='checkout-wrap'),
        'fiokom': section(one(block('shortcode', None, '[woocommerce_my_account]')), className='pt-7'),
    }
    for slug, markup in pages.items():
        (cdir / f'{slug}.html').write_text(markup + '\n')
    arts = json.loads((THEME / 'setup/data/articles.json').read_text())
    adir = THEME / 'setup/content/posts'
    adir.mkdir(exist_ok=True)
    for a in arts['articles']:
        (adir / f'{a["slug"]}.html').write_text(article_content(a) + '\n')
    print(f'sablonok: {len(templates())}, oldalak: {len(pages)}, cikkek: {len(arts["articles"])}')


# ---------------------------------------------------------------------------

def build_zip():
    DIST.parent.mkdir(exist_ok=True)
    skip = {'src', 'node_modules', '.DS_Store'}
    with zipfile.ZipFile(DIST, 'w', zipfile.ZIP_DEFLATED) as z:
        for f in sorted(THEME.rglob('*')):
            rel = f.relative_to(THEME)
            if f.is_file() and not (set(rel.parts) & skip):
                z.write(f, Path('mandala') / rel)
    print(f'{DIST.relative_to(ROOT)}  {DIST.stat().st_size / 1024:.0f} KB')


if __name__ == '__main__':
    import sys
    build_assets()
    build_js()
    # A blokk-markup csak kérésre generálódik újra: a dev/canon.mjs által kanonizált
    # sablonokat és oldaltartalmakat egy sima build nem írja felül.
    if '--content' in sys.argv or not (THEME / 'templates').exists():
        build_content()
    else:
        print('blokk-markup: változatlan (újragenerálás: --content, utána dev/canon.mjs)')
    build_zip()
