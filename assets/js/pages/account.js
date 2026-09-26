// Fiók (woocommerce_my_account): belépés + regisztráció, állapotokkal.
import { initPage, $, $$, icon, bindValidation, validateForm } from '../ui.js';

initPage({ active: 'account' });
const pw = (id, name, label, auto, validate) => `<div class="iu-form-field form-row"><label for="${id}">${label}<abbr class="required" title="kötelező">*</abbr></label>
  <div class="password-input"><input class="input-text" type="password" id="${id}" name="${name}" autocomplete="${auto}" data-validate="${validate}" required>
  <button type="button" class="show-password-input" aria-label="Jelszó megjelenítése" aria-pressed="false">${icon('eye')}</button></div></div>`;
$('[data-account]').innerHTML = `<div class="u-columns col2-set">
  <div class="panel"><h2 style="font-size:var(--fs-h3)">Belépés</h2>
    <form class="woocommerce-form woocommerce-form-login login iu-form" novalidate data-login>
      <div class="iu-form-field form-row"><label for="username">E-mail-cím<abbr class="required" title="kötelező">*</abbr></label><input class="input-text" type="email" id="username" name="username" autocomplete="username" data-validate="required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek." required></div>
      ${pw('password', 'password', 'Jelszó', 'current-password', 'required|Add meg a jelszavad.')}
      <label class="check"><input type="checkbox" name="rememberme"> <span>Maradjak bejelentkezve</span></label>
      <button type="submit" class="iu-button iu-button-large iu-button-block">Belépés</button>
      <p class="lost_password"><a href="#" data-lost>Elfelejtetted a jelszavad?</a></p>
      <p class="form-message" role="status"></p>
    </form></div>
  <div class="panel panel-soft"><h2 style="font-size:var(--fs-h3)">Új vagyok itt</h2>
    <p class="text-muted">Fiókkal követheted a rendeléseidet, és gyorsabban vásárolhatsz. Rendelni fiók nélkül is lehet.</p>
    <form class="woocommerce-form woocommerce-form-register register iu-form" novalidate data-register>
      <div class="iu-form-field form-row"><label for="reg_email">E-mail-cím<abbr class="required" title="kötelező">*</abbr></label><input class="input-text" type="email" id="reg_email" name="email" autocomplete="email" data-validate="required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek." required></div>
      ${pw('reg_password', 'password', 'Jelszó', 'new-password', 'required|Adj meg egy jelszót.\nmin8|Legalább 8 karakter legyen.')}
      <p class="field-hint">Legalább 8 karakter. Tipp: egy rövid mondat könnyebben megjegyezhető.</p>
      <label class="iu-form-accept check-row" for="reg_accept"><input type="checkbox" id="reg_accept" data-validate="required|A regisztrációhoz fogadd el az adatkezelési tájékoztatót."> <span>Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>.</span></label>
      <button type="submit" class="iu-button iu-button-outline iu-button-large iu-button-block">Regisztráció</button>
      <p class="form-message" role="status"></p>
    </form></div>
</div>`;
$$('.show-password-input').forEach((b) => b.addEventListener('click', () => {
  const input = b.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  b.setAttribute('aria-pressed', String(show));
  b.setAttribute('aria-label', show ? 'Jelszó elrejtése' : 'Jelszó megjelenítése');
}));
$$('[data-login],[data-register]').forEach((form) => {
  bindValidation(form);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = $('.form-message', form);
    const errs = validateForm(form);
    if (errs.length) { errs[0].el.focus(); msg.className = 'form-message is-error'; msg.innerHTML = `${icon('alert')}<span>Javítsd a jelölt mezőket.</span>`; return; }
    // A prototípusban nincs szerveroldali fiók: a WooCommerce kezeli élesben.
    msg.className = 'form-message is-error';
    msg.innerHTML = `${icon('info')}<span>${form.matches('[data-login]') ? 'Ismeretlen e-mail-cím vagy hibás jelszó. (A prototípusban a belépés nem aktív – élesben a WooCommerce fiókkezelése működik.)' : 'A prototípusban a regisztráció nem aktív – élesben a WooCommerce létrehozza a fiókot, és üdvözlő e-mailt küld.'}</span>`;
  });
});
$('[data-lost]').addEventListener('click', (e) => { e.preventDefault(); const m = $('[data-login] .form-message'); m.className = 'form-message is-success'; m.innerHTML = `${icon('mail')}<span>Ha létezik fiók ehhez a címhez, küldtünk egy jelszó-visszaállító linket.</span>`; });
