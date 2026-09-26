// Hangtál-választó (mandala/bowl-finder): lépésenkénti kérdések, pontozás a termékindexből,
// indoklással és a kínálat szűrőjére mutató linkkel.
import { $, $$, esc, icon, fmt, loadProducts, productCard, refreshReveal } from './env.js';
import { serialize, emptyState } from './facets.js';

const M = window.MANDALA || {};
const form = $('[data-finder]');

const SOUND = { magas: 'Tiszta, csengő hang', kozep: 'Kiegyensúlyozott hangfekvés', mely: 'Mély, hosszan zengő hang' };
const CHAKRA = { gyoker: 'Gyökér', szakralis: 'Szakrális', napfonat: 'Napfonat', sziv: 'Szív', torok: 'Torok', homlok: 'Homlok', korona: 'Korona' };

/** A tál hangfekvése a mért Hz és a súly alapján. */
function soundOf(p) {
  const hz = p.attrs.hz; const g = p.attrs.suly;
  if ((hz && hz >= 350) || (!hz && g && g <= 450)) return 'magas';
  if ((hz && hz <= 280) || (!hz && g && g >= 750)) return 'mely';
  return 'kozep';
}
const ORDER = ['magas', 'kozep', 'mely'];

function score(p, a) {
  let s = 0;
  const why = [];
  const sound = soundOf(p);
  const hz = p.attrs.hz ? `${p.attrs.hz} Hz` : '';
  // Hang
  if (a.hang === sound) { s += 3; why.push(`${SOUND[sound]}${hz ? ` (${hz})` : ''}`); }
  else if (Math.abs(ORDER.indexOf(a.hang) - ORDER.indexOf(sound)) === 1) s += 1;
  // Tapasztalat
  const k = p.attrs.keszites;
  if (a.tapasztalat === 'kezdo') {
    if (k === 'gepi' || k === 'ontott') { s += 2; why.push('Könnyen megszólaltatható'); }
    if (p.attrs.suly && p.attrs.suly >= 300 && p.attrs.suly <= 650) s += 1;
  } else if (a.tapasztalat === 'halado') {
    if (k === 'ontott' || k === 'kovacsolt') { s += 1; why.push('Gazdag felhangok'); }
  } else if (a.tapasztalat === 'profi') {
    if (k === 'kovacsolt') { s += 3; why.push('Kézzel kovácsolt'); }
    if (p.unique) { s += 1; why.push('Egyedi darab'); }
  }
  // Cél
  const intents = p.intents || [];
  if (a.cel === 'terapia' && p.attrs.suly >= 600) { s += 2; why.push('Csoportos hangfürdőhöz is elég erős'); }
  if (['csend', 'otthon', 'ajandek'].includes(a.cel) && intents.includes(a.cel)) { s += a.cel === 'ajandek' ? 2 : 1; if (a.cel === 'ajandek') why.push('Ajándéknak is ajánljuk'); }
  // Csakra
  const ch = p.attrs.csakra || [];
  if (a.csakra && ch.includes(a.csakra)) { s += 3; why.push(`${CHAKRA[a.csakra]}csakra`); }
  else if (a.csakra && ch.includes('het')) { s += 1; why.push('Mind a hét csakrához'); }
  // Keret
  if (a.keret) {
    const [lo, hi] = a.keret.split('-').map(Number);
    if (p.price >= lo && p.price <= hi) { s += 3; why.push('A kereten belül'); }
    else if (p.price > hi && p.price <= hi * 1.15) s += 0;
    else if (p.price > hi) s -= 6;
  }
  if (p.audio) { s += 0.5; why.push('Meghallgathatod'); }
  if (p.stock === 'incoming') { s -= 1; why.push(`Előrendelhető, érkezik ${p.incomingLabel || ''}`.trim()); }
  return { p, s, why: why.slice(0, 4) };
}

function shopLink(products, a) {
  const st = emptyState();
  const sample = products[0];
  if (sample) { st.cat = sample.cat; st.sub = sample.sub; }
  if (a.keret) st.sel.ar = a.keret.split('-').map(Number).map((v, i) => (i ? Math.min(v, 999999) : v));
  if (a.csakra) st.sel.csakra = [a.csakra];
  const url = new URL(M.shop || '/', location.href);
  url.search = serialize(st);
  return url.toString();
}

