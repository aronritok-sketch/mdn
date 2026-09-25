import { initPage, cartLine, $, $$, esc, icon } from '../app.js';
import { logoMark } from '../art.js';
import { CONFIG } from '../data.js';
import { cart, cartDetails, fmt } from '../store.js';

initPage({ active: 'cart', newsletter: false });

const root = $('[data-checkout]');
const choice = { shipping: CONFIG.shipping[0].id, payment: CONFIG.payment[0].id };
let formDraft = {};
let ordered = false;

function totals(subtotal, freeShipping) {
  const ship = CONFIG.shipping.find((s) => s.id === choice.shipping);
  const pay = CONFIG.payment.find((p) => p.id === choice.payment);
  const shipping = freeShipping ? 0 : ship.price;
  const fee = pay.fee || 0;
  return { shipping, fee, total: subtotal + shipping + fee };
}

const field = (name, label, { type = 'text', auto = '', full = false, required = true, attrs = '' } = {}) => `
  <div class="field ${full ? 'full' : ''}">
    <label for="f-${name}">${label}${required ? '' : ' <span class="muted">(nem kötelező)</span>'}</label>
    <input class="input" id="f-${name}" name="${name}" type="${type}" ${auto ? `autocomplete="${auto}"` : ''} ${required ? 'required' : ''} ${attrs} value="${esc(formDraft[name] || '')}">
    <span class="field-err" id="e-${name}"></span>
  </div>`;

async function render() {
  const { items, subtotal, remaining, freeShipping } = await cartDetails();
  if (!items.length) {
    root.innerHTML = `<div class="confirm">${icon('bag', 'ico ico-xl')}
      <h1 style="font-size:2.6rem;margin-top:1rem">A kosarad üres</h1>
      <p class="lead" style="margin:0 auto 2rem">Nézz körül a kínálatban – hangtálak, füstölők, szobrok és ajándékok várnak.</p>
      <a class="btn btn-primary" href="termekek.html">Tovább a kínálathoz</a></div>`;
    return;
  }
  const t = totals(subtotal, freeShipping);
  const pickup = choice.shipping === 'pickup';

  root.innerHTML = `<div class="container checkout">
    <div>
      <section class="panel" aria-labelledby="c1"><h2 id="c1"><span class="step">1</span>Kosár</h2>
        <div class="ship-meter" style="border-radius:var(--r-sm)"><p>${freeShipping ? `${icon('check')} A szállítás ingyenes.` : `Még <strong>${fmt(remaining)}</strong> és ingyen szállítunk.`}</p>
        <div class="meter"><span style="width:${Math.min(100, (subtotal / CONFIG.freeShippingFrom) * 100)}%"></span></div></div>
        <ul class="cart-lines" style="padding:0">${items.map(cartLine).join('')}</ul>
      </section>

      <form id="penztar" class="checkout-form" novalidate data-form>
        <section class="panel" aria-labelledby="c2"><h2 id="c2"><span class="step">2</span>Elérhetőség</h2>
          <div class="form-grid">
            ${field('lastname', 'Vezetéknév', { auto: 'family-name' })}
            ${field('firstname', 'Keresztnév', { auto: 'given-name' })}
            ${field('email', 'E-mail-cím', { type: 'email', auto: 'email' })}
            ${field('phone', 'Telefonszám', { type: 'tel', auto: 'tel', attrs: 'placeholder="+36 30 123 4567"' })}
          </div>
        </section>

        <section class="panel" aria-labelledby="c3"><h2 id="c3"><span class="step">3</span>Szállítás</h2>
          <fieldset class="options" style="border:0;padding:0;margin:0 0 1.2rem"><legend class="sr-only">Szállítási mód</legend>
            ${CONFIG.shipping.map((s) => `<label class="option"><input type="radio" name="shipping" value="${s.id}" ${choice.shipping === s.id ? 'checked' : ''}>
              <span>${esc(s.label)}<small>${esc(s.note)}</small></span><strong>${freeShipping || !s.price ? 'Ingyenes' : fmt(s.price)}</strong></label>`).join('')}
          </fieldset>
          ${pickup ? `<p class="note">Az átvétel időpontját e-mailben egyeztetjük. ${esc(CONFIG.contact.address)}.</p>` : `<div class="form-grid">
            ${field('zip', 'Irányítószám', { auto: 'postal-code', attrs: 'inputmode="numeric" maxlength="4"' })}
            ${field('city', 'Település', { auto: 'address-level2' })}
            ${field('address', choice.shipping === 'locker' ? 'Számlázási cím' : 'Utca, házszám', { auto: 'street-address', full: true })}
            ${choice.shipping === 'locker' ? '<p class="note full">A csomagautomatát a rendelés után, a szolgáltató felületén választhatod ki.</p>' : ''}
          </div>`}
        </section>

        <section class="panel" aria-labelledby="c4"><h2 id="c4"><span class="step">4</span>Fizetés</h2>
          <fieldset class="options" style="border:0;padding:0;margin:0 0 1.2rem"><legend class="sr-only">Fizetési mód</legend>
            ${CONFIG.payment.map((p) => `<label class="option"><input type="radio" name="payment" value="${p.id}" ${choice.payment === p.id ? 'checked' : ''}>
              <span>${esc(p.label)}<small>${esc(p.note)}</small></span><strong>${p.fee ? `+${fmt(p.fee)}` : ''}</strong></label>`).join('')}
          </fieldset>
          <div class="field" style="margin-bottom:1.2rem"><label for="f-note">Megjegyzés <span class="muted">(nem kötelező)</span></label>
            <textarea class="input" id="f-note" name="note" placeholder="Például az ajándékkártya szövege">${esc(formDraft.note || '')}</textarea></div>
          <label class="check"><input type="checkbox" name="terms" required ${formDraft.terms ? 'checked' : ''}> <span>Elolvastam és elfogadom az <a href="informaciok.html#aszf" target="_blank">ÁSZF-et</a> és az <a href="informaciok.html#adatvedelem" target="_blank">adatkezelési tájékoztatót</a>.</span></label>
          <span class="field-err" id="e-terms"></span>
        </section>
      </form>
    </div>

    <aside class="summary panel" aria-labelledby="sum-title">
      <h2 id="sum-title">Összesítő</h2>
      <p class="muted small">${items.reduce((s, l) => s + l.qty, 0)} termék</p>
      <div class="sum-rows">
        <div><span>Részösszeg</span><span>${fmt(subtotal)}</span></div>
        <div><span>Szállítás</span><span>${t.shipping ? fmt(t.shipping) : 'Ingyenes'}</span></div>
        ${t.fee ? `<div><span>Utánvét díja</span><span>${fmt(t.fee)}</span></div>` : ''}
        <div class="total"><span>Fizetendő</span><span>${fmt(t.total)}</span></div>
      </div>
      <p class="muted small" style="margin:.4rem 0 1.2rem">Az árak az ÁFÁ-t tartalmazzák.</p>
      <button class="btn btn-primary btn-block" type="submit" form="penztar">Megrendelés elküldése</button>
      <ul class="perks">
        <li>${icon('check')} Biztonságos, titkosított fizetés</li>
        <li>${icon('return')} 14 napos visszaküldés</li>
        <li>${icon('hand')}<span>Kérdés esetén: <a href="mailto:${CONFIG.contact.email}">${CONFIG.contact.email}</a></span></li>
      </ul>
    </aside>
  </div>`;
}

