// Egyszerű oldalak (Rólunk, Információk, Kapcsolat, Viszonteladóknak) és űrlapjaik.
import { initPage, $, $$, icon, esc } from '../app.js';
import { CONFIG } from '../data.js';

initPage({ active: document.body.dataset.active || '' });

const contact = $('[data-contact]');
if (contact) {
  const c = CONFIG.contact;
  contact.innerHTML = `
    <li>${icon('mail')}<a href="mailto:${c.email}">${esc(c.email)}</a></li>
    <li>${icon('phone')}<a href="tel:${c.phone.replace(/\s/g, '')}">${esc(c.phone)}</a></li>
    <li>${icon('pin')}<span>${esc(c.address)}</span></li>
    <li>${icon('clock')}<span>${esc(c.hours)}</span></li>
    <li>${icon('facebook')}<a href="${c.facebook}" target="_blank" rel="noopener">facebook.com/mandalawebaruhaz</a></li>`;
}

const rules = {
  email: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || 'Adj meg egy érvényes e-mail-címet.',
  tel: (v) => v.replace(/\D/g, '').length >= 9 || 'Adj meg egy érvényes telefonszámot.',
  taxno: (v) => /^\d{8}-?\d-?\d{2}$/.test(v) || 'Formátum: 12345678-1-12',
};

$$('form[data-validate]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    let first = null;
    $$('.field', form).forEach((f) => {
      const input = $('input, textarea, select', f);
      const err = $('.field-err', f);
      if (!input) return;
      const v = input.value.trim();
      let msg = '';
      if (input.required && !v) msg = 'Kötelező mező.';
      else if (v && rules[input.dataset.rule || input.type]) {
        const r = rules[input.dataset.rule || input.type](v);
        if (r !== true) msg = r;
      }
      f.classList.toggle('invalid', !!msg);
      input.toggleAttribute('aria-invalid', !!msg);
      if (err) err.textContent = msg;
      if (msg && !first) first = input;
    });
    const consent = $('input[name="consent"]', form);
    const status = $('.form-msg', form);
    if (!first && consent && !consent.checked) { first = consent; status.textContent = 'Kérjük, fogadd el az adatkezelési tájékoztatót.'; status.className = 'form-msg err'; }
    if (first) { first.focus(); return; }
    // Éles üzemben: POST a WordPress oldali űrlapkezelőnek (pl. Contact Form 7 REST végpont).
    status.textContent = form.dataset.success || 'Köszönjük, üzenetedet megkaptuk.';
    status.className = 'form-msg ok';
    form.reset();
  });
});
