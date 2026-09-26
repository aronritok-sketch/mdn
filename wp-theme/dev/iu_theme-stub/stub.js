// iu_theme TESZT HELYETTESÍTŐ – iu/form beküldés (AJAX) és iu/accordion.
document.addEventListener('submit', async (e) => {
  const form = e.target.closest('form.iu-form[data-form-id]');
  if (!form || e.defaultPrevented) return;
  e.preventDefault();
  const data = new FormData(form);
  data.append('iu_form_id', form.dataset.formId);
  const msg = form.querySelector('.form-message');
  form.querySelectorAll('.field-error').forEach((x) => x.remove());
  const res = await fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' });
  const json = await res.json().catch(() => ({ errors: {}, error: 'Hiba' }));
  if (json.errors && (Object.keys(json.errors).length || json.error)) {
    Object.entries(json.errors).forEach(([name, text]) => { const el = form.querySelector(`[name="${name}"]`); if (el) el.insertAdjacentHTML('afterend', `<p class="field-error">${text}</p>`); });
    if (msg) { msg.className = 'form-message is-error'; msg.textContent = json.error || 'Javítsd a hibás mezőket.'; }
  } else if (msg) { msg.className = 'form-message is-success'; msg.textContent = 'Köszönjük, megkaptuk.'; form.reset(); }
});
document.addEventListener('click', (e) => {
  const head = e.target.closest('.iu-accordion-item-head');
  if (!head) return;
  const body = head.nextElementSibling;
  const open = head.getAttribute('aria-expanded') !== 'true';
  head.setAttribute('aria-expanded', String(open));
  body.hidden = !open;
});
document.querySelectorAll('.iu-accordion-item-head').forEach((h, i) => { h.setAttribute('role', 'button'); h.tabIndex = 0; h.setAttribute('aria-expanded', String(i === 0)); h.nextElementSibling.hidden = i !== 0; });