function saveDraft() {
  const form = $('[data-form]');
  if (!form) return;
  const data = new FormData(form);
  formDraft = { ...formDraft, ...Object.fromEntries([...data.entries()].filter(([k]) => !['shipping', 'payment'].includes(k))), terms: form.terms?.checked };
}

function validate(form) {
  const errs = {};
  const v = (n) => (form[n]?.value || '').trim();
  ['lastname', 'firstname'].forEach((n) => { if (!v(n)) errs[n] = 'Kötelező mező.'; });
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))) errs.email = 'Adj meg egy érvényes e-mail-címet.';
  if (v('phone').replace(/\D/g, '').length < 9) errs.phone = 'Adj meg egy érvényes telefonszámot.';
  if (form.zip) {
    if (!/^\d{4}$/.test(v('zip'))) errs.zip = 'Négyjegyű irányítószám.';
    if (!v('city')) errs.city = 'Kötelező mező.';
    if (!v('address')) errs.address = 'Kötelező mező.';
  }
  if (!form.terms.checked) errs.terms = 'A rendeléshez fogadd el a feltételeket.';

  $$('.field', form).forEach((f) => f.classList.remove('invalid'));
  $$('.field-err', form).forEach((e) => { e.textContent = ''; });
  Object.entries(errs).forEach(([n, msg]) => {
    const input = form[n];
    $(`#e-${n}`).textContent = msg;
    input?.closest('.field')?.classList.add('invalid');
    input?.setAttribute('aria-invalid', 'true');
    input?.setAttribute('aria-describedby', `e-${n}`);
  });
  const first = Object.keys(errs)[0];
  if (first) form[first]?.focus();
  return !first;
}

document.addEventListener('change', (e) => {
  if (e.target.name === 'shipping' || e.target.name === 'payment') {
    saveDraft();
    choice[e.target.name] = e.target.value;
    render().then(() => $(`input[name="${e.target.name}"][value="${e.target.value}"]`)?.focus());
  }
});
document.addEventListener('cart:change', () => { if (ordered) return; saveDraft(); render(); });

document.addEventListener('submit', async (e) => {
  if (!e.target.matches('[data-form]')) return;
  e.preventDefault();
  const form = e.target;
  if (!validate(form)) return;
  const { subtotal, freeShipping } = await cartDetails();
  const { total } = totals(subtotal, freeShipping);
  const orderNo = `MND-${Date.now().toString().slice(-6)}`;
  // Éles üzemben itt a WooCommerce Store API /checkout végpontja hívódik.
  const email = form.email.value.trim();
  ordered = true;
  cart.clear();
  root.innerHTML = `<div class="container confirm">
    <div class="seal">${logoMark}</div>
    <p class="eyebrow" style="justify-content:center">Köszönjük a rendelést</p>
    <h1 style="font-size:clamp(2.4rem,5vw,3.4rem)">Úton van hozzád egy kis csend</h1>
    <p class="lead" style="margin:0 auto 1rem">Rendelésszám: <strong>${orderNo}</strong> · Fizetendő: <strong>${fmt(total)}</strong></p>
    <p class="muted">A visszaigazolást a(z) <strong>${esc(email)}</strong> címre küldjük. Ha kérdésed van, válaszolj arra a levélre.</p>
    <div class="hero-cta" style="justify-content:center"><a class="btn btn-primary" href="termekek.html">Vásárlás folytatása</a><a class="btn btn-ghost" href="magazin.html">Olvass a magazinban</a></div>
  </div>`;
  scrollTo({ top: 0, behavior: 'smooth' });
});

await render();
if (location.hash === '#penztar') $('#penztar')?.scrollIntoView();
