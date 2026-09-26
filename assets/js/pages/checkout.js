// Pénztár – [woocommerce_checkout], klasszikus WooCommerce markup.
// Az űrlap egyszer renderelődik; a módválasztások csak a függő részeket frissítik,
// így gépelés közben nem ugrik a fókusz és nem vesznek el az adatok.
import { initPage, $, $$, esc, icon, productMedia, modal, closeLayer, bindValidation, validateForm, showFieldState, fieldError, normPhone } from '../ui.js';
import { CONFIG } from '../data.js';
import { totals, coupon, fmt, storage, KEYS, orders, cart } from '../store.js';

initPage({ variant: 'checkout' });
const root = $('[data-checkout]');

// Irányítószám → település (minta; élesben teljes KSH-lista vagy a szállítási bővítmény adja)
const ZIPS = { 2000: 'Szentendre', 2040: 'Budaörs', 2100: 'Gödöllő', 3300: 'Eger', 3525: 'Miskolc', 4024: 'Debrecen', 4025: 'Debrecen', 6000: 'Kecskemét', 6720: 'Szeged', 6724: 'Szeged', 7621: 'Pécs', 7622: 'Pécs', 8000: 'Székesfehérvár', 8200: 'Veszprém', 9021: 'Győr', 9027: 'Győr', 9400: 'Sopron' };
const cityOf = (zip) => (/^1\d{3}$/.test(zip) ? 'Budapest' : ZIPS[zip] || '');

const draft = { shipping: 'gls', payment: 'barion', ...storage.get(KEYS.draft, {}) };
const saveDraft = () => storage.set(KEYS.draft, draft);

const V = {
  req: (m) => `required|${m}`,
  email: 'required|Add meg az e-mail-címed – ide küldjük a visszaigazolást.\nemail|Ez nem tűnik érvényes e-mail-címnek (pl. nev@pelda.hu).',
  phone: 'required|Add meg a telefonszámod – a futár ezen ér el.\nphone|Magyar telefonszámot adj meg, pl. +36 30 123 4567.',
  zip: 'required|Add meg az irányítószámot.\nzip|Négyjegyű magyar irányítószámot adj meg.',
};
function row({ id, label, type = 'text', auto = '', validate = '', cls = 'form-row-wide', hint = '', placeholder = '', extra = '' }) {
  const req = /required/.test(validate);
  const hintId = hint ? `${id}-hint` : '';
  return `<p class="form-row ${cls} ${req ? 'validate-required' : ''}" id="${id}_field">
    <label for="${id}">${label}${req ? '&nbsp;<abbr class="required" title="kötelező">*</abbr>' : ' <span class="optional">(nem kötelező)</span>'}</label>
    <span class="woocommerce-input-wrapper"><input type="${type}" class="input-text" name="${id}" id="${id}" ${auto ? `autocomplete="${auto}"` : ''} ${placeholder ? `placeholder="${esc(placeholder)}"` : ''} ${validate ? `data-validate="${esc(validate)}"` : ''} ${req ? 'aria-required="true"' : ''} ${hintId ? `data-hint="${hintId}" aria-describedby="${hintId}"` : ''} value="${esc(draft[id] || '')}" ${extra}>
    ${hint ? `<span class="field-hint" id="${hintId}">${esc(hint)}</span>` : ''}</span></p>`;
}
const checkbox = (id, label, { validate = '', checked = draft[id], short = '' } = {}) => `<p class="form-row form-row-wide check-row" id="${id}_field">
  <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="${id}"><input type="checkbox" class="woocommerce-form__input input-checkbox" name="${id}" id="${id}" ${checked ? 'checked' : ''} ${validate ? `data-validate="${esc(validate)}" aria-required="true"` : ''} ${short ? `data-label="${esc(short)}"` : ''}> <span>${label}</span></label></p>`;

function methodIcon(name) { return `<span class="method-icon" aria-hidden="true">${icon(name)}</span>`; }

