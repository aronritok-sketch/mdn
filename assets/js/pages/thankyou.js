// Köszönő oldal – WooCommerce order-received (thankyou.php) markup.
import { initPage, $, $$, esc, icon, params, setMeta, toast } from '../ui.js';
import { CONFIG } from '../data.js';
import { orders, fmt } from '../store.js';

initPage();
const root = $('[data-thankyou]');
const o = orders.get(params().get('order')) || (params().get('order') ? null : orders.last());

if (!o) {
  root.innerHTML = `<h1 class="iu-title" style="font-size:var(--fs-h2);text-align:center">Rendelés visszaigazolása</h1><div class="woocommerce-error" role="alert"><strong>${icon('alert')} Ezt a rendelést nem találjuk ezen az eszközön.</strong><span>A visszaigazolást e-mailben is elküldtük. Kérdés esetén írj nekünk: <a href="mailto:${CONFIG.contact.email}">${CONFIG.contact.email}</a></span></div>`;
} else {
  setMeta({ title: `Köszönjük – rendelés #${o.number}` });
  const date = new Date(o.date);
  const T = o.totals;
  const copy = (value, label) => `<button type="button" class="copy-btn" data-copy="${esc(value)}" aria-label="${esc(label)} másolása">${icon('copy', 'ico ico-s')} Másolás</button>`;
  const pickup = o.shipping.method === 'pickup';

  const paymentBlock = {
    barion: `<div class="woocommerce-message" role="status">${icon('check-circle')}<span><strong>Sikeres fizetés.</strong> A Barion tranzakciót rögzítettük, a rendelésed feldolgozás alatt van. (Prototípus: a fizetés szimulált.)</span></div>`,
    bacs: `<section class="woocommerce-bacs-bank-details panel" aria-labelledby="bacs-title">
      <h2 class="wc-bacs-bank-details-heading" id="bacs-title" style="font-size:var(--fs-h3)">Utalási adatok</h2>
      <p class="text-muted">A csomagot a jóváírás után adjuk fel. Közleménynek pontosan a rendelésszámot írd.</p>
      <ul class="wc-bacs-bank-details order_details bacs_details">
        <li class="account_name">Kedvezményezett<strong>${esc(CONFIG.bank.holder)}</strong></li>
        <li class="bank_name">Bank<strong>${esc(CONFIG.bank.name)}</strong></li>
        <li class="account_number">Számlaszám<strong class="num">${esc(CONFIG.bank.account)} ${copy(CONFIG.bank.account, 'Számlaszám')}</strong></li>
        <li class="iban">IBAN<strong class="num">${esc(CONFIG.bank.iban)} ${copy(CONFIG.bank.iban.replace(/\s/g, ''), 'IBAN')}</strong></li>
        <li>Közlemény<strong class="num">${o.number} ${copy(o.number, 'Közlemény')}</strong></li>
        <li>Összeg<strong class="num">${fmt(T.total)} ${copy(String(T.total), 'Összeg')}</strong></li>
      </ul><p class="field-hint" style="margin-top:var(--space-4)">A banki adatok helykitöltők – élesítéskor a WooCommerce „Előre utalás” beállításából jönnek.</p></section>`,
    cod: `<div class="woocommerce-info" role="status">${icon('cash')}<span><strong>${pickup ? 'Fizetés átvételkor' : 'Utánvét'}:</strong> ${fmt(T.total)} – ${pickup ? 'a bemutatóteremben készpénzzel vagy bankkártyával.' : o.shipping.method === 'foxpost' ? 'az automatánál bankkártyával.' : 'a futárnál készpénzzel vagy bankkártyával.'}</span></div>`,
  }[o.payment.id];

  const deliveryStep = pickup ? ['Átvehető a bemutatóteremben', 'E-mailben értesítünk, amikor készen áll'] : o.shipping.method === 'foxpost'
    ? ['Az automatába kerül', `${esc(o.shipping.locker?.name || 'Foxpost automata')} – a nyitókódot SMS-ben kapod`] : ['Futárnak átadjuk', 'A GLS csomagkövető linkjét e-mailben küldjük'];
  const shipTo = pickup ? `Személyes átvétel<br>Mandala bemutatóterem, Budapest<br>${esc(CONFIG.contact.hours)}`
    : o.shipping.method === 'foxpost' ? `Foxpost csomagautomata<br><strong>${esc(o.shipping.locker?.name || '')}</strong><br>${esc(o.shipping.locker?.address || '')}`
      : `GLS futárszolgálat<br>${esc(o.shipping.address || `${o.billing.name}, ${o.billing.address}`)}`;

  root.innerHTML = `<div class="woocommerce-order">
    <div class="thankyou-hero">
      <span class="seal">${icon('check', 'ico')}</span>
      <h1 class="iu-title" style="font-size:clamp(2.25rem,4.5vw,3.25rem);margin:0">Köszönjük, ${esc(o.billing.first || 'kedves vásárlónk')}!</h1>
      <p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">Megkaptuk a rendelésed. A visszaigazolást elküldtük a(z) <strong>${esc(o.email)}</strong> címre – ha nem látod, nézd meg a Promóciók vagy a Spam mappát is.</p>
    </div>

    <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
      <li class="woocommerce-order-overview__order order">Rendelésszám<strong class="num">#${o.number}</strong></li>
      <li class="woocommerce-order-overview__date date">Dátum<strong>${date.toLocaleDateString('hu-HU', { year: 'numeric', month: 'long', day: 'numeric' })}</strong></li>
      <li class="woocommerce-order-overview__email email">E-mail<strong>${esc(o.email)}</strong></li>
      <li class="woocommerce-order-overview__total total">Végösszeg<strong class="num">${fmt(T.total)}</strong></li>
      <li class="woocommerce-order-overview__payment-method method">Fizetési mód<strong>${esc(o.payment.label)}</strong></li>
    </ul>

    ${paymentBlock}

    <div class="iu-row" style="width:100%;gap:var(--space-6)">
      <div class="iu-column iu-column-2-3" style="display:grid;gap:var(--space-6);align-content:start">
        <section class="woocommerce-order-details panel" aria-labelledby="details-title">
          <h2 class="woocommerce-order-details__title" id="details-title" style="font-size:var(--fs-h3)">Rendelés részletei</h2>
          <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
            <thead><tr><th class="woocommerce-table__product-name product-name">Termék</th><th class="woocommerce-table__product-table product-total">Összesen</th></tr></thead>
            <tbody>${o.items.map((i) => `<tr class="woocommerce-table__line-item order_item"><td class="woocommerce-table__product-name product-name">${esc(i.name)} <strong class="product-quantity">×&nbsp;${i.qty}</strong><br><span class="text-muted text-small">Cikkszám: ${esc(i.sku)}</span></td><td class="woocommerce-table__product-total product-total">${fmt(i.price * i.qty)}</td></tr>`).join('')}</tbody>
            <tfoot>
              <tr><th scope="row">Részösszeg:</th><td>${fmt(T.subtotal)}</td></tr>
              ${T.discount ? `<tr><th scope="row">Kedvezmény (${esc(T.code)}):</th><td>−${fmt(T.discount)}</td></tr>` : ''}
              <tr><th scope="row">Szállítás:</th><td>${T.shipping ? fmt(T.shipping) : 'Ingyenes'} <span class="text-muted text-small">– ${esc(o.shipping.label)}</span></td></tr>
              ${T.fee ? `<tr><th scope="row">Utánvét díja:</th><td>${fmt(T.fee)}</td></tr>` : ''}
              <tr><th scope="row">Fizetési mód:</th><td>${esc(o.payment.label)}</td></tr>
              <tr><th scope="row">Végösszeg:</th><td>${fmt(T.total)}<br><small class="text-muted" style="font-weight:400">Tartalmaz ${fmt(T.vat)} ÁFA-t (${CONFIG.vatRate}%)</small></td></tr>
              ${o.notes ? `<tr><th scope="row">Megjegyzés:</th><td style="text-align:left">${esc(o.notes)}</td></tr>` : ''}
            </tfoot>
          </table>
        </section>
        <section class="woocommerce-customer-details panel" aria-labelledby="addr-title">
          <h2 id="addr-title" style="font-size:var(--fs-h3)">Címek</h2>
          <div class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
            <div><h3 style="font-size:var(--fs-h5)">Számlázási adatok</h3><address>${esc(o.billing.name)}${o.billing.company ? `<br>${esc(o.billing.company)}<br>Adószám: ${esc(o.billing.tax)}` : ''}<br>${esc(o.billing.address)}<br>${esc(o.phone)}<br>${esc(o.email)}</address></div>
            <div><h3 style="font-size:var(--fs-h5)">Szállítás</h3><address>${shipTo}</address></div>
          </div>
        </section>
      </div>
      <div class="iu-column iu-column-1-3" style="display:grid;gap:var(--space-6);align-content:start">
        <section class="panel" aria-labelledby="next-title">
          <h2 id="next-title" style="font-size:var(--fs-h4)">Mi történik most?</h2>
          <ol class="timeline">
            <li class="is-done"><span class="dot">${icon('check', 'ico ico-s')}</span><span><strong>Visszaigazolás elküldve</strong><span>${esc(o.email)}</span></span></li>
            <li><span class="dot">2</span><span><strong>${o.payment.id === 'bacs' ? 'Várjuk az utalást' : 'Csomagoljuk'}</strong><span>${o.payment.id === 'bacs' ? 'A jóváírás után azonnal csomagolunk' : 'Általában 1 munkanapon belül'}</span></span></li>
            <li><span class="dot">3</span><span><strong>${deliveryStep[0]}</strong><span>${deliveryStep[1]}</span></span></li>
            <li><span class="dot">4</span><span><strong>${pickup ? 'Átveszed' : 'Megérkezik'}</strong><span>Jó elcsendesedést!</span></span></li>
          </ol>
        </section>
        ${o.account ? `<div class="woocommerce-message">${icon('user')}<span>Létrehoztuk a fiókodat – a rendelésed a <a href="fiok.html">Fiókom</a> oldalon követheted.</span></div>`
          : `<section class="panel panel-soft"><h2 style="font-size:var(--fs-h5)">Kövesd a rendelésed</h2><p class="text-muted text-small">Egy jelszóval fiókot hozhatsz létre ezekkel az adatokkal.</p><a class="iu-button iu-button-outline iu-button-block" href="fiok.html">Fiók létrehozása</a></section>`}
        <div class="iu-button-group"><a class="iu-button iu-button-block" href="termekek.html">Vásárlás folytatása</a><a class="iu-button iu-button-link" href="magazin.html">Olvass a magazinban</a></div>
      </div>
    </div>
  </div>`;

  root.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    try { await navigator.clipboard.writeText(b.dataset.copy); toast('Vágólapra másolva.'); } catch { toast(`Másold ki kézzel: ${esc(b.dataset.copy)}`); }
  });
  $$('.woocommerce-order-overview strong').forEach((el) => el.setAttribute('translate', 'no'));
}