if (form) {
  const steps = $$('.finder-step', form);
  const next = $('[data-finder-next]', form);
  const back = $('[data-finder-back]', form);
  const result = $('[data-finder-result]', form);
  const bar = $('.finder-progress span', form);
  let step = 0;
  const answers = () => Object.fromEntries(new FormData(form));
  const answered = () => !!$(`[data-step="${step}"] input:checked`, form);

  function go(n, focus = true) {
    step = n;
    steps.forEach((f, i) => { f.hidden = i !== step; });
    result.hidden = true;
    next.hidden = false;
    back.hidden = step === 0;
    next.disabled = !answered();
    next.innerHTML = step === steps.length - 1 ? `Ajánlatot kérek ${icon('sparkle', 'ico ico-s')}` : `Tovább ${icon('arrow', 'ico ico-s')}`;
    bar.style.width = `${((step + 1) / (steps.length + 1)) * 100}%`;
    if (focus) ($(`[data-step="${step}"] input:checked`, form) || $(`[data-step="${step}"] input`, form))?.focus();
  }

  async function show() {
    const a = answers();
    const all = await loadProducts();
    const bowls = all.filter((p) => (p.sub === form.dataset.category || p.cat === form.dataset.category) && p.stock !== 'out');
    const ranked = bowls.map((p) => score(p, a)).sort((x, y) => y.s - x.s || x.p.price - y.p.price);
    const top = ranked.filter((r) => r.s > 0).slice(0, 3);
    steps.forEach((f) => { f.hidden = true; });
    next.hidden = true;
    back.hidden = false;
    bar.style.width = '100%';
    result.hidden = false;
    const link = shopLink(bowls, a);
    result.innerHTML = top.length
      ? `<h2 class="finder-title" tabindex="-1">${icon('sparkle')} Neked ezeket ajánljuk</h2>
         <ul class="products columns-3 finder-picks">${top.map((r) => productCard(r.p)).join('')}</ul>
         <div class="finder-actions"><a class="iu-button iu-button-outline" href="${esc(link)}">Minden illő hangtál a kínálatban ${icon('arrow', 'ico ico-s')}</a>
           <button type="button" class="iu-button iu-button-link" data-finder-restart>Újrakezdem</button>
           <a class="iu-button iu-button-link" href="#tanacsadas">Inkább személyesen kérdeznék</a></div>`
      : `<h2 class="finder-title" tabindex="-1">Most nincs pontosan ilyen tál raktáron</h2>
         <p>Írd meg, mit keresel – az érkező szállítmányból félreteszünk neked egyet, vagy segítünk választani.</p>
         <div class="finder-actions"><a class="iu-button" href="#tanacsadas">Tanácsot kérek</a><button type="button" class="iu-button iu-button-link" data-finder-restart>Újrakezdem</button></div>`;
    // Indoklás a kártyák alá
    $$('.finder-picks > li', result).forEach((li, i) => {
      li.insertAdjacentHTML('beforeend', `<ul class="finder-why" aria-label="Miért ajánljuk">${top[i].why.map((w) => `<li>${icon('check', 'ico ico-s')}${esc(w)}</li>`).join('')}</ul>`);
    });
    refreshReveal();
    $('.finder-title', result)?.focus();
    window.dataLayer?.push({ event: 'mandala_finder_result', finder_answers: a, finder_results: top.map((r) => r.p.sku) });
  }

  form.addEventListener('change', (e) => {
    if (!e.target.matches('input[type="radio"]')) return;
    next.disabled = false;
  });
  // Egérrel/érintéssel választva automatikusan tovább; billentyűzettel a „Tovább” gombbal.
  form.addEventListener('click', (e) => {
    const opt = e.target.closest('.finder-option');
    if (opt && e.detail > 0) setTimeout(() => { if (answered()) (step < steps.length - 1 ? go(step + 1) : show()); }, 220);
    if (e.target.closest('[data-finder-restart]')) { form.reset(); go(0); }
  });
  next.addEventListener('click', () => { if (!answered()) return; if (step < steps.length - 1) go(step + 1); else show(); });
  back.addEventListener('click', () => go(result.hidden ? Math.max(0, step - 1) : steps.length - 1));
  form.addEventListener('submit', (e) => e.preventDefault());
  go(0, false);
}