async function start() {
  const t0 = await totals();
  if (!t0.items.length) {
    root.innerHTML = `<div class="woocommerce-info" role="status">${icon('info')}<span>A kosarad üres, ezért nincs mit kifizetni. <a href="termekek.html">Vissza a kínálathoz</a></span></div>`;
    return;
  }

  root.innerHTML = `<form name="checkout" method="post" class="checkout woocommerce-checkout" novalidate aria-label="Pénztár">
  <div class="checkout-main" id="customer_details">
    <div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" tabindex="-1" data-notices></div>

    <section class="checkout-section" id="section-contact" aria-labelledby="h-contact">
      <h3 id="h-contact"><span class="step-dot">1</span>Elérhetőség<a class="section-aside" href="fiok.html">Van fiókod? Belépés</a></h3>
      <div class="field-wrapper">
        ${row({ id: 'billing_email', label: 'E-mail-cím', type: 'email', auto: 'email', validate: V.email, cls: 'form-row-first', hint: 'Ide küldjük a visszaigazolást és a csomagkövetést.' })}
        ${row({ id: 'billing_phone', label: 'Telefonszám', type: 'tel', auto: 'tel', validate: V.phone, cls: 'form-row-last', placeholder: '+36 30 123 4567', extra: 'data-format="phone" inputmode="tel"' })}
      </div>
    </section>

    <section class="checkout-section" id="section-shipping" aria-labelledby="h-shipping">
      <h3 id="h-shipping"><span class="step-dot">2</span>Szállítási mód</h3>
      <fieldset style="border:0;margin:0;padding:0"><legend class="sr-only">Szállítási mód</legend>
      <ul id="shipping_method" class="woocommerce-shipping-methods">
        ${CONFIG.shipping.map((s) => `<li class="method" data-method="${s.id}"><label for="shipping_method_${s.id}">
          <input type="radio" name="shipping_method[0]" id="shipping_method_${s.id}" value="${s.id}" class="shipping_method" data-wc="${s.wc}" ${draft.shipping === s.id ? 'checked' : ''}>
          ${methodIcon(s.icon)}<span class="method-text"><strong>${esc(s.label)}</strong><small>${esc(s.note)}</small></span>
          <span class="method-price" data-ship-price="${s.id}"></span></label>
          <div class="method-extra" data-ship-extra="${s.id}" style="padding:0 var(--space-5) var(--space-5)" hidden></div></li>`).join('')}
      </ul></fieldset>
    </section>

    <section class="checkout-section woocommerce-billing-fields" id="section-billing" aria-labelledby="h-billing">
      <h3 id="h-billing"><span class="step-dot">3</span><span data-billing-title>Szállítási és számlázási cím</span></h3>
      <div class="woocommerce-billing-fields__field-wrapper">
        ${row({ id: 'billing_last_name', label: 'Vezetéknév', auto: 'family-name', validate: V.req('Add meg a vezetékneved.'), cls: 'form-row-first' })}
        ${row({ id: 'billing_first_name', label: 'Keresztnév', auto: 'given-name', validate: V.req('Add meg a keresztneved.'), cls: 'form-row-last' })}
        <div class="toggle-row">${checkbox('is_company', 'Cégként vásárolok (ÁFÁ-s számla cégnévre)').replace('form-row form-row-wide check-row', 'check-row')}</div>
        <div class="reveal-fields" data-company hidden>
          ${row({ id: 'billing_company', label: 'Cégnév', auto: 'organization', validate: V.req('Add meg a cég nevét.'), cls: 'form-row-wide' })}
          ${row({ id: 'billing_tax_number', label: 'Adószám', validate: 'required|Céges számlához add meg az adószámot.\ntaxno|Ez nem érvényes magyar adószám (8-1-2 számjegy).', cls: 'form-row-wide', placeholder: '12345676-2-41', hint: 'A számjegyeket is ellenőrizzük, hogy a számla biztosan jó legyen.', extra: 'data-format="taxno" inputmode="numeric"' })}
        </div>
        <p class="form-row form-row-wide"><span class="field-label">Ország</span><span class="text-muted" style="font-size:.9375rem">Magyarország <span class="text-small">– jelenleg belföldre szállítunk</span></span></p>
        ${row({ id: 'billing_postcode', label: 'Irányítószám', auto: 'postal-code', validate: V.zip, cls: 'form-row-third zip-row', extra: 'inputmode="numeric" maxlength="4"' })}
        ${row({ id: 'billing_city', label: 'Település', auto: 'address-level2', validate: V.req('Add meg a települést.'), cls: 'form-row-twothird city-row' })}
        ${row({ id: 'billing_address_1', label: 'Utca, házszám', auto: 'address-line1', validate: V.req('Add meg az utcát és a házszámot.'), cls: 'form-row-twothird', placeholder: 'pl. Király utca 12.' })}
        ${row({ id: 'billing_address_2', label: 'Emelet, ajtó', auto: 'address-line2', cls: 'form-row-third' })}
        <div class="toggle-row" data-ship-diff-row>${checkbox('ship_to_different_address', 'Másik címre kérem a szállítást').replace('form-row form-row-wide check-row', 'check-row')}</div>
        <div class="reveal-fields woocommerce-shipping-fields" data-ship-diff hidden>
          <p class="field-hint">A futár erre a címre viszi a csomagot; a számla a fenti címre szól.</p>
          ${row({ id: 'shipping_last_name', label: 'Vezetéknév', auto: 'shipping family-name', validate: V.req('Add meg a címzett vezetéknevét.'), cls: 'form-row-first' })}
          ${row({ id: 'shipping_first_name', label: 'Keresztnév', auto: 'shipping given-name', validate: V.req('Add meg a címzett keresztnevét.'), cls: 'form-row-last' })}
          ${row({ id: 'shipping_postcode', label: 'Irányítószám', auto: 'shipping postal-code', validate: V.zip, cls: 'form-row-third zip-row', extra: 'inputmode="numeric" maxlength="4"' })}
          ${row({ id: 'shipping_city', label: 'Település', auto: 'shipping address-level2', validate: V.req('Add meg a települést.'), cls: 'form-row-twothird city-row' })}
          ${row({ id: 'shipping_address_1', label: 'Utca, házszám', auto: 'shipping address-line1', validate: V.req('Add meg az utcát és a házszámot.'), cls: 'form-row-twothird' })}
          ${row({ id: 'shipping_address_2', label: 'Emelet, ajtó', auto: 'shipping address-line2', cls: 'form-row-third' })}
        </div>
      </div>
    </section>

    <section class="checkout-section woocommerce-checkout-payment" id="payment" aria-labelledby="h-payment">
      <h3 id="h-payment"><span class="step-dot">4</span>Fizetés<span class="section-aside text-muted">${icon('lock', 'ico ico-s')} Titkosított</span></h3>
      <fieldset style="border:0;margin:0;padding:0"><legend class="sr-only">Fizetési mód</legend>
      <ul class="wc_payment_methods payment_methods methods">
        ${CONFIG.payment.map((p) => `<li class="wc_payment_method payment_method_${p.id} method" data-pay="${p.id}"><label for="payment_method_${p.id}">
          <input id="payment_method_${p.id}" type="radio" class="input-radio" name="payment_method" value="${p.id}" ${draft.payment === p.id ? 'checked' : ''}>
          ${methodIcon({ barion: 'card', bacs: 'bank', cod: 'cash' }[p.id])}<span class="method-text"><strong data-pay-label="${p.id}">${esc(p.label)}</strong><small data-pay-note="${p.id}">${esc(p.note)}</small></span>
          <span class="method-price" data-pay-fee="${p.id}"></span></label>
          <div class="payment_box payment_method_${p.id}" data-pay-box="${p.id}" hidden>${p.id === 'barion'
            ? `<p>A „Megrendelés” gomb után a Barion biztonságos fizetési oldalára irányítunk. Kártyaadataidat mi nem látjuk és nem tároljuk.</p><div class="pay-marks" style="justify-content:flex-start;margin-top:var(--space-3)">${p.marks.map((m) => `<span>${m}</span>`).join('')}</div>`
            : p.id === 'bacs' ? '<p>A visszaigazolásban és a köszönő oldalon megadjuk a bankszámlaszámot; közleménynek a rendelésszámot írd. A csomagot a jóváírás után adjuk fel (általában 1 munkanap).</p>'
            : '<p data-cod-box></p>'}</div></li>`).join('')}
      </ul></fieldset>
    </section>

    <section class="checkout-section" id="section-review" aria-labelledby="h-review">
      <h3 id="h-review"><span class="step-dot">5</span>Megrendelés</h3>
      <div class="woocommerce-additional-fields" style="margin-bottom:var(--space-5)">
        <details class="review-coupon" style="border:0;padding:0" ${draft.order_comments ? 'open' : ''}><summary>Megjegyzés vagy ajándékkártya szövege ${icon('chevron', 'ico ico-s')}</summary>
          <p class="form-row notes" id="order_comments_field" style="margin-top:var(--space-3)"><label for="order_comments" class="sr-only">Megjegyzés a rendeléshez</label>
          <span class="woocommerce-input-wrapper"><textarea name="order_comments" class="input-text" id="order_comments" rows="3" maxlength="500" placeholder="Pl. csengő nem működik; vagy a kártyára írandó üzenet">${esc(draft.order_comments || '')}</textarea></span></p>
        </details>
      </div>
      <div class="woocommerce-terms-and-conditions-wrapper">
        ${checkbox('createaccount', 'Fiókot hozok létre a rendelés követéséhez')}
        <div class="reveal-fields" data-account hidden style="margin:0">${row({ id: 'account_password', label: 'Jelszó', type: 'password', auto: 'new-password', validate: 'required|Adj meg egy jelszót a fiókhoz.\nmin8|Legalább 8 karakter legyen.', cls: 'form-row-wide', hint: 'Legalább 8 karakter.' })}</div>
        ${checkbox('newsletter', 'Feliratkozom a hírlevélre (havonta kétszer, bármikor leiratkozhatok)')}
        ${checkbox('terms', 'Elolvastam és elfogadom az <a href="jogi.html?d=aszf" target="_blank" rel="noopener">ÁSZF-et</a> és az <a href="jogi.html?d=adatkezeles" target="_blank" rel="noopener">adatkezelési tájékoztatót</a>, és tudomásul veszem a 14 napos elállási jogra vonatkozó tájékoztatást.', { validate: 'required|A megrendeléshez el kell fogadnod az ÁSZF-et és az adatkezelési tájékoztatót.', checked: false, short: 'ÁSZF elfogadása' })}
      </div>
      <div class="form-row place-order" style="margin-top:var(--space-5)">
        <button type="submit" class="iu-button iu-button-large alt" name="woocommerce_checkout_place_order" id="place_order">Fizetési kötelezettséggel járó megrendelés</button>
        <p class="place-order-note" data-place-note></p>
      </div>
    </section>
  </div>

  <aside class="checkout-aside" aria-labelledby="order_review_heading" data-aside>
    <button type="button" class="mobile-summary-toggle" aria-expanded="false" aria-controls="order_review" data-summary-toggle><span>${icon('bag', 'ico ico-s')} Rendelés összesítő <span class="text-muted" data-summary-count></span></span><strong class="num" data-summary-total></strong></button>
    <div class="summary-body">
      <h2 id="order_review_heading">Rendelésed</h2>
      <div id="order_review" class="woocommerce-checkout-review-order" data-review></div>
      <ul class="trust-mini"><li>${icon('lock', 'ico ico-s')}Biztonságos, titkosított fizetés</li><li>${icon('return', 'ico ico-s')}14 napos visszaküldés</li><li>${icon('phone', 'ico ico-s')}Segítség: <a href="tel:${CONFIG.contact.phone.replace(/\s/g, '')}">${CONFIG.contact.phone}</a></li></ul>
    </div>
  </aside>
</form>`;

  const form = $('form.checkout', root);
  bindValidation(form);
  bindEvents(form);
  syncConditional();
  await refreshDynamic();
  if (location.hash === '#payment') $('#payment').scrollIntoView();
}

