// Pénztár (klasszikus WooCommerce): mezőnkénti validáció, formázás, cégként vásárlás,
// irányítószám → település, kupon az összesítőben, fizetési módtól függő díj frissítése,
// hibaösszesítő, mobil összesítő. A frissítéseket a WooCommerce checkout.js végzi.
import { $, $$, esc, icon, fmt } from './env.js';
import { bindValidation, validateForm, showFieldState, countryOf } from './validate.js';
import { endpointOf, isElinBlock, isFailure, checkoutTroubleHtml, orderPlacedHtml, contactOf, contactHtml, cartHash, orderState, sleep, wcPost } from './net.js';

const form = $('form.checkout.mandala-checkout');
const jq = window.jQuery;
const M = window.MANDALA || {};

if (form) {
  bindValidation(form);
  const $body = jq?.(document.body);
  const update = () => $body?.trigger('update_checkout');

  // Irányítószám → település (Budapest + nagyvárosok; élesben bővíthető teljes KSH-listára)
  const ZIPS = { 2000: 'Szentendre', 2040: 'Budaörs', 2100: 'Gödöllő', 3300: 'Eger', 3525: 'Miskolc', 4024: 'Debrecen', 4025: 'Debrecen', 6000: 'Kecskemét', 6720: 'Szeged', 6724: 'Szeged', 7621: 'Pécs', 7622: 'Pécs', 8000: 'Székesfehérvár', 8200: 'Veszprém', 9021: 'Győr', 9027: 'Győr', 9400: 'Sopron' };
  const cityOf = (zip) => (/^1\d{3}$/.test(zip) ? 'Budapest' : ZIPS[zip] || '');
  form.addEventListener('input', (e) => {
    const el = e.target;
    if (el.name?.endsWith('_postcode') && /^\d{4}$/.test(el.value) && countryOf(el) === 'HU') {
      const cityEl = $(`#${el.name.replace('postcode', 'city')}`);
      const city = cityOf(el.value);
      if (cityEl && city && (!cityEl.value || cityEl.dataset.auto)) { cityEl.value = city; cityEl.dataset.auto = '1'; showFieldState(cityEl, ''); }
    }
    if (el.name?.endsWith('_city')) delete el.dataset.auto;
  });

  // Külföldi cím (EU-s szállításnál): irányítószám hossza, adószám címkéje
  function syncCountry() {
    const hu = ($('#billing_country')?.value || 'HU') === 'HU';
    ['#billing_postcode', '#shipping_postcode'].forEach((sel) => {
      const el = $(sel);
      if (!el) return;
      const huZip = countryOf(el) === 'HU';
      if (huZip) { el.maxLength = 4; el.inputMode = 'numeric'; } else { el.removeAttribute('maxlength'); el.inputMode = 'text'; }
    });
    const tax = $('#billing_tax_number');
    const label = $('label[for="billing_tax_number"]');
    if (tax && label) {
      label.firstChild.textContent = hu ? 'Adószám ' : 'Közösségi adószám (EU VAT) ';
      tax.placeholder = hu ? '12345676-2-41' : 'ATU12345678';
      tax.inputMode = hu ? 'numeric' : 'text';
    }
  }
  $body?.on('country_to_state_changed', syncCountry);
  syncCountry();

  // Cégként vásárlás
  const company = $('#is_company');
  // A WooCommerce címmező-szkriptje (address-i18n) országváltáskor prioritás szerint újrarendezi
  // a mezőket, és kiveszi őket a [data-company] dobozból – ezért a sorokat közvetlenül rejtjük.
  const syncCompany = () => {
    const hide = !company?.checked;
    const box = $('[data-company]');
    if (box) box.hidden = hide;
    ['#billing_company_field', '#billing_tax_number_field'].forEach((sel) => { const row = $(sel); if (row) row.hidden = hide; });
  };
  $body?.on('country_to_state_changed updated_checkout', syncCompany);
  company?.addEventListener('change', () => { syncCompany(); if (company.checked) $('#billing_company')?.focus(); });
  syncCompany();

  // Szállítási módtól függő szövegek (futár: cím; automata/átvétel: számlázási adatok)
  function syncShipping() {
    const chosen = $('input.shipping_method:checked')?.value || $('input.shipping_method[type="hidden"]')?.value || '';
    // A típust a szerver adja (data-kind: courier | point | pickup) – a GLS bővítmény módjaitól független.
    const kind = $('input.shipping_method:checked')?.closest('[data-kind]')?.dataset.kind || (/^local_pickup/.test(chosen) ? 'pickup' : 'courier');
    const pickupOrLocker = kind !== 'courier';
    const title = $('[data-billing-title]');
    if (title) title.textContent = pickupOrLocker ? 'Számlázási adatok' : 'Szállítási és számlázási cím';
    const diff = $('[data-ship-diff-row]');
    if (diff) diff.hidden = pickupOrLocker;
    if (pickupOrLocker && $('#ship-to-different-address-checkbox')?.checked) $('#ship-to-different-address-checkbox').click();
    $('#section-shipping')?.classList.toggle('is-complete', !!chosen);
  }

  // Fizetési mód: díj (utánvét) és gomb melletti összeg frissítése
  $body?.on('payment_method_selected', update);
  function placeNote() {
    const method = $('input[name="payment_method"]:checked')?.value;
    const note = $('[data-place-note]');
    if (note) note.textContent = method === 'bacs' ? 'A banki adatokat a következő oldalon és e-mailben is megkapod.' : method === 'cod' ? 'A megrendelés után e-mailben visszaigazoljuk a rendelést.' : method ? 'A gomb megnyomása után a fizetési szolgáltató biztonságos oldalára irányítunk.' : '';
    const btn = $('#place_order');
    const total = $('.order-total td .amount')?.textContent.trim();
    if (btn && total && !btn.querySelector('.mandala-place-total')) btn.insertAdjacentHTML('beforeend', ` · <span class="num mandala-place-total">${esc(total)}</span>`);
  }
  form.addEventListener('change', (e) => { if (e.target.name === 'payment_method') setTimeout(placeNote, 0); });

  // Kupon az összesítőben (a WooCommerce apply_coupon végpontja)
  let couponOpen = false;
  async function applyCoupon() {
    const input = $('#checkout_coupon');
    const err = $('#checkout_coupon-error');
    const code = input?.value.trim();
    if (!code) { input?.focus(); return; }
    const params = window.wc_checkout_params || {};
    const body = new URLSearchParams({ security: params.apply_coupon_nonce || '', coupon_code: code, billing_email: $('#billing_email')?.value || '' });
    // Tiltásnál (ELIN 403) a wcPost ugyanezt GET-tel küldi – az elin.php hídja a WooCommerce apply_coupon kezelőjének
    // adja át. Az e-mail-cím nem megy a címbe: a kupon e-mail-korlátozását a WooCommerce a rendeléskor úgyis nézi.
    const res = await wcPost('apply_coupon', body, { omit: ['billing_email'] });
    const text = res?.ok ? await res.text() : '';
    if (!res?.ok || !text) {
      err.innerHTML = `${icon('alert', 'ico ico-s')}<span>A kupont most nem tudtuk beváltani – nem értük el a pénztárt. Próbáld újra; ha akkor sem megy, válts másik internetre (például wifi helyett mobilnetre), vagy szólj nekünk: ${contactHtml(contactOf(M))}</span>`;
      return;
    }
    if (/woocommerce-error|is-error/.test(text)) {
      const msg = new DOMParser().parseFromString(text, 'text/html').body.textContent.trim() || 'Ez a kupon nem érvényes.';
      err.innerHTML = `${icon('alert', 'ico ico-s')}<span>${esc(msg)}</span>`;
      input.setAttribute('aria-invalid', 'true');
      input.setAttribute('aria-describedby', 'checkout_coupon-error');
      input.focus();
      return;
    }
    couponOpen = true;
    update();
  }
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-coupon-apply]')) { e.preventDefault(); applyCoupon(); }
    const toggle = e.target.closest('[data-summary-toggle]');
    if (toggle) {
      const aside = $('[data-aside]');
      const open = !aside.classList.contains('is-open');
      aside.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', String(open));
    }
    const link = e.target.closest('.woocommerce-NoticeGroup a[href^="#"]');
    if (link) { e.preventDefault(); const target = $(link.getAttribute('href')); target?.scrollIntoView({ behavior: 'smooth', block: 'center' }); target?.focus({ preventScroll: true }); }
  });
  form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.id === 'checkout_coupon') { e.preventDefault(); applyCoupon(); } });
  form.addEventListener('toggle', (e) => { if (e.target.matches?.('[data-coupon-details]')) couponOpen = e.target.open; }, true);

  // A csomagpont-választót (Pont / GLS bővítmény) a bővítmény a Fizetés blokk elejére teszi – áttesszük a
  // Szállítási mód alá, hogy a vevő ott lássa, ahol a csomagpontot választotta. A bővítmény a saját elemét
  // kezeli tovább (mutatás / rejtés, térkép), csak a helye változik; a pénztár űrlapján belül marad.
  const movePoint = () => {
    const box = $('#section-payment > .pont.woocommerce-shipping-fields, #section-payment > .pont');
    const anchor = $('#section-shipping fieldset');
    if (box && anchor) { anchor.after(box); box.classList.add('point-picker'); }
  };
  movePoint();

  // ---------- Ha a pénztár nem válaszol (ELIN 403 / szerverhiba / hálózat) ----------
  // A WooCommerce checkout.js-ének az update_order_review-nál nincs hibaága: a szürke takarás örökre rajta maradna,
  // a szállítási módok nem jelennének meg, és a vásárló semmit nem tudna meg. Levesszük a takarást, a gombot
  // használhatóvá tesszük, és megmondjuk, mit tehet (újra, másik internet, telefon, e-mail).
  const unstick = () => {
    jq?.('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table, #mandala-shipping, #section-shipping, form.checkout').each(function () { if (jq.fn.unblock) jq(this).unblock(); });
    $$('.blockUI', form).forEach((el) => el.remove());
    form.classList.remove('processing');
    const place = $('#place_order');
    if (place) { place.disabled = false; place.classList.remove('is-loading'); }
  };
  function showTrouble(kind, blocked, expired = false) {
    unstick();
    $$('.mandala-net-notice').forEach((el) => el.remove());
    const box = document.createElement('div');
    box.className = 'woocommerce-error mandala-net-notice';
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.dataset.kind = kind;
    box.innerHTML = `${icon('alert')}${checkoutTroubleHtml({ kind, blocked, expired, contact: contactOf(M) })}`;
    if (kind === 'order') {
      // A WooCommerce általános hibaüzenete helyett a miénk, a pénztár tetején (ahová a WooCommerce is görget).
      $$('.woocommerce-NoticeGroup-checkout').forEach((g) => { if (!g.querySelector('[role="alert"] ul')) g.remove(); });
      form.prepend(box);
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      box.focus({ preventScroll: true });
    } else {
      // A szállítási módok helyén – ott, ahol a vásárló épp vár valamire; gépelés közben nem görgetünk el.
      const anchor = $('#section-shipping fieldset') || $('#section-shipping') || form.firstElementChild;
      anchor ? anchor.append(box) : form.prepend(box);
    }
  }
  // A csomagpont-térkép (Pont bővítmény) a frissítés után window.initOpenMap()-et hív; a függvényt a sikeres frissítés
  // hozza. Ha a frissítés elmaradt, „window.initOpenMap is not a function” hiba állna meg minden további szkriptet –
  // ilyenkor csendes helyőrző, amíg a bővítmény a valódit be nem állítja (a jQuery hibaeseménye az ajaxComplete előtt fut).
  const guardOpenMap = () => {
    if (typeof window.initOpenMap === 'function') return;
    let real = null;
    try {
      Object.defineProperty(window, 'initOpenMap', { configurable: true, get: () => real || (() => {}), set: (f) => { real = typeof f === 'function' ? f : null; } });
    } catch { /* */ }
  };
  // A rendelés leadásakor a kosár lenyomata: ha a válasz elveszett, ehhez képest nézzük, átment-e a rendelés.
  let sentHash = '';
  // A válasz nélkül maradt rendelés (5xx / hálózat): létrejött-e? A munkamenet rendelését és a kosarat GET-tel nézzük
  // (a WooCommerce a „Függőben” / „Sikertelen” rendelést újraküldéskor újrahasználja, a többit nem – dupla rendelés lenne).
  // Visszaad: { placed, url } | 'open' (nem jött létre, újraküldhető) | 'unknown' (nem kérdezhető le).
  async function orderOutcome() {
    for (const ms of [0, 1500]) {
      if (ms) await sleep(ms);
      const st = await orderState();
      if (!st) continue;
      if (st.placed) return { placed: true, url: st.url || '' };
      if (sentHash && !st.cart_hash) return { placed: true, url: '' }; // a kosár kiürült: a rendelés átment
      return 'open';
    }
    return 'unknown';
  }
  function showPlaced(url) {
    $$('.mandala-net-notice').forEach((el) => el.remove());
    const box = document.createElement('div');
    box.className = 'woocommerce-message mandala-net-notice';
    box.setAttribute('role', 'status');
    box.tabIndex = -1;
    box.dataset.kind = 'placed';
    box.innerHTML = `${icon('check')}${orderPlacedHtml({ url })}`;
    form.prepend(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const place = $('#place_order');
    if (place) place.disabled = true; // ne lehessen még egyszer leadni
    if (url) setTimeout(() => location.assign(url), 1200);
  }
  jq?.(document).ajaxError(async (_e, xhr, settings) => {
    const ep = endpointOf(settings?.url);
    if (!['update_order_review', 'checkout'].includes(ep) || xhr?.statusText === 'abort' || !isFailure(xhr?.status)) return;
    const blocked = isElinBlock(xhr.status, xhr.getResponseHeader?.('content-type') || '', xhr.responseText || '');
    // A WordPress saját 403-ja „-1” szöveggel: lejárt az ellenőrző kód (sokáig nyitva hagyott oldal) – frissíteni kell.
    const expired = !blocked && Number(xhr.status) === 403 && /^\s*-1\s*$/.test(String(xhr.responseText || ''));
    if (ep === 'update_order_review') {
      guardOpenMap();
      // Épp megy a rendelés (a fizetési mód váltása frissítést indít, és a vásárló rögtön a gombra kattint): a gombhoz és
      // az űrlaphoz most nem nyúlunk (újra kattintható gomb = második rendelés), és átirányításkor (Teya) nem villan
      // üzenet. Ha a rendelés hibával tér vissza, utána jön az üzenet.
      if (form.classList.contains('processing')) {
        $body?.off('checkout_error.mandalaNet').one('checkout_error.mandalaNet', () => setTimeout(() => {
          if (!form.classList.contains('processing') && !$('.mandala-net-notice[data-kind="order"], .mandala-net-notice[data-kind="placed"]')) showTrouble('review', blocked || (xhr.status === 403 && !expired), expired);
        }, 0));
        return;
      }
      showTrouble('review', blocked || (xhr.status === 403 && !expired), expired);
      return;
    }
    const sure = blocked || (xhr.status === 403 && !expired); // a kérés el sem érte a boltot: nincs rendelés
    showTrouble('order', sure, expired);
    if (sure || expired) return;
    const out = await orderOutcome();
    if (out?.placed && $('.mandala-net-notice[data-kind="order"]')) showPlaced(out.url);
  });
  form.addEventListener('click', async (e) => {
    const retry = e.target.closest('[data-net-retry]');
    if (!retry) return;
    e.preventDefault();
    const kind = retry.dataset.netRetry;
    if (kind === 'reload') { location.reload(); return; }
    if (kind === 'order' && retry.dataset.check) {
      // Válasz nélküli rendelés: újraküldés előtt megnézzük, nem jött-e létre mégis.
      retry.disabled = true;
      const out = await orderOutcome();
      retry.disabled = false;
      if (out?.placed) { showPlaced(out.url); return; }
      if (out === 'unknown') {
        const lead = retry.closest('.mandala-net-notice')?.querySelector('span');
        if (lead) lead.innerHTML = `<strong>Még mindig nem érjük el a boltot.</strong> Ne add le újra, amíg nem tudjuk, megérkezett-e: várj egy percet, nézd meg a postafiókod, és próbáld újra – vagy szólj nekünk: ${contactHtml(contactOf(M))}`;
        return;
      }
    }
    retry.closest('.mandala-net-notice')?.remove();
    if (kind === 'order') $('#place_order')?.click();
    else update();
  });
  // Ha a frissítés újra sikerül, a régi üzenet felesleges.
  $body?.on('updated_checkout', () => $$('.mandala-net-notice[data-kind="review"]').forEach((el) => el.remove()));

  // Frissítés után: állapotok visszaállítása
  $body?.on('updated_checkout', () => {
    movePoint();
    syncShipping();
    placeNote();
    const details = $('[data-coupon-details]');
    if (details && couponOpen) details.open = true;
  });

  // Beküldés előtt: böngészőoldali ellenőrzés, hibaösszesítő linkekkel (a szerver is ellenőriz).
  document.addEventListener('submit', (e) => {
    if (e.target !== form) return;
    const errors = validateForm(form);
    let group = $('.woocommerce-NoticeGroup-checkout', form);
    if (!errors.length) { group?.remove(); sentHash = cartHash(); return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    if (!group) { form.insertAdjacentHTML('afterbegin', '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout" tabindex="-1"></div>'); group = $('.woocommerce-NoticeGroup-checkout', form); }
    group.innerHTML = `<div class="woocommerce-error" role="alert"><strong>${icon('alert')} ${errors.length === 1 ? 'Egy adatot még javítani kell:' : `${errors.length} adatot még javítani kell:`}</strong>
      <ul>${errors.map((x) => `<li><a href="#${esc(x.el.id)}">${esc(x.label)}</a>: ${esc(x.msg)}</li>`).join('')}</ul></div>`;
    group.scrollIntoView({ behavior: 'smooth', block: 'start' });
    group.focus({ preventScroll: true });
  }, true);

  // Kész szakaszok jelölése
  form.addEventListener('focusout', () => setTimeout(() => {
    [['#section-contact', '#section-contact'], ['#section-billing', '.woocommerce-billing-fields__field-wrapper']].forEach(([sec, root]) => {
      const fields = $$(`${root} [data-validate]`).filter((el) => el.offsetParent !== null);
      $(sec)?.classList.toggle('is-complete', fields.length > 0 && fields.every((el) => el.value && !el.getAttribute('aria-invalid')));
    });
  }, 0));

  // Elhagyott kosár: az érvényes e-mail-cím rögzítése (egyetlen emlékeztető, leiratkozható).
  const emailEl = $('#billing_email');
  let captured = '';
  emailEl?.addEventListener('change', () => {
    const v = emailEl.value.trim();
    if (v === captured || !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) || !M.wcAjax) return;
    captured = v;
    // Tiltásnál GET-tel ismétli (az e-mail és a név sütiben megy, nem a címben – net.js).
    wcPost('mandala_capture', new URLSearchParams({ email: v, name: $('#billing_first_name')?.value || '', security: M.cartNonce || '' })).catch(() => {});
  });

  syncShipping();
  placeNote();
  void fmt;
}
