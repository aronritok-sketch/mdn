// Jogi oldalak (single_page, hosszú szöveg + tartalomjegyzék). A teljes szöveg a jelenlegi oldalról / jogásztól jön.
import { initPage, $, $$, esc, params, setMeta, breadcrumbs } from '../ui.js';
import { CONFIG } from '../data.js';

initPage();
const DOCS = {
  aszf: { title: 'Általános Szerződési Feltételek', sections: [
    ['Szolgáltató adatai', 'Cégnév, székhely, cégjegyzékszám, adószám, e-mail, telefon – [kitöltendő a cégadatokból].'],
    ['A szerződés tárgya', 'A mandala.hu webáruházban kínált termékek adásvétele. A termékek főbb tulajdonságait a termékoldal tartalmazza.'],
    ['Árak', `A feltüntetett árak forintban értendők, és tartalmazzák a ${CONFIG.vatRate}% ÁFÁ-t. A szállítási díjat a pénztár külön sorban mutatja.`],
    ['A rendelés menete', 'Kosár → pénztár → a „Fizetési kötelezettséggel járó megrendelés” gomb megnyomása. A rendelésről automatikus visszaigazoló e-mailt küldünk.'],
    ['Fizetés és szállítás', 'Bankkártya (Barion), előre utalás, utánvét; GLS futár, Foxpost csomagautomata vagy személyes átvétel.'],
    ['Elállási jog', 'A fogyasztó a termék átvételétől számított 14 napon belül indoklás nélkül elállhat a szerződéstől (45/2014. Korm. rendelet).'],
    ['Szavatosság, jótállás', '[kitöltendő – jogászi átnézéssel]'],
    ['Panaszkezelés', 'Panaszodat e-mailben vagy postán jelezheted; 30 napon belül írásban válaszolunk. [Békéltető testület adatai – kitöltendő]'],
  ] },
  adatkezeles: { title: 'Adatkezelési tájékoztató', sections: [
    ['Adatkezelő', '[Cégadatok – kitöltendő]'],
    ['Kezelt adatok', 'Rendelés teljesítéséhez: név, cím, e-mail, telefonszám, számlázási adatok. Hírlevélhez: e-mail-cím.'],
    ['Adatfeldolgozók', 'Tárhelyszolgáltató, futárszolgálat (GLS, Foxpost), fizetési szolgáltató (Barion), számlázó (Számlázz.hu / Billingo) – [pontosítandó].'],
    ['Sütik', 'A szükséges sütik a működéshez kellenek; statisztikai és marketing sütiket csak hozzájárulással használunk. A beállítás a lábléc „Sütibeállítások” linkjén módosítható.'],
    ['Jogaid', 'Hozzáférés, helyesbítés, törlés, korlátozás, adathordozhatóság, tiltakozás; panasz a NAIH-nál.'],
  ] },
  impresszum: { title: 'Impresszum', sections: [
    ['Üzemeltető', '[Cégnév, székhely, cégjegyzékszám, adószám – kitöltendő]'],
    ['Kapcsolat', `${CONFIG.contact.email} · ${CONFIG.contact.phone}`],
    ['Tárhelyszolgáltató', '[Név, cím, elérhetőség – kitöltendő]'],
  ] },
};
const key = DOCS[params().get('d')] ? params().get('d') : 'aszf';
const doc = DOCS[key];
setMeta({ title: doc.title });
$('[data-legal]').innerHTML = `<section class="iu-section page-head"><div class="iu-row"><div class="iu-column iu-column-1-1">
  ${breadcrumbs([['Kezdőlap', 'index.html'], [doc.title]])}<h1 class="iu-title">${esc(doc.title)}</h1><p>Hatályos: 2026. október 1-től. <span class="text-muted">A szöveg helykitöltő – a végleges változat jogászi átnézés után kerül fel.</span></p>
  <div class="chip-row" style="margin-top:var(--space-5)">${Object.entries(DOCS).map(([k, d]) => `<a class="chip ${k === key ? 'is-active' : ''}" href="jogi.html?d=${k}" ${k === key ? 'aria-current="page"' : ''}>${esc(d.title)}</a>`).join('')}</div>
</div></div></section>
<section class="iu-section" style="padding-top:var(--space-7)"><div class="iu-row">
  <div class="iu-column iu-column-1-4"><nav class="toc" aria-label="Tartalom"><ol>${doc.sections.map(([t], i) => `<li><a href="#s${i + 1}">${i + 1}. ${esc(t)}</a></li>`).join('')}</ol></nav></div>
  <div class="iu-column iu-column-3-4"><div class="entry-content">${doc.sections.map(([t, b], i) => `<h2 id="s${i + 1}" style="${i ? '' : 'margin-top:0'};scroll-margin-top:100px">${i + 1}. ${esc(t)}</h2><p>${esc(b)}</p>`).join('')}</div></div>
</div></section>`;
const links = $$('.toc a');
const io = new IntersectionObserver((en) => en.forEach((x) => { if (x.isIntersecting) links.forEach((l) => l.classList.toggle('is-active', l.hash === `#${x.target.id}`)); }), { rootMargin: '-20% 0px -70% 0px' });
$$('.entry-content h2[id]').forEach((h) => io.observe(h));
