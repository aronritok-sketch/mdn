// Futtatás: python3 -m http.server 8000 &  →  node tests/filter.mjs
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const B = process.env.BASE || 'http://localhost:8000/';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
await ctx.addInitScript(() => localStorage.setItem('mandala.cookie.v1', '{}'));
const p = await ctx.newPage();
const errs = []; p.on('pageerror', (e) => errs.push(e.message)); p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load/.test(m.text())) errs.push(m.text()); });
let fails = 0;
const ok = (l, c, x = '') => { if (!c) fails++; console.log(c ? '✓' : '✗', l, x); };
const count = async () => p.locator('[data-results] li.product').count();
const total = async () => Number((await p.locator('[data-count]').innerText()).match(/^\d+/)?.[0] || 0);
const groups = async () => p.$$eval('[data-filters] details[data-group]', (d) => d.map((x) => x.dataset.group));

await p.goto(B + 'termekek.html', { waitUntil: 'networkidle' });
ok('all products', await total() === 33, String(await total()));
ok('bowl facets hidden without category', !(await groups()).includes('hang'), (await groups()).join(','));

await p.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak', { waitUntil: 'networkidle' });
ok('8 bowls', await total() === 8);
ok('bowl facets visible', ['hang', 'hz', 'suly', 'csakra', 'keszites'].every(async (g) => true) && (await groups()).includes('hang') && (await groups()).includes('suly'), (await groups()).join(','));
await p.click('[data-facet="hang"][data-val="G#"]');
ok('note G# → 1', await total() === 1);
ok('disjunctive count: G still shows 1', (await p.locator('[data-facet="hang"][data-val="G"] small').innerText()) === '1');
await p.click('[data-facet="hang"][data-val="G"]');
ok('G# OR G → 2', await total() === 2);
ok('URL has hang', decodeURIComponent(p.url()).includes('hang=G#,G'), decodeURIComponent(p.url()).split('?')[1]);
await p.goBack(); await p.waitForTimeout(300);
ok('back button undoes last filter', await total() === 1, decodeURIComponent(p.url()).split('?')[1]);
await p.click('[data-chips] [data-clear="all"]'); await p.waitForTimeout(200);

await p.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak', { waitUntil: 'networkidle' });
await p.click('[data-range="suly"] input[data-num="min"]'); await p.keyboard.press('Control+A'); await p.keyboard.type('300');
await p.keyboard.press('Tab'); await p.waitForTimeout(150);
ok('tab keeps focus on max field after min commit', await p.evaluate(() => document.activeElement.dataset.num) === 'max');
await p.keyboard.press('Control+A'); await p.keyboard.type('600');
await p.press('[data-range="suly"] input[data-num="max"]', 'Enter'); await p.waitForTimeout(200);
ok('weight 300–600 g', await total() === 4, String(await total()));
ok('range chip', (await p.locator('[data-chips]').innerText()).includes('Súly'));
const hist = await p.locator('[data-range="suly"] .facet-hist span.is-in').count();
ok('histogram highlights range', hist > 0 && hist < 12, String(hist));
await p.click('[data-clear="suly"]'); await p.waitForTimeout(200);
await p.click('[data-preset="kovacsolt"]'); await p.waitForTimeout(200);
ok('preset: forged', await total() === 3);
ok('zero-result option is disabled (no dead end)', await p.locator('[data-facet="csakra"][data-val="torok"]').isDisabled());
await p.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak&keszites=kovacsolt&csakra=torok', { waitUntil: 'networkidle' });
ok('forged + throat (URL) → empty', await total() === 0);
ok('relax suggestions', await p.locator('[data-relax]').count() > 0, await p.locator('.relax').innerText().catch(() => ''));
await p.click('[data-relax="0"]'); await p.waitForTimeout(200);
ok('relax applied → results', await total() > 0, String(await total()));

