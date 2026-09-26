// Futtatás: python3 -m http.server 8000 &  →  node tests/smoke.mjs [képernyőkép-mappa]
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
// Playwright: helyi telepítés (npm i -D playwright) vagy PWPATH=<globális playwright útvonal>.
const { chromium } = require(process.env.PWPATH || 'playwright');
const out = process.argv[2] || null; // opcionális: képernyőképek mappája
const B0 = process.env.BASE || 'http://localhost:8000/';
const B = B0;
const pages = ['index.html','termekek.html','termekek.html?cat=szakralis-targyak&sub=hangtalak','termek.html?p=mintas-hangtal-490g-405hz','termek.html?p=kezi-kovacsolt-hangtal-full-moon','kosar.html','penztar.html','koszonjuk.html','fiok.html','kedvencek.html','kereses.html?s=hangt','kereses.html?s=xyzq','404.html','magazin.html','cikk.html?a=csengo-tingsha-hangtal','rolunk.html','viszonteladoknak.html','kapcsolat.html','informaciok.html','jogi.html?d=aszf','stilus.html'];
const browser = await chromium.launch();
for (const [vw, tag] of [[1440,'d'],[390,'m']]) {
  const ctx = await browser.newContext({ viewport: { width: vw, height: 900 }, ignoreHTTPSErrors: true });
  await ctx.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({stats:false})); localStorage.setItem('mandala.cart.v2', JSON.stringify([{id:26234,qty:1},{id:4490,qty:2}])); } catch {} });
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log(tag, page.url().split('/').pop(), 'PAGEERROR', e.message));
  page.on('console', m => { if (m.type()==='error' && !/Failed to load resource/.test(m.text())) console.log(tag, page.url().split('/').pop(), 'CONSOLE', m.text()); });
  for (const [i,p] of pages.entries()) {
    await page.goto(B + p, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);
    const r = await page.evaluate(async () => {
      document.querySelectorAll('.reveal').forEach(e => e.classList.add('is-in'));
      for (let y = 0; y < document.body.scrollHeight; y += 700) { scrollTo(0, y); await new Promise(r => setTimeout(r, 30)); }
      scrollTo(0,0);
      const small = [...document.querySelectorAll('a[href], button, input, select, summary')].filter(el => el.offsetParent && getComputedStyle(el).visibility!=='hidden').map(el => { const b = el.getBoundingClientRect(); return { el, w: b.width, h: b.height }; })
        .filter(x => x.w > 0 && x.h > 0 && (x.h < 24) && !x.el.closest('p, li, td, .iu-breadcrumbs, .footer-bottom, .post-meta, .field-suggest, figcaption, label') && !['checkbox','radio','range'].includes(x.el.type)).map(x => (x.el.className||x.el.tagName)+':'+Math.round(x.h)).slice(0,4);
      return { h1: document.querySelectorAll('h1').length, sw: document.documentElement.scrollWidth, small };
    });
    const warn = [];
    if (r.h1 !== 1) warn.push('H1=' + r.h1);
    if (r.sw > vw) warn.push('OVERFLOW ' + r.sw);
    if (r.small.length) warn.push('SMALL ' + r.small.join(','));
    if (warn.length) console.log(tag, p, warn.join(' | '));
    if (out) await page.screenshot({ path: `${out}/${tag}-${String(i).padStart(2,'0')}.png`, fullPage: true });
  }
  await ctx.close();
}
await browser.close();
console.log('done');
