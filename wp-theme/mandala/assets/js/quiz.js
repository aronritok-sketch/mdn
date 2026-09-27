// Választó kvízek (mandala/quiz): füstölő- és ajándékválasztó. A kérdéseket és a pontozási
// szabályokat a szerver adja (data-quiz-config), a pontozás a termékindexből fut a böngészőben.
import { $, $$, esc, icon, loadProducts, productCard, refreshReveal } from './env.js';

const form = $('[data-quiz]');

const norm = (v) => String(v ?? '').toLowerCase();
function field(p, f) {
  if (f.startsWith('attr.')) return (p.attrs || {})[f.slice(5)];
  return p[f];
}
function hit(p, rule) {
  const have = field(p, rule.f);
  const want = Array.isArray(rule.v) ? rule.v.map(norm) : [norm(rule.v)];
  if (rule.f === 'name') return want.some((w) => norm(p.name).includes(w));
  if (typeof rule.v === 'boolean') return !!have === rule.v;
  const list = Array.isArray(have) ? have.map(norm) : [norm(have)];
  return list.some((h) => want.includes(h));
}
function inScope(p, scope) {
  if (p.voucher || p.stock === 'out' || p.workshop) return false;
  if (scope.sub && !scope.sub.includes(p.sub) && !scope.sub.includes(p.cat)) return false;
  return true;
}
function score(p, answers, rules) {
  let s = 0;
  const why = [];
  for (const [q, value] of Object.entries(answers)) {
    const r = rules[q]?.[value];
    if (!r) continue;
    let matched = false;
    for (const b of r.boost || []) if (hit(p, b)) { s += b.w; matched = true; }
    if (matched && r.why) why.push(r.why);
    if (r.price) {
      const [lo, hi] = r.price;
      if (p.price >= lo && p.price <= hi) { s += 3; why.push('A kereten belül'); }
      else if (p.price > hi) s -= 6;
      else s -= 1;
    }
  }
  if (p.stock === 'low') why.push('Már csak néhány darab');
  if (p.rating >= 4.5) { s += 0.5; why.push(`${p.rating}/5 értékelés`); }
  return { p, s, why: [...new Set(why)].slice(0, 3) };
}

if (form) {
  const cfg = JSON.parse($('[data-quiz-config]', form).textContent);
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
    const pool = all.filter((p) => inScope(p, cfg.scope));
    const ranked = pool.map((p) => score(p, a, cfg.rules)).sort((x, y) => y.s - x.s || x.p.price - y.p.price);
    const top = ranked.filter((r) => r.s > 0).slice(0, 6);
    const extras = [];
    for (const c of cfg.companions || []) {
      if (Object.entries(c.when).every(([k, v]) => a[k] === v)) {
        const comp = all.filter((p) => p.sub === c.sub && p.stock !== 'out' && (!c.name || norm(p.name).includes(norm(c.name))))
          .concat(all.filter((p) => p.sub === c.sub && p.stock !== 'out'))[0];
        if (comp) extras.push({ label: c.label, p: comp });
      }
    }
    const actions = [];
    if (a.atadas === 'utalvany' && cfg.links.voucher) actions.push(`<a class="iu-button" href="${esc(cfg.links.voucher)}">Ajándékutalvány ${icon('arrow', 'ico ico-s')}</a>`);
    if (a.atadas === 'csomag' && cfg.links.gift) actions.push(`<a class="iu-button" href="${esc(cfg.links.gift)}">Csomagoljuk be együtt ${icon('gift', 'ico ico-s')}</a>`);
    actions.push(`<a class="iu-button iu-button-outline" href="${esc(cfg.links.shop)}">A teljes kínálat ${icon('arrow', 'ico ico-s')}</a>`, '<button type="button" class="iu-button iu-button-link" data-finder-restart>Újrakezdem</button>');
    steps.forEach((f) => { f.hidden = true; });
    next.hidden = true;
    back.hidden = false;
    bar.style.width = '100%';
    result.hidden = false;
    result.innerHTML = top.length
      ? `<h2 class="finder-title" tabindex="-1">${icon('sparkle')} Neked ezeket ajánljuk</h2>
         <ul class="products columns-3 finder-picks">${top.map((r) => productCard(r.p)).join('')}</ul>
         ${extras.map((x) => `<h3 class="quiz-extra-title">${esc(x.label)}</h3><ul class="products columns-3">${productCard(x.p)}</ul>`).join('')}
         <div class="finder-actions">${actions.join('')}</div>`
      : `<h2 class="finder-title" tabindex="-1">Most nincs pontosan ilyen raktáron</h2><p>Nézz körül a teljes kínálatban, vagy kérdezd az AI tanácsadónkat.</p><div class="finder-actions">${actions.join('')}</div>`;
    $$('.finder-picks > li', result).forEach((li, i) => {
      if (top[i].why.length) li.insertAdjacentHTML('beforeend', `<ul class="finder-why" aria-label="Miért ajánljuk">${top[i].why.map((w) => `<li>${icon('check', 'ico ico-s')}${esc(w)}</li>`).join('')}</ul>`);
    });
    refreshReveal();
    $('.finder-title', result)?.focus();
    window.dataLayer?.push({ event: 'mandala_quiz_result', quiz: form.dataset.quiz, quiz_answers: a, quiz_results: top.map((r) => r.p.sku) });
  }

  form.addEventListener('change', (e) => { if (e.target.matches('input[type="radio"]')) next.disabled = false; });
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