// ---------- Függő részek ----------
function syncConditional() {
  const ship = draft.shipping;
  $('[data-company]').hidden = !draft.is_company;
  $('[data-account]').hidden = !draft.createaccount;
  const courier = ship === 'gls';
  $('[data-ship-diff-row]').hidden = !courier;
  $('[data-ship-diff]').hidden = !(courier && draft.ship_to_different_address);
  $('[data-billing-title]').textContent = courier ? 'Szállítási és számlázási cím' : 'Számlázási adatok';
  $$('[data-ship-extra]').forEach((el) => { el.hidden = el.dataset.shipExtra !== ship || ship === 'gls'; });
  const fox = $('[data-ship-extra="foxpost"]');
  const locker = CONFIG.lockers.find((l) => l.id === draft.locker);
  fox.innerHTML = `<div class="pickup-point ${fox.dataset.invalid ? 'is-invalid' : ''}" id="foxpost_point" tabindex="-1">${icon('locker', 'ico ico-l')}
    <span>${locker ? `<strong>${esc(locker.name)}</strong>${esc(locker.address)} · Nyitva: ${esc(locker.hours)}` : `<strong>Még nem választottál automatát</strong>${fox.dataset.invalid ? '<span style="color:var(--c-error)">Válaszd ki, melyik automatába kéred a csomagot.</span>' : 'Keresés település vagy irányítószám szerint.'}`}</span>
    <button type="button" class="iu-button ${locker ? 'iu-button-outline' : ''}" data-pick-locker>${locker ? 'Módosítás' : 'Automata választása'}</button></div>`;
  $('[data-ship-extra="pickup"]').innerHTML = `<div class="pickup-point">${icon('store', 'ico ico-l')}<span><strong>Mandala bemutatóterem, Budapest</strong>${esc(CONFIG.contact.address)} · ${esc(CONFIG.contact.hours)}<br>E-mailben értesítünk, amikor átvehető (általában 1 munkanap).</span><span></span></div>`;
  // Utánvét felirata: személyes átvételnél „Fizetés átvételkor”, díj nélkül
  const cod = CONFIG.payment.find((p) => p.id === 'cod');
  const pickup = ship === 'pickup';
  $('[data-pay-label="cod"]').textContent = pickup ? cod.pickupLabel : cod.label;
  $('[data-pay-note="cod"]').textContent = pickup ? cod.pickupNote : cod.note;
  $('[data-cod-box]').textContent = pickup ? 'A bemutatóteremben készpénzzel vagy bankkártyával fizethetsz.' : `Utánvét díja: ${fmt(cod.fee)}. ${ship === 'foxpost' ? 'Az automatánál bankkártyával fizethetsz.' : 'A futárnál készpénzzel vagy bankkártyával fizethetsz.'}`;
  $('[data-pay-fee="cod"]').innerHTML = pickup ? '' : `+${fmt(cod.fee)}`;
  $$('[data-pay-box]').forEach((b) => { b.hidden = b.dataset.payBox !== draft.payment; });
  $('[data-place-note]').textContent = draft.payment === 'barion' ? 'A gomb megnyomása után a Barion fizetési oldalára irányítunk.' : draft.payment === 'bacs' ? 'A banki adatokat a következő oldalon és e-mailben is megkapod.' : 'A megrendelés után e-mailben visszaigazoljuk a rendelést.';
}

