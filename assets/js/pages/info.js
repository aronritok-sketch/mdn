// Vásárlási információk: szekciók + GYIK (iu/accordion).
import { initPage, $, $$, esc, icon } from '../ui.js';
import { bindAccordions } from '../blocks.js';
import { CONFIG } from '../data.js';
import { fmt } from '../store.js';

initPage();
const FAQ = [
  ['Mennyi idő alatt érkezik meg a csomag?', 'A raktáron lévő termékeket 1–2 munkanapon belül feladjuk; a GLS és a Foxpost általában a feladást követő munkanapon kézbesít.'],
  ['Meghallgathatom a hangtálat vásárlás előtt?', 'Igen: a bemutatóteremben előzetes egyeztetéssel, vagy a termékoldalon kérhetsz hangfelvételt.'],
  ['Kérhetek számlát cégnévre?', 'Igen, a pénztárban jelöld be a „Cégként vásárolok” lehetőséget, és add meg a cégnevet és az adószámot.'],
  ['Csomagoltok ajándékba?', 'Kérésre díszdobozba tesszük a terméket, és kézzel írt kártyát teszünk mellé – a pénztárban a megjegyzésnél írd meg a szöveget.'],
  ['Mi történik, ha sérülten érkezik a termék?', 'Fotózd le a csomagot és a terméket, és írj nekünk 3 napon belül – kicseréljük vagy visszatérítjük az árát.'],
];
$('[data-info]').innerHTML = `
  <div class="entry-content" style="max-width:none">
    <section id="szallitas" style="scroll-margin-top:100px"><h2 style="margin-top:0">Szállítás és átvétel</h2>
      <div class="table-wrap"><table><thead><tr><th>Mód</th><th>Idő</th><th>Díj</th></tr></thead><tbody>
      ${CONFIG.shipping.map((s) => `<tr><td><strong>${esc(s.label)}</strong></td><td>${esc(s.note)}</td><td>${s.price ? `${fmt(s.price)}<br><span class="text-muted text-small">${fmt(CONFIG.freeShippingFrom)} felett ingyenes</span>` : 'Ingyenes'}</td></tr>`).join('')}
      </tbody></table></div><p>A díjak bruttó árak, az ÁFÁ-t tartalmazzák. Személyes átvételnél e-mailben értesítünk, amikor a csomag átvehető.</p></section>
    <section id="fizetes" style="scroll-margin-top:100px"><h2>Fizetési módok</h2><ul>
      ${CONFIG.payment.map((p) => `<li><strong>${esc(p.label)}</strong> – ${esc(p.note)}${p.fee ? ` Díja: ${fmt(p.fee)} (személyes átvételnél nincs).` : ''}</li>`).join('')}</ul></section>
    <section id="visszakuldes" style="scroll-margin-top:100px"><h2>Visszaküldés és elállás</h2>
      <p>A termék átvételétől számított <strong>14 napon belül</strong> indoklás nélkül elállhatsz a vásárlástól. Jelezd e-mailben (${esc(CONFIG.contact.email)}), majd küldd vissza a terméket sértetlenül; a vételárat a visszaérkezéstől számított 14 napon belül visszautaljuk.</p>
      <ol><li>Írj nekünk a rendelésszámmal.</li><li>Csomagold vissza a terméket (lehetőleg az eredeti csomagolásban).</li><li>Küldd vissza, vagy hozd be személyesen.</li></ol></section>
    <section id="gyik" style="scroll-margin-top:100px"><h2>Gyakori kérdések</h2>
      <div class="iu-accordion">${FAQ.map(([q, a], i) => `<div class="iu-accordion-item"><button type="button" class="iu-accordion-item-head" aria-expanded="${i === 0}">${esc(q)} ${icon('plus')}</button><div class="iu-accordion-item-body"><p>${esc(a)}</p></div></div>`).join('')}</div></section>
  </div>`;
bindAccordions();
// Aktív tartalomjegyzék elem
const links = $$('.toc a');
const io = new IntersectionObserver((en) => en.forEach((x) => { if (x.isIntersecting) links.forEach((l) => l.classList.toggle('is-active', l.hash === `#${x.target.id}`)); }), { rootMargin: '-30% 0px -60% 0px' });
$$('[data-info] section[id]').forEach((s) => io.observe(s));
