// Viszonteladói gyorsrendelő (Fiókom → Viszonteladói felület): a termékindex a belépett
// viszonteladónak nagyker árakkal érkezik; szűrés, mennyiségek, tömeges kosárba tétel.
import { $, $$, esc, icon, fmt, norm, loadProducts } from './env.js';

const M = window.MANDALA || {};
const root = $('[data-b2b]');

if (root) {
  const rows = $('[data-b2b-rows]', root);
  const q = $('[data-b2b-q]', root);
  const cat = $('[data-b2b-cat]', root);
  const stock = $('[data-b2b-stock]', root);
  const count = $('[data-b2b-count]', root);
  const sum = $('[data-b2b-sum]', root);
  const add = $('[data-b2b-add]', root);
  const msg = $('[data-b2b-msg]', root);
  const qty = new Map(); // termék id → mennyiség (szűrés közben is megmarad)
  let all = [];
  const LIMIT = 150;

  const stockLabel = (p) => (p.stock === 'out' ? 'nincs' : p.stock === 'incoming' ? `érkezik ${p.incomingLabel || ''}` : p.stockQty ? `${p.stockQty} db` : 'van');

  function render() {
    const term = norm(q.value.trim());
    const list = all.filter((p) => (!cat.value || p.cat === cat.value)
      && (!stock.checked || p.stock === 'in' || p.stock === 'low')
      && (!term || norm(`${p.name} ${p.sku}`).includes(term)));
    count.textContent = list.length > LIMIT ? `${list.length} termék – az első ${LIMIT} látszik, szűkíts kereséssel.` : `${list.length} termék`;
    rows.innerHTML = list.slice(0, LIMIT).map((p) => {
      const max = p.stock === 'in' || p.stock === 'low' ? (p.stockQty || 999) : 0;
      return `<tr>
        <th scope="row"><a href="${esc(p.url)}">${esc(p.name)}</a></th>
        <td class="sku">${esc(p.sku || '')}</td>
        <td class="num" data-label="Bolti ár">${p.retail ? fmt(p.retail) : fmt(p.price)}</td>
        <td class="num" data-label="Nagyker">${p.wholesale ? `<strong>${fmt(p.price)}</strong>` : '–'}</td>
        <td class="num" data-label="Készlet">${esc(stockLabel(p))}</td>
        <td><input type="number" class="input-text" inputmode="numeric" min="0" max="${max}" step="1" value="${qty.get(p.id) || ''}" data-id="${p.id}" aria-label="Mennyiség: ${esc(p.name)}"${max ? '' : ' disabled'}></td>
      </tr>`;
    }).join('');
  }

  function total() {
    let pcs = 0; let amount = 0;
    for (const [id, n] of qty) { const p = all.find((x) => x.id === id); if (p) { pcs += n; amount += n * p.price; } }
    sum.textContent = `${pcs} db · ${fmt(amount)}`;
    add.disabled = pcs === 0;
  }

  rows.addEventListener('input', (e) => {
    const input = e.target.closest('input[data-id]');
    if (!input) return;
    const max = Number(input.max) || 999;
    const n = Math.max(0, Math.min(max, Math.floor(Number(input.value) || 0)));
    if (n) qty.set(Number(input.dataset.id), n); else qty.delete(Number(input.dataset.id));
    total();
  });
  rows.addEventListener('change', (e) => { const input = e.target.closest('input[data-id]'); if (input) input.value = qty.get(Number(input.dataset.id)) || ''; });
  [q, cat, stock].forEach((el) => el.addEventListener('input', render));

  add.addEventListener('click', async () => {
    msg.innerHTML = '';
    add.classList.add('is-loading'); add.disabled = true;
    const body = new URLSearchParams({ security: M.cartNonce || '', items: JSON.stringify([...qty]) });
    try {
      const res = await fetch(String(M.wcAjax).replace('%%endpoint%%', 'mandala_bulk_add'), { method: 'POST', credentials: 'same-origin', body });
      const data = await res.json();
      if (data.ok) {
        document.dispatchEvent(new CustomEvent('mandala:cart-add', { detail: { items: [...qty], source: 'b2b_quick_order' } }));
        qty.clear(); render(); total();
        msg.innerHTML = `${icon('check', 'ico ico-s')}<span>${data.added} db a kosárban.${data.failed.length ? ` Nem sikerült: ${esc(data.failed.join(', '))} (készlet).` : ''} <a href="${esc(data.cart)}">Tovább a kosárhoz</a></span>`;
        $$('[data-cart-count]').forEach((el) => { el.hidden = !data.count; el.textContent = data.count; });
      } else msg.innerHTML = `${icon('alert', 'ico ico-s')}<span>${esc(data.error || 'Most nem sikerült – próbáld újra.')}</span>`;
    } catch {
      msg.innerHTML = `${icon('alert', 'ico ico-s')}<span>Most nem sikerült – próbáld újra.</span>`;
    }
    add.classList.remove('is-loading');
    total();
  });

  loadProducts().then((list) => { all = list.filter((p) => !p.voucher); render(); total(); });
}