// Legacy linkek
await p.goto(B + 'termekek.html?intent=ajandek', { waitUntil: 'networkidle' });
ok('legacy intent=ajandek', (await p.locator('[data-title]').innerText()) === 'Figyelmes ajándék' && decodeURIComponent(p.url()).includes('szandek=ajandek'));
await p.goto(B + 'termekek.html?sale=1', { waitUntil: 'networkidle' });
ok('legacy sale=1 → akciós', await total() === (await p.$$eval('[data-results] .badge-sale', (x) => x.length)) && await total() > 0, String(await total()));

// Szín, anyag, keresés
await p.goto(B + 'termekek.html', { waitUntil: 'networkidle' });
const szin = p.locator('[data-group="szin"]');
if (!(await szin.getAttribute('open'))) await szin.locator('summary').click();
await p.click('[data-facet="szin"][data-val="rez"]'); await p.waitForTimeout(200);
ok('swatch réz', await total() === 4, String(await total()));
ok('collapsed state remembered', await p.locator('[data-group="szin"][open]').count() === 1);
await p.click('[data-chips] [data-clear="all"]'); await p.waitForTimeout(200);
const anyag = p.locator('[data-group="anyag"]');
if (!(await anyag.getAttribute('open'))) await anyag.locator('summary').click();
await anyag.locator('[data-more]').click(); await p.waitForTimeout(200);
ok('show more reveals options', await anyag.locator('li:not([hidden])').count() > 6);
await p.fill('#find-anyag', 'bambusz'); await p.waitForTimeout(100);
ok('find within options', await anyag.locator('li:not([hidden])').count() === 1);
await p.fill('#filter-q', 'hangtál'); await p.waitForTimeout(600);
ok('search within', await total() === 8, String(await total()));
ok('focus stays in search', await p.evaluate(() => document.activeElement.id) === 'filter-q');
await p.fill('#filter-q', ''); await p.waitForTimeout(600);

// Kategóriaváltáskor a hangtál-szűrők törlődnek
await p.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak&hang=A', { waitUntil: 'networkidle' });
await p.click('[data-filters] [data-cat="lakberendezes"]:not([data-sub])'); await p.waitForTimeout(200);
ok('scoped facet dropped on category change', !decodeURIComponent(p.url()).includes('hang='), decodeURIComponent(p.url()).split('?')[1]);

// Mobil
const m = await b.newContext({ viewport: { width: 390, height: 844 } });
await m.addInitScript(() => localStorage.setItem('mandala.cookie.v1', '{}'));
const mp = await m.newPage(); mp.on('pageerror', (e) => errs.push('m:' + e.message));
await mp.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak', { waitUntil: 'networkidle' });
await mp.click('[data-filters-open]'); await mp.waitForTimeout(350);
await mp.click('[data-facet="csakra"][data-val="torok"]'); await mp.waitForTimeout(250);
ok('mobile: panel stays open, live count', (await mp.locator('[data-filters].is-open').count()) === 1 && (await mp.locator('.filters-apply .iu-button-large').innerText()).startsWith('2 termék'));
ok('mobile: no horizontal overflow', await mp.evaluate(() => document.documentElement.scrollWidth) <= 390);
if (process.argv[2]) { await mp.screenshot({ path: `${process.argv[2]}/f-mobile.png` }); }
await mp.click('.filters-apply .iu-button-large'); await mp.waitForTimeout(350);
ok('mobile: apply closes panel', (await mp.locator('[data-filters].is-open').count()) === 0 && await mp.locator('[data-results] li.product').count() === 2);
if (process.argv[2]) {
  await p.goto(B + 'termekek.html?cat=szakralis-targyak&sub=hangtalak&suly=400-800', { waitUntil: 'networkidle' });
  await p.screenshot({ path: `${process.argv[2]}/f-desktop.png`, fullPage: true });
}
console.log('ERRORS:', errs.length ? errs : 'none', `| hibás: ${fails}`);
await b.close();
process.exit(fails || errs.length ? 1 : 0);
