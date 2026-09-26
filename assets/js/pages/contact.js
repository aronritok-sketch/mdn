// Kapcsolat: iu/form (formId: kapcsolat) + elérhetőségek + térkép helye.
import { initPage, $, esc, icon, formField, acceptField, bindSimpleForm } from '../ui.js';
import { CONFIG } from '../data.js';

initPage();
const c = CONFIG.contact;
$('[data-contact]').innerHTML = `
  <li>${icon('mail')}<span><strong>E-mail</strong><a href="mailto:${c.email}">${c.email}</a></span></li>
  <li>${icon('phone')}<span><strong>Telefon</strong><a href="tel:${c.phone.replace(/\s/g, '')}">${c.phone}</a></span></li>
  <li>${icon('pin')}<span><strong>Bemutatóterem</strong>${esc(c.address)}</span></li>
  <li>${icon('clock')}<span><strong>Nyitvatartás</strong>${esc(c.hours)}</span></li>`;
$('[data-map]').innerHTML = `<svg class="map-bg" viewBox="0 0 400 300" aria-hidden="true" preserveAspectRatio="xMidYMid slice"><g fill="none" stroke="currentColor" stroke-width="2"><path d="M0 80h400M0 190h400M120 0v300M290 0v300M0 250 400 40"/><path d="M40 0c40 90 20 180 90 300M330 0c-30 120 20 200-40 300" stroke-width="10" stroke-opacity=".6"/></g></svg>
  <div><span class="pin" aria-hidden="true"><span></span></span><strong style="color:var(--c-ink)">Budapest</strong><span>A Google Térkép a pontos cím megadása után kerül ide (iu/html beágyazás).</span></div>`;
$('[data-contact-form]').innerHTML = `<form class="iu-form" data-form-id="kapcsolat" novalidate data-success="Köszönjük az üzenetet! Egy munkanapon belül válaszolunk a megadott e-mail-címre.">
  <h2 style="font-size:var(--fs-h3);margin:0">Üzenet küldése</h2>
  <div class="form-grid">
    ${formField({ id: 'c-name', name: 'name', label: 'Név', auto: 'name', validate: 'required|Add meg a neved.' })}
    ${formField({ id: 'c-email', name: 'email', label: 'E-mail-cím', type: 'email', auto: 'email', validate: 'required|Add meg az e-mail-címed.\nemail|Ez nem tűnik érvényes e-mail-címnek.' })}
    ${formField({ id: 'c-phone', name: 'phone', label: 'Telefonszám', type: 'tel', auto: 'tel', validate: 'phone|Adj meg egy magyar telefonszámot (+36…).', hint: 'Ha visszahívást kérsz.' })}
    ${formField({ id: 'c-topic', name: 'topic', label: 'Téma', options: ['Termékkérdés', 'Rendelésem állapota', 'Visszaküldés', 'Személyes átvétel / bemutatóterem', 'Egyéb'], value: 'Termékkérdés' })}
    ${formField({ id: 'c-msg', name: 'message', label: 'Üzenet', textarea: true, span: 'span-2', validate: 'required|Írd meg az üzeneted.' })}
  </div>
  ${acceptField('c-accept', 'Elfogadom az <a href="jogi.html?d=adatkezeles">adatkezelési tájékoztatót</a>.')}
  <div><button type="submit" class="iu-button iu-button-large">Üzenet küldése</button></div>
  <p class="form-message" role="status"></p>
</form>`;
$('#c-phone').dataset.format = 'phone';
bindSimpleForm($('[data-form-id="kapcsolat"]'));