async function refreshDynamic() {
  const t = await totals({ shipping: draft.shipping, payment: draft.payment });
  CONFIG.shipping.forEach((s) => {
    const free = t.freeShipping && s.free && s.price;
    $(`[data-ship-price="${s.id}"]`).innerHTML = !s.price ? '<span class="is-free">Ingyenes</span>' : free ? `<s>${fmt(s.price)}</s><span class="is-free">Ingyenes</span>` : fmt(s.price);
    $(`[data-ship-price="${s.id}"]`).className = `method-price ${!s.price || free ? 'is-free' : ''}`;
  });
  const ship = CONFIG.shipping.find((s) => s.id === draft.shipping);
  const couponOpen = $('[data-coupon-details]')?.open || false;
  const active = document.activeElement?.id;
  $('[data-review]').innerHTML = `
    <ul class="review-items" aria-label="Termékek">${t.items.map(({ product: p, qty }) => `<li>
      <span class="thumb">${productMedia(p)}<span class="qty-badge" aria-label="${qty} darab">${qty}</span></span>
      <span><span class="name">${esc(p.name)}</span><small>${qty} × ${fmt(p.price)}</small></span><strong class="num">${fmt(p.price * qty)}</strong></li>`).join('')}</ul>
    <p style="margin:calc(-1 * var(--space-2)) 0 var(--space-3);text-align:right"><a class="text-small" href="kosar.html">Kosár módosítása</a></p>
    <details class="review-coupon" data-coupon-details ${couponOpen ? 'open' : ''}><summary>${t.code ? `Kupon: ${esc(t.code)}` : 'Van kuponkódod?'} ${icon('chevron', 'ico ico-s')}</summary>
      <div class="coupon" data-coupon>${t.code
        ? `<span class="coupon-applied">${icon('check', 'ico ico-s')} ${esc(CONFIG.coupons[t.code].label)}<button type="button" data-coupon-remove aria-label="Kupon eltávolítása">${icon('close', 'ico ico-s')}</button></span>`
        : '<label class="sr-only" for="checkout_coupon">Kuponkód</label><input type="text" class="input-text" id="checkout_coupon" placeholder="Kuponkód" autocomplete="off"><button type="button" class="iu-button iu-button-outline" data-coupon-apply>Beváltás</button><p class="field-error" id="checkout_coupon-error" role="status"></p>'}</div></details>
    <table class="totals-table shop_table woocommerce-checkout-review-order-table"><tbody>
      <tr class="cart-subtotal"><th>Részösszeg</th><td>${fmt(t.subtotal)}</td></tr>
      ${t.discount ? `<tr class="discount cart-discount"><th>Kedvezmény</th><td>−${fmt(t.discount)}</td></tr>` : ''}
      <tr class="woocommerce-shipping-totals shipping"><th>Szállítás<span class="includes_tax">${esc(ship?.label || 'Válassz szállítási módot')}</span></th><td>${t.shippingCost === null ? '–' : t.shippingCost ? fmt(t.shippingCost) : 'Ingyenes'}</td></tr>
      ${t.fee ? `<tr class="fee"><th>Utánvét díja</th><td>${fmt(t.fee)}</td></tr>` : ''}
      <tr class="order-total"><th>Fizetendő</th><td>${fmt(t.total)}<small class="includes_tax">Tartalmaz ${fmt(t.vat)} ÁFA-t (${CONFIG.vatRate}%)</small></td></tr>
    </tbody></table>`;
  if (active === 'checkout_coupon') $('#checkout_coupon')?.focus();
  $('[data-summary-total]').textContent = fmt(t.total);
  $('[data-summary-count]').textContent = `(${t.count} db)`;
  $('#place_order').innerHTML = `Fizetési kötelezettséggel járó megrendelés · <span class="num">${fmt(t.total)}</span>`;
  // Kész szakaszok jelölése
  const done = (sel) => $$(`${sel} [data-validate]`).filter((el) => !el.closest('[hidden]')).every((el) => !fieldError(el));
  $('#section-contact').classList.toggle('is-complete', done('#section-contact'));
  $('#section-billing').classList.toggle('is-complete', done('#section-billing'));
  $('#section-shipping').classList.toggle('is-complete', draft.shipping !== 'foxpost' || !!draft.locker);
  return t;
}

