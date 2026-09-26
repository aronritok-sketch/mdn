// Ajándékcsomag-összeállító (mandala/gift-builder): kiválasztás, élő összeg, kosárba (wc-ajax=mandala_gift_add).
import { $, $$, esc, icon, fmt } from './env.js';

const M = window.MANDALA || {};
const form = $('[data-gift-builder]');

if (form) {
  const max = Number(form.dataset.max) || 6;
  const count = $('[data-gift-count]', form);
  const total = $('[data-gift-total]', form);
  const submit = $('[data-gift-submit]', form);
  const error = $('[data-gift-error]', form);
  const chars = $('[data-gift-chars]', form);
  const items = () => $$('input[name="items[]"]:checked', form);

  function update() {
    const chosen = items();
    const box = $('input[name="box"]:checked', form);
    const sum = chosen.reduce((s, i) => s + Number(i.dataset.price), 0) + (box ? Number(box.dataset.price) : 0);
    $$('input[name="items[]"]', form).forEach((i) => { i.disabled = !i.checked && chosen.length >= max; });
    count.textContent = chosen.length
      ? `${chosen.length} termék kiválasztva${chosen.length >= max ? ` – ennyi fér egy csomagba` : chosen.length < 2 ? ' – még legalább egy kell' : ''}.`
      : 'Még nincs kiválasztva termék.';
    total.textContent = chosen.length ? fmt(sum) : '–';
    submit.disabled = chosen.length < 2 || !box;
  }

  form.addEventListener('change', update);
  $('#gift-message', form)?.addEventListener('input', (e) => { chars.textContent = `${e.target.value.length} / 200`; });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.innerHTML = '';
    const body = new FormData(form);
    body.append('security', M.cartNonce || '');
    submit.classList.add('is-loading'); submit.disabled = true;
    try {
      const res = await fetch(String(M.wcAjax).replace('%%endpoint%%', 'mandala_gift_add'), { method: 'POST', credentials: 'same-origin', body });
      const data = await res.json();
      if (data.ok) {
        const added = items().map((i) => [Number(i.value), 1]).concat([[Number($('input[name="box"]:checked', form).value), 1]]);
        document.dispatchEvent(new CustomEvent('mandala:cart-add', { detail: { items: added, source: 'gift_builder' } }));
        setTimeout(() => { location.href = data.cart; }, 150);
        return;
      }
      error.innerHTML = `${icon('alert', 'ico ico-s')}<span>${esc(data.error || 'Most nem sikerült – próbáld újra.')}</span>`;
    } catch {
      error.innerHTML = `${icon('alert', 'ico ico-s')}<span>Most nem sikerült – próbáld újra.</span>`;
    }
    submit.classList.remove('is-loading');
    update();
  });
  update();
}
