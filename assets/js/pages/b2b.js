// Viszonteladói jelentkezés: iu/form + iu/form-steps (3 lépés), adószám-ellenőrzéssel.
import { initPage, $, $$, esc, icon, formField, acceptField, bindValidation, validateForm } from '../ui.js';

initPage({ active: 'b2b' });
$('[data-b2b-perks]').innerHTML = ['Hangtálak mért frekvencia- és súlyadatokkal', 'Termékfotók és leírások a saját felületeidre', 'ÁFÁ-s számla, személyes átvétel vagy futár', 'Utánrendelés és félretétel új érkezéskor']
  .map((t) => `<li>${icon('check')}<span>${t}</span></li>`).join('');

const STEPS = ['Cég adatai', 'Kapcsolattartó', 'Érdeklődés'];
$('[data-b2b-form]').innerHTML = `<form class="iu-form" data-form-id="viszontelado" novalidate>
  <ol class="iu-form-steps" aria-label="Jelentkezés lépései">${STEPS.map((s, i) => `<li data-step-ind="${i}">${i + 1}. ${s}</li>`).join('')}</ol>
  <fieldset class="iu-form-step" data-step="0" style="border:0;padding:0;margin:0"><legend class="sr-only">Cég adatai</legend><div class="form-grid">
    ${formField({ id: 'b-company', name: 'company', label: 'Cégnév', auto: 'organization', span: 'span-2', validate: 'required|Add meg a cég nevét.' })}
    ${formField({ id: 'b-tax', name: 'tax_number', label: 'Adószám', placeholder: '12345676-2-41', hint: 'Formátum: 8-1-2 számjegy. Ellenőrizzük a számjegyeket is.', validate: 'required|Add meg az adószámot.\ntaxno|Ez nem érvényes magyar adószám.' })}
    ${formField({ id: 'b-type', name: 'shop_type', label: 'Tevékenység', options: ['Jógastúdió', 'Ajándék- vagy lakberendezési bolt', 'Masszázs / terápia', 'Webáruház', 'Egyéb'], value: 'Jógastúdió' })}
    ${formField({ id: 'b-city', name: 'city', label: 'Település', auto: 'address-level2', validate: 'required|Add meg a települést.' })}
    ${formField({ id: 'b-web', name: 'website', label: 'Weboldal vagy közösségi oldal', type: 'text', auto: 'url' })}
  </div></fieldset>
  <fieldset class="iu-form-step" data-step="1" hidden style="border:0;padding:0;margin:0"><legend class="sr-only">Kapcsolattartó</legend><div class="form-grid">
    ${formField({ id: 'b-name', name: 'name', label: 'Név', auto: 'name', validate: 'required|Add meg a kapcsolattartó nevét.' })}
    ${formField({ id: 'b-phone', name: 'phone', label: 'Telefonszám', type: 'tel', auto: 'tel', validate: 'required|Add meg a telefonszámot.\nphone|Adj meg egy magyar telefonszámot (+36…).' })}
    ${formField({ id: 'b-email', name: 'email', label: 'E-mail-cím', type: 'email', auto: 'email', span: 'span-2', validate: 'required|Add meg az e-mail-címet.\nemail|Ez nem tűnik érvényes e-mail-címnek.' })}
  </div></fieldset>
  <fieldset class="iu-form-step" data-step="2" hidden style="border:0;padding:0;margin:0"><legend class="sr-only">Érdeklődés</legend><div class="form-grid">
    ${formField({ id: 'b-volume', name: 'volume', label: 'Várható havi rendelés', options: ['50 000 Ft alatt', '50 000 – 200 000 Ft', '200 000 Ft felett'], value: '50 000 – 200 000 Ft' })}
    ${formField({ id: 'b-cats', name: 'categories', label: 'Érdeklődési kör', options: ['Hangtálak és hangszerek', 'Füstölők', 'Szobrok és dekor', 'Textil és ékszer', 'Vegyes'], value: 'Vegyes' })}
    ${formField({ id: 'b-msg', name: 'message', label: 'Megjegyzés', textarea: true, span: 'span-2' })}
  </div>${acceptField('b-accept', 'Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>.')}</fieldset>
  <div class="iu-form-step-nav"><button type="button" class="iu-button iu-button-outline" data-prev hidden>${icon('arrow-left', 'ico ico-s')} Előző</button><span></span>
    <button type="button" class="iu-button" data-next>Következő ${icon('arrow', 'ico ico-s')}</button><button type="submit" class="iu-button" hidden>Jelentkezés elküldése</button></div>
  <p class="form-message" role="status"></p>
</form>`;
$('#b-tax').dataset.format = 'taxno';
$('#b-phone').dataset.format = 'phone';
const form = $('[data-form-id="viszontelado"]');
bindValidation(form);
let step = 0;
function go(n) {
  step = n;
  $$('[data-step]', form).forEach((f) => { f.hidden = Number(f.dataset.step) !== step; });
  $$('[data-step-ind]', form).forEach((li) => { const i = Number(li.dataset.stepInd); li.className = i < step ? 'is-done' : i === step ? 'is-current' : ''; li.toggleAttribute('aria-current', i === step); });
  $('[data-prev]', form).hidden = step === 0;
  $('[data-next]', form).hidden = step === STEPS.length - 1;
  $('button[type="submit"]', form).hidden = step !== STEPS.length - 1;
  $(`[data-step="${step}"] input, [data-step="${step}"] select`, form)?.focus();
}
const stepValid = () => { const errs = validateForm($(`[data-step="${step}"]`, form)); if (errs.length) errs[0].el.focus(); return !errs.length; };
$('[data-next]', form).addEventListener('click', () => { if (stepValid()) go(step + 1); });
$('[data-prev]', form).addEventListener('click', () => go(step - 1));
form.addEventListener('submit', (e) => {
  e.preventDefault();
  if (!stepValid()) return;
  const btn = $('button[type="submit"]', form);
  btn.classList.add('is-loading');
  setTimeout(() => {
    form.innerHTML = `<div class="empty-state" style="border-style:solid;border-color:var(--c-line)"><span style="color:var(--c-success)">${icon('check-circle', 'ico ico-xl')}</span>
      <h2 style="font-size:var(--fs-h3)">Köszönjük a jelentkezést!</h2><p>1–2 munkanapon belül jelentkezünk a megadott e-mail-címen. A jóváhagyás után belépve a viszonteladói árakat látod.</p>
      <a class="iu-button" href="termekek.html">Addig nézz körül</a></div>`;
  }, 800);
});
go(0);