// ---------- Foxpost automata választó (élesben a szállítási bővítmény térképes választója) ----------
function pickLocker() {
  const listHtml = (q) => {
    const t = q.trim().toLowerCase();
    const hits = CONFIG.lockers.filter((l) => !t || `${l.name} ${l.address}`.toLowerCase().includes(t));
    return hits.length ? hits.map((l) => `<li><button type="button" data-locker="${l.id}" aria-pressed="${draft.locker === l.id}">${icon('locker')}<span><strong>${esc(l.name)}</strong><small>${esc(l.address)}</small></span><small>${esc(l.hours)}</small></button></li>`).join('')
      : '<li class="text-muted">Nincs találat. Próbáld a település nevével vagy irányítószámmal.</li>';
  };
  const m = modal({ id: 'locker-modal', title: 'Foxpost automata választása', body: `<label class="field-label" for="locker-q">Település, irányítószám vagy cím</label>
    <input class="input-text" id="locker-q" type="search" placeholder="pl. Budapest, 6724, Árkád" style="margin-top:var(--space-2)" value="${esc(draft.billing_city || '')}">
    <ul class="locker-list" data-locker-list aria-live="polite">${listHtml(draft.billing_city || '')}</ul>
    <p class="field-hint" style="margin-top:var(--space-4)">Minta lista – élesben a Foxpost bővítmény teljes, térképes automatalistája jelenik meg.</p>` });
  $('#locker-q', m).addEventListener('input', (e) => { $('[data-locker-list]', m).innerHTML = listHtml(e.target.value); });
  m.addEventListener('click', (e) => {
    const b = e.target.closest('[data-locker]');
    if (!b) return;
    draft.locker = b.dataset.locker;
    saveDraft();
    delete $('[data-ship-extra="foxpost"]').dataset.invalid;
    closeLayer(m);
    syncConditional();
    refreshDynamic();
    setTimeout(() => $('[data-pick-locker]')?.focus(), 300);
  });
}

