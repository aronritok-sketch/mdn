// Design system oldal: tokenek, szövegstílusok, rács, ikonok, komponensek és állapotaik.
import { initPage, $, esc, icon, productCard, breadcrumbs } from '../ui.js';
import { ICON_NAMES } from '../icons.js';
import { loadProducts } from '../store.js';

initPage();

const PALETTE = [
  ['white', 'Fehér', '#FFFFFF'], ['paper', 'Papír (háttér)', '#F7F4EE'], ['sand', 'Homok (felület)', '#EEE7DB'], ['line', 'Vonal', '#DDD3C3'],
  ['ink', 'Tinta (szöveg)', '#1C1916'], ['muted', 'Halvány szöveg', '#6E6357'], ['accent', 'Sáfrány', '#A9581A'], ['accent-deep', 'Sáfrány mély (link)', '#8A4512'],
  ['maroon', 'Bordó', '#6B2A2A'], ['night', 'Éjszaka', '#16120F'], ['success', 'Siker', '#2E6A3E'], ['error', 'Hiba', '#A3322A'],
];
function lum(hex) {
  const c = hex.match(/\w\w/g).map((x) => parseInt(x, 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
const TYPE = [
  ['H1', 'h1', '64 / 42 px', 'Cormorant Garamond 500, 1.08'], ['H2', 'h2', '44 / 34 px', 'Cormorant Garamond 500, 1.08'], ['H3', 'h3', '28 / 24 px', 'Cormorant Garamond 500'],
  ['H4', 'h4', '22 / 20 px', 'Inter 600, 1.3'], ['H5', 'h5', '18 px', 'Inter 600'], ['H6 / címke', 'h6', '13 px, nagybetűs', 'Inter 600, +0.14em'],
];
const SPACE = [4, 8, 12, 16, 24, 32, 48, 64, 96, 128];
const sec = (id, title, body, note = '') => `<section class="iu-section" id="${id}" style="padding:var(--space-8) 0;border-top:var(--border)"><div class="iu-row"><div class="iu-column iu-column-1-4"><h2 style="font-size:var(--fs-h3)">${title}</h2>${note ? `<p class="text-muted text-small">${note}</p>` : ''}</div><div class="iu-column iu-column-3-4">${body}</div></div></section>`;

const products = await loadProducts();
const sample = [products.find((p) => p.stock === 'in' && p.isNew), products.find((p) => p.stock === 'low'), products.find((p) => p.stock === 'out'), products.find((p) => p.compare)].filter(Boolean);

$('[data-styleguide]').innerHTML = `
  <section class="iu-section page-head"><div class="iu-row"><div class="iu-column iu-column-1-1">
    ${breadcrumbs([['Kezdőlap', 'index.html'], ['Design system']])}
    <p class="eyebrow">Átadási dokumentáció</p><h1 class="iu-title">Mandala design system</h1>
    <p>A korlátozott készlet, amelyből minden oldal épül. Minden érték a <code>vars.css</code> és a <code>theme.json</code> tokenjeiből jön – ami nem innen származik, az kivétel, és kérdést vet fel.</p>
    <div class="chip-row" style="margin-top:var(--space-5)">${[['szinek', 'Színek'], ['tipo', 'Tipográfia'], ['ter', 'Térköz és rács'], ['ikon', 'Ikonok'], ['gomb', 'Gombok'], ['urlap', 'Űrlapok'], ['elemek', 'Elemek'], ['kartya', 'Kártyák'], ['allapot', 'Üres és hibaállapot']].map(([h, l]) => `<a class="chip" href="#${h}">${l}</a>`).join('')}</div>
  </div></div></section>

  ${sec('szinek', 'Színek', `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:var(--space-4)">${PALETTE.map(([slug, name, hex]) => {
    const onPaper = contrast(hex, '#F7F4EE'); const onWhite = contrast(hex, '#FFFFFF');
    return `<figure style="margin:0;border:var(--border);border-radius:var(--radius-m);overflow:hidden;background:var(--c-white)"><div style="height:84px;background:${hex};border-bottom:var(--border)"></div>
      <figcaption style="padding:var(--space-3) var(--space-4);font-size:var(--fs-small)"><strong>${name}</strong><br><code>${slug}</code> · <code>${hex}</code><br><span class="text-muted">Papíron ${onPaper.toFixed(2)}:1 · fehéren ${onWhite.toFixed(2)}:1</span></figcaption></figure>`;
  }).join('')}</div>`, '12 névvel ellátott szín (theme.json paletta). Szövegre csak 4,5:1 feletti párosítást használunk (WCAG AA).')}

  ${sec('tipo', 'Tipográfia', `<div class="table-wrap entry-content" style="font-size:1rem"><table><thead><tr><th>Stílus</th><th>Minta</th><th>Asztal / mobil</th><th>Betű</th></tr></thead><tbody>
    ${TYPE.map(([n, tag, size, font]) => `<tr><td>${n}</td><td>${tag === 'h1' ? '<p class="is-h1" style="margin:0">Lassulj le, érkezz meg</p>' : `<${tag} style="margin:0">Lassulj le, érkezz meg</${tag}>`}</td><td class="num">${size}</td><td class="text-muted">${font}</td></tr>`).join('')}
    <tr><td>Bevezető</td><td><p class="lead" style="margin:0">Hangtálak, füstölők és mala láncok.</p></td><td class="num">19 / 17 px</td><td class="text-muted">Inter 400, 1.55</td></tr>
    <tr><td>Törzs</td><td><p style="margin:0">A hangtálakat egyenként hallgatjuk meg.</p></td><td class="num">16 px</td><td class="text-muted">Inter 400, 1.6</td></tr>
    <tr><td>Kicsi</td><td><p class="text-small" style="margin:0">Az árak az ÁFÁ-t tartalmazzák.</p></td><td class="num">14 px</td><td class="text-muted">Inter 400</td></tr>
    <tr><td>Felülcím</td><td><p class="eyebrow" style="margin:0">Frissen érkezett</p></td><td class="num">12 px</td><td class="text-muted">Inter 600, nagybetűs</td></tr>
  </tbody></table></div>`, 'Két betűcsalád: Cormorant Garamond (címek) és Inter (szöveg, felület). A mobil méretek 991.8 px alatt lépnek életbe.')}

  ${sec('ter', 'Térköz és rács', `<div style="display:grid;gap:var(--space-2)">${SPACE.map((v, i) => `<div style="display:grid;grid-template-columns:120px 1fr;align-items:center;gap:var(--space-4);font-size:var(--fs-small)"><code>--space-${i + 1}</code><span style="display:flex;align-items:center;gap:var(--space-3)"><span style="width:${v}px;height:16px;background:var(--c-accent);border-radius:2px"></span>${v} px</span></div>`).join('')}</div>
    <h3 style="font-size:var(--fs-h5);margin-top:var(--space-7)">Rács: 12 oszlop asztalon, 4 mobilon</h3>
    <div style="display:grid;grid-template-columns:repeat(12,1fr);gap:var(--space-4);margin:var(--space-4) 0">${Array.from({ length: 12 }, (_, i) => `<span style="height:48px;background:var(--c-accent-soft);border-radius:4px;display:grid;place-items:center;font-size:var(--fs-xs)">${i + 1}</span>`).join('')}</div>
    <p class="text-muted text-small">Tartalom max. 1280 px, oldalmargó 48 / 20 px, oszlopköz 32 / 16 px. 1920 px-en a tartalom középen marad, a szekcióhátterek teljes szélességűek. Az iu/row oszlopszerkezetei (1-2, 1-3, 2-3, 1-4, 3-4) erre a rácsra képezhetők le.</p>
    <h3 style="font-size:var(--fs-h5);margin-top:var(--space-6)">Lekerekítés és árnyék</h3>
    <div style="display:flex;gap:var(--space-5);flex-wrap:wrap">${[['radius-s', '6'], ['radius-m', '12'], ['radius-l', '20'], ['radius-pill', '999']].map(([n, v]) => `<div style="width:120px;height:80px;background:var(--c-white);border:var(--border);border-radius:var(--${n});display:grid;place-items:center;font-size:var(--fs-xs)"><code>${n}</code>${v} px</div>`).join('')}
    ${['shadow-s', 'shadow-m', 'shadow-l'].map((n) => `<div style="width:120px;height:80px;background:var(--c-white);border-radius:var(--radius-m);box-shadow:var(--${n});display:grid;place-items:center;font-size:var(--fs-xs)"><code>${n}</code></div>`).join('')}</div>`, 'Fix skála; a szekciók közti távolság egységesen 96 px (mobilon 64 px).')}

  ${sec('ikon', 'Ikonok', `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:var(--space-3)">${ICON_NAMES.map((n) => `<div style="display:grid;justify-items:center;gap:var(--space-2);padding:var(--space-4) var(--space-2);background:var(--c-white);border:var(--border);border-radius:var(--radius-s);font-size:var(--fs-xs)">${icon(n, 'ico ico-l')}<code>${n}</code></div>`).join('')}</div>`, 'Egy készlet, 24 px-es viewBox, 1,6 px vonal. SVG-ként exportálható.')}

  ${sec('gomb', 'Gombok és állapotok', `<div class="table-wrap entry-content" style="font-size:1rem"><table><thead><tr><th>Típus</th><th>Alap</th><th>Fókusz</th><th>Letiltott</th><th>Töltés</th></tr></thead><tbody>
    ${[['Elsődleges (default)', ''], ['Másodlagos (outline)', 'iu-button-outline'], ['Szöveges (link)', 'iu-button-link']].map(([n, c]) => `<tr><td>${n}</td><td><button class="iu-button ${c}" type="button">Kosárba</button></td><td><button class="iu-button ${c}" type="button" style="box-shadow:var(--focus)">Kosárba</button></td><td><button class="iu-button ${c}" type="button" disabled>Kosárba</button></td><td>${c === 'iu-button-link' ? '–' : `<button class="iu-button ${c} is-loading" type="button" aria-label="Folyamatban">Kosárba</button>`}</td></tr>`).join('')}
  </tbody></table></div>
  <div class="iu-button-group" style="margin-top:var(--space-4)"><button class="iu-button iu-button-large" type="button">Nagy gomb</button><button class="icon-button" type="button" aria-label="Ikongomb">${icon('heart')}</button><span class="chip">Chip</span><span class="chip is-active">Aktív chip</span></div>
  <p class="text-muted text-small" style="margin-top:var(--space-4)">Hover: az elsődleges gomb sáfrány mély színre vált, az outline kitöltődik (200 ms). Aktív: 1 px lesüllyedés. Minimális érinthető méret: 44 × 44 px.</p>`)}

  ${sec('urlap', 'Űrlapmezők', `<div class="iu-form"><div class="form-grid">
    <div class="iu-form-field"><label for="sg1">Üres mező<abbr class="required" title="kötelező">*</abbr></label><input id="sg1" class="input-text" placeholder="nev@pelda.hu"></div>
    <div class="iu-form-field woocommerce-validated"><label for="sg2">Kitöltött, érvényes</label><input id="sg2" class="input-text" value="anna@pelda.hu" style="border-color:#9DBBA3"></div>
    <div class="iu-form-field is-invalid"><label for="sg3">Hibás mező<abbr class="required" title="kötelező">*</abbr></label><input id="sg3" class="input-text" value="1234-5" aria-invalid="true" aria-describedby="sg3e"><p class="field-error" id="sg3e">${icon('alert', 'ico ico-s')}<span>Négyjegyű magyar irányítószámot adj meg.</span></p></div>
    <div class="iu-form-field"><label for="sg4">Letiltott</label><input id="sg4" class="input-text" value="Magyarország" disabled></div>
    <div class="iu-form-field span-2"><label for="sg5">Megjegyzés <span class="optional">(nem kötelező)</span></label><textarea id="sg5" rows="2"></textarea><p class="field-hint">Súgó szöveg a mező alatt.</p></div>
  </div>
  <label class="iu-form-accept"><input type="checkbox" checked> <span>Elfogadom az adatkezelési tájékoztatót.</span></label>
  <p class="form-message is-success">${icon('check-circle')}<span>Sikeres beküldés: köszönjük, megkaptuk.</span></p>
  <p class="form-message is-error">${icon('alert')}<span>Hiba: két mezőt javítani kell.</span></p>
  <div><button class="iu-button is-loading" type="button" aria-label="Küldés folyamatban">Küldés</button> <span class="text-muted text-small">Küldés folyamatban</span></div></div>`, 'iu/form mezők: validáció soronként „szabály|hibaüzenet” formában (required, email, phone, zip, taxno).')}

  ${sec('elemek', 'Elemek', `<div style="display:grid;gap:var(--space-5)">
    <div class="chip-row"><span class="badge">Új</span><span class="badge badge-sale">−12%</span><span class="badge badge-dark">Elfogyott</span><span class="origin origin-nepal">Nepál</span><span class="origin origin-india">India</span></div>
    <div style="display:grid;gap:var(--space-2)"><p class="stock in-stock">Raktáron</p><p class="stock low-stock">Utolsó 2 db raktáron</p><p class="stock out-of-stock">Elfogyott</p></div>
    <div class="woocommerce-message">${icon('check-circle')}<span>Sikerüzenet (woocommerce-message)</span></div>
    <div class="woocommerce-info">${icon('info')}<span>Információ (woocommerce-info)</span></div>
    <div class="woocommerce-error"><strong>${icon('alert')} Hibaösszesítő (woocommerce-error)</strong><ul><li><a href="#sg3">Irányítószám</a>: négyjegyű számot adj meg.</li></ul></div>
    <div class="ship-meter"><p>Még <strong>6 350 Ft</strong>, és ingyen szállítunk.</p><div class="meter"><span style="width:74%"></span></div></div>
    <div class="quantity"><button type="button" aria-label="Kevesebb">${icon('minus')}</button><input type="number" value="2" aria-label="Mennyiség"><button type="button" aria-label="Több">${icon('plus')}</button></div>
    <nav class="pagination" aria-label="Lapozó minta" style="justify-content:flex-start;margin:0"><span class="page-numbers current">1</span><a class="page-numbers" href="#elemek">2</a><a class="page-numbers" href="#elemek">3</a></nav>
  </div>`)}

  ${sec('kartya', 'Termékkártya (Loop Product)', `<ul class="products columns-4">${sample.map(productCard).join('')}</ul>`, 'Állapotok: új, kevés készlet, elfogyott, akciós. Kép aránya 1000 × 1128 (a jelenlegi feltöltésekkel egyező).')}

  ${sec('allapot', 'Üres és hibaállapot', `<div class="empty-state">${icon('search', 'ico ico-xl')}<h3>Nincs találat</h3><p>Minden listának van üres állapota: kínálat, keresés, kosár, kedvencek, magazin.</p><button class="iu-button" type="button">Szűrők törlése</button></div>`)}
`;