// ---------- Események ----------
function bindEvents(form) {
  form.addEventListener('input', (e) => {
    const el = e.target;
    if (!el.name || el.type === 'radio') return;
    draft[el.name] = el.type === 'checkbox' ? el.checked : el.value;
    if (el.name.endsWith('_postcode') && /^\d{4}$/.test(el.value)) {
      const cityEl = $(`#${el.name.replace('postcode', 'city')}`);
      const city = cityOf(el.value);
      if (city && (!cityEl.value || cityEl.dataset.auto)) {
        cityEl.value = city; cityEl.dataset.auto = '1';
        draft[cityEl.name] = city;
        showFieldState(cityEl, '');
      }
    }
    if (el.name.endsWith('_city')) delete el.dataset.auto;
    if (el.name !== 'terms') saveDraft();
  });
  form.addEventListener('change', (e) => {
    const el = e.target;
    if (el.name === 'shipping_method[0]') { draft.shipping = el.value; saveDraft(); syncConditional(); refreshDynamic(); }
    else if (el.name === 'payment_method') { draft.payment = el.value; saveDraft(); syncConditional(); refreshDynamic(); }
    else if (['is_company', 'ship_to_different_address', 'createaccount'].includes(el.name)) {
      draft[el.name] = el.checked; saveDraft(); syncConditional();
      if (el.checked) $(`${{ is_company: '[data-company]', ship_to_different_address: '[data-ship-diff]', createaccount: '[data-account]' }[el.name]} input`)?.focus();
    } else if (el.matches('[data-validate]')) refreshDynamic();
  });
  form.addEventListener('focusout', (e) => { if (e.target.matches?.('[data-validate]')) setTimeout(refreshDynamic, 0); });
  form.addEventListener('click', async (e) => {
    if (e.target.closest('[data-pick-locker]')) { pickLocker(); return; }
    if (e.target.closest('[data-coupon-apply]')) { await applyCoupon(); return; }
    if (e.target.closest('[data-coupon-remove]')) { coupon.remove(); await refreshDynamic(); $('[data-coupon-details] summary')?.focus(); return; }
    if (e.target.closest('[data-summary-toggle]')) {
      const aside = $('[data-aside]');
      const open = !aside.classList.contains('is-open');
      aside.classList.toggle('is-open', open);
      e.target.closest('[data-summary-toggle]').setAttribute('aria-expanded', String(open));
    }
    const link = e.target.closest('[data-notices] a[href^="#"]');
    if (link) { e.preventDefault(); const target = $(link.getAttribute('href')); target?.scrollIntoView({ behavior: 'smooth', block: 'center' }); target?.focus({ preventScroll: true }); }
  });
  form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'checkout_coupon') { e.preventDefault(); applyCoupon(); } });
  form.addEventListener('submit', (e) => { e.preventDefault(); placeOrder(form); });
  document.addEventListener('cart:change', refreshDynamic);
}

async function applyCoupon() {
  const t = await totals();
  const res = coupon.apply($('#checkout_coupon').value, t.subtotal);
  if (!res.ok) {
    const err = $('#checkout_coupon-error');
    err.innerHTML = `${icon('alert', 'ico ico-s')}<span>${esc(res.message)}</span>`;
    $('#checkout_coupon').setAttribute('aria-invalid', 'true');
    $('#checkout_coupon').setAttribute('aria-describedby', 'checkout_coupon-error');
    $('#checkout_coupon').focus();
    return;
  }
  await refreshDynamic();
  $('[data-coupon-details]').open = true;
  $('[data-coupon-remove]')?.focus();
}

// ---------- Megrendelés ----------
async function placeOrder(form) {
  const notices = $('[data-notices]');
  const errors = validateForm(form);
  const fox = $('[data-ship-extra="foxpost"]');
  if (draft.shipping === 'foxpost' && !draft.locker) {
    fox.dataset.invalid = '1';
    syncConditional();
    errors.splice(1, 0, { el: $('#foxpost_point'), label: 'Foxpost automata', msg: 'Válaszd ki, melyik automatába kéred a csomagot.' });
  }
  if (errors.length) {
    notices.innerHTML = `<div class="woocommerce-error" role="alert"><strong>${icon('alert')} ${errors.length === 1 ? 'Egy adatot még javítani kell:' : `${errors.length} adatot még javítani kell:`}</strong>
      <ul>${errors.map((x) => `<li><a href="#${x.el.id}">${esc(x.label)}</a>: ${esc(x.msg)}</li>`).join('')}</ul></div>`;
    notices.scrollIntoView({ behavior: 'smooth', block: 'start' });
    notices.focus({ preventScroll: true });
    return;
  }
  notices.innerHTML = '';
  const btn = $('#place_order');
  btn.classList.add('is-loading'); btn.disabled = true;
  form.setAttribute('aria-busy', 'true');
  form.style.opacity = '.6';

  const t = await totals({ shipping: draft.shipping, payment: draft.payment });
  const number = String(Math.floor(10000 + Math.random() * 89999));
  const pay = CONFIG.payment.find((p) => p.id === draft.payment);
  const ship = CONFIG.shipping.find((s) => s.id === draft.shipping);
  const f = (k) => (draft[k] || '').trim();
  const order = {
    number, key: `wc_order_${Math.random().toString(36).slice(2, 12)}`, date: new Date().toISOString(),
    status: draft.payment === 'barion' ? 'processing' : 'on-hold',
    email: f('billing_email'), phone: normPhone(f('billing_phone')) || f('billing_phone'),
    billing: { name: `${f('billing_last_name')} ${f('billing_first_name')}`, first: f('billing_first_name'), company: draft.is_company ? f('billing_company') : '', tax: draft.is_company ? f('billing_tax_number') : '', address: `${f('billing_postcode')} ${f('billing_city')}, ${f('billing_address_1')}${f('billing_address_2') ? `, ${f('billing_address_2')}` : ''}` },
    shipping: { method: ship.id, label: ship.label, locker: draft.shipping === 'foxpost' ? CONFIG.lockers.find((l) => l.id === draft.locker) : null,
      address: draft.shipping !== 'gls' ? '' : draft.ship_to_different_address
        ? `${f('shipping_last_name')} ${f('shipping_first_name')}, ${f('shipping_postcode')} ${f('shipping_city')}, ${f('shipping_address_1')}${f('shipping_address_2') ? `, ${f('shipping_address_2')}` : ''}` : '' },
    payment: { id: pay.id, label: draft.shipping === 'pickup' && pay.id === 'cod' ? pay.pickupLabel : pay.label },
    items: t.items.map(({ product: p, qty }) => ({ id: p.id, name: p.name, sku: p.sku, qty, price: p.price })),
    totals: { subtotal: t.subtotal, discount: t.discount, code: t.code, shipping: t.shippingCost, fee: t.fee, total: t.total, vat: t.vat },
    notes: f('order_comments'), account: !!draft.createaccount, newsletter: !!draft.newsletter,
  };
  // Élesben: a WooCommerce checkout végpont (wc-ajax=checkout) ‒ Barionnál átirányítás a fizetési oldalra.
  setTimeout(() => {
    orders.save(order);
    cart.clear();
    storage.set(KEYS.draft, { shipping: draft.shipping, payment: draft.payment });
    location.href = `koszonjuk.html?order=${number}&key=${order.key}`;
  }, draft.payment === 'barion' ? 1400 : 900);
}

start();
