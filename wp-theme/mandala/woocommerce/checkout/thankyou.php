<?php
/**
 * Köszönő oldal: összegzés, fizetési teendő (utalási adatok másolás gombbal / utánvét),
 * rendelés részletei, címek, „Mi történik most?” idővonal.
 *
 * @see checkout/thankyou.php (WooCommerce 8.1)
 * @var WC_Order|false $order
 */

defined('ABSPATH') || exit;

$icon = 'mandala_icon';
?>
<div class="woocommerce-order">
<?php if (!$order) : ?>
  <h1 class="iu-title" style="font-size:var(--fs-h2);text-align:center"><?php esc_html_e('Rendelés visszaigazolása', 'mandala'); ?></h1>
  <div class="woocommerce-info" role="status"><?php echo $icon('info'); // phpcs:ignore ?><span><?php esc_html_e('Köszönjük, megkaptuk a rendelésed. A visszaigazolást e-mailben elküldtük.', 'mandala'); ?></span></div>
<?php else :
    do_action('woocommerce_before_thankyou', $order->get_id());
    if ($order->has_status('failed')) : ?>
  <h1 class="iu-title" style="font-size:var(--fs-h2);text-align:center"><?php esc_html_e('A fizetés nem sikerült', 'mandala'); ?></h1>
  <div class="woocommerce-error" role="alert"><strong><?php echo $icon('alert'); // phpcs:ignore ?> <?php esc_html_e('A bank vagy a fizetési szolgáltató elutasította a tranzakciót.', 'mandala'); ?></strong>
    <span><?php esc_html_e('A rendelésed megmaradt, újra megpróbálhatod a fizetést – akár másik kártyával vagy fizetési móddal.', 'mandala'); ?></span></div>
  <div class="iu-button-group iu-button-group-center"><a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="iu-button"><?php esc_html_e('Fizetés újra', 'mandala'); ?></a>
    <?php if (is_user_logged_in()) : ?><a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="iu-button iu-button-outline"><?php esc_html_e('Fiókom', 'mandala'); ?></a><?php endif; ?></div>
    <?php else :
        $method = $order->get_payment_method();
        $shipping = $order->get_shipping_methods();
        $ship = $shipping ? reset($shipping) : null;
        $ship_id = $ship ? $ship->get_method_id() : '';
        $kind = $ship ? mandala_shipping_kind($ship_id . ':' . $ship->get_instance_id(), $ship->get_name()) : 'courier';
        $pickup = $kind === 'pickup';
        $point = $kind === 'point';
        $total = wp_strip_all_tags($order->get_formatted_order_total());
        $copy = fn($value, $label) => '<button type="button" class="copy-btn" data-copy="' . esc_attr($value) . '" aria-label="' . esc_attr(sprintf(__('%s másolása', 'mandala'), $label)) . '">' . $icon('copy', 'ico ico-s') . ' ' . esc_html__('Másolás', 'mandala') . '</button>';
        ?>
  <div class="thankyou-hero">
    <span class="seal"><?php echo $icon('check', 'ico'); // phpcs:ignore ?></span>
    <h1 class="iu-title" style="font-size:clamp(2.25rem,4.5vw,3.25rem);margin:0"><?php echo esc_html(sprintf(__('Köszönjük, %s!', 'mandala'), $order->get_billing_first_name() ?: __('kedves vásárlónk', 'mandala'))); ?></h1>
    <p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received"><?php echo wp_kses_post(sprintf(__('Megkaptuk a rendelésed. A visszaigazolást elküldtük a(z) <strong>%s</strong> címre – ha nem látod, nézd meg a Promóciók vagy a Spam mappát is.', 'mandala'), esc_html($order->get_billing_email()))); ?></p>
  </div>

  <ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details">
    <li class="woocommerce-order-overview__order order"><?php esc_html_e('Rendelésszám', 'mandala'); ?><strong class="num" translate="no">#<?php echo esc_html($order->get_order_number()); ?></strong></li>
    <li class="woocommerce-order-overview__date date"><?php esc_html_e('Dátum', 'mandala'); ?><strong translate="no"><?php echo esc_html(wc_format_datetime($order->get_date_created(), 'Y. F j.')); ?></strong></li>
    <li class="woocommerce-order-overview__email email"><?php esc_html_e('E-mail', 'mandala'); ?><strong translate="no"><?php echo esc_html($order->get_billing_email()); ?></strong></li>
    <li class="woocommerce-order-overview__total total"><?php esc_html_e('Végösszeg', 'mandala'); ?><strong class="num" translate="no"><?php echo esc_html($total); ?></strong></li>
    <li class="woocommerce-order-overview__payment-method method"><?php esc_html_e('Fizetési mód', 'mandala'); ?><strong><?php echo wp_kses_post($order->get_payment_method_title()); ?></strong></li>
  </ul>

  <?php if ($method === 'bacs') :
      $bank = mandala_config('bank', []);
      $accounts = (array) get_option('woocommerce_bacs_accounts', []);
      if (!empty($accounts[0]['account_number'])) {
          $bank = ['holder' => $accounts[0]['account_name'] ?? '', 'name' => $accounts[0]['bank_name'] ?? '', 'account' => $accounts[0]['account_number'], 'iban' => $accounts[0]['iban'] ?? ''];
      } ?>
  <section class="woocommerce-bacs-bank-details panel" aria-labelledby="bacs-title">
    <h2 class="wc-bacs-bank-details-heading" id="bacs-title" style="font-size:var(--fs-h3)"><?php esc_html_e('Utalási adatok', 'mandala'); ?></h2>
    <p class="text-muted"><?php esc_html_e('A csomagot a jóváírás után adjuk fel. Közleménynek pontosan a rendelésszámot írd.', 'mandala'); ?></p>
    <ul class="wc-bacs-bank-details order_details bacs_details">
      <?php if (!empty($bank['holder'])) : ?><li class="account_name"><?php esc_html_e('Kedvezményezett', 'mandala'); ?><strong><?php echo esc_html($bank['holder']); ?></strong></li><?php endif; ?>
      <?php if (!empty($bank['name'])) : ?><li class="bank_name"><?php esc_html_e('Bank', 'mandala'); ?><strong><?php echo esc_html($bank['name']); ?></strong></li><?php endif; ?>
      <li class="account_number"><?php esc_html_e('Számlaszám', 'mandala'); ?><strong class="num"><?php echo esc_html($bank['account'] ?? ''); ?> <?php echo $copy($bank['account'] ?? '', __('Számlaszám', 'mandala')); // phpcs:ignore ?></strong></li>
      <?php if (!empty($bank['iban'])) : ?><li class="iban">IBAN<strong class="num"><?php echo esc_html($bank['iban']); ?> <?php echo $copy(str_replace(' ', '', $bank['iban']), 'IBAN'); // phpcs:ignore ?></strong></li><?php endif; ?>
      <li><?php esc_html_e('Közlemény', 'mandala'); ?><strong class="num"><?php echo esc_html($order->get_order_number()); ?> <?php echo $copy($order->get_order_number(), __('Közlemény', 'mandala')); // phpcs:ignore ?></strong></li>
      <li><?php esc_html_e('Összeg', 'mandala'); ?><strong class="num"><?php echo esc_html($total); ?> <?php echo $copy((string) round((float) $order->get_total()), __('Összeg', 'mandala')); // phpcs:ignore ?></strong></li>
    </ul>
  </section>
  <?php elseif ($method === 'cod') : ?>
  <div class="woocommerce-info" role="status"><?php echo $icon('cash'); // phpcs:ignore ?><span><strong><?php echo esc_html($pickup ? __('Fizetés átvételkor', 'mandala') : __('Utánvét', 'mandala')); ?>:</strong> <?php echo esc_html($total); ?> – <?php echo esc_html($pickup ? __('a bemutatóteremben készpénzzel vagy bankkártyával.', 'mandala') : ($point ? __('a GLS ponton kártyával vagy készpénzzel.', 'mandala') : __('a futárnál készpénzzel vagy bankkártyával.', 'mandala'))); ?></span></div>
  <?php elseif ($order->is_paid()) : ?>
  <div class="woocommerce-message" role="status"><?php echo $icon('check-circle'); // phpcs:ignore ?><span><strong><?php esc_html_e('Sikeres fizetés.', 'mandala'); ?></strong> <?php esc_html_e('A rendelésed feldolgozás alatt van.', 'mandala'); ?></span></div>
  <?php endif; ?>

  <?php
  // Fizetési átjárók és mérőkódok üzenetei (a rendelés részleteit mi rajzoljuk ki lent).
  ob_start();
  do_action('woocommerce_thankyou_' . $method, $order->get_id());
  do_action('woocommerce_thankyou', $order->get_id());
  $hooks = trim((string) ob_get_clean());
  if ($hooks) {
      echo '<div class="thankyou-extra">' . $hooks . '</div>'; // phpcs:ignore
  }
  ?>

  <div class="iu-row" style="width:100%;gap:var(--space-6)">
    <div class="iu-column iu-column-2-3" style="display:grid;gap:var(--space-6);align-content:start">
      <section class="woocommerce-order-details panel" aria-labelledby="details-title">
        <h2 class="woocommerce-order-details__title" id="details-title" style="font-size:var(--fs-h3)"><?php esc_html_e('Rendelés részletei', 'mandala'); ?></h2>
        <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
          <thead><tr><th class="woocommerce-table__product-name product-name"><?php esc_html_e('Termék', 'mandala'); ?></th><th class="woocommerce-table__product-table product-total"><?php esc_html_e('Összesen', 'mandala'); ?></th></tr></thead>
          <tbody>
          <?php foreach ($order->get_items() as $item) :
              $p = $item->get_product(); ?>
            <tr class="woocommerce-table__line-item order_item"><td class="woocommerce-table__product-name product-name"><?php echo esc_html($item->get_name()); ?> <strong class="product-quantity">×&nbsp;<?php echo (int) $item->get_quantity(); ?></strong><?php if ($p && $p->get_sku()) : ?><br><span class="text-muted text-small"><?php echo esc_html(sprintf(__('Cikkszám: %s', 'mandala'), $p->get_sku())); ?></span><?php endif; ?></td>
              <td class="woocommerce-table__product-total product-total"><?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?></td></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
          <?php foreach ($order->get_order_item_totals() as $key => $row) : ?>
            <tr class="<?php echo esc_attr($key); ?>"><th scope="row"><?php echo esc_html($row['label']); ?></th><td><?php echo wp_kses_post($row['value']); ?></td></tr>
          <?php endforeach; ?>
          <?php if ($order->get_customer_note()) : ?>
            <tr><th scope="row"><?php esc_html_e('Megjegyzés:', 'mandala'); ?></th><td style="text-align:left"><?php echo wp_kses_post(nl2br(wptexturize($order->get_customer_note()))); ?></td></tr>
          <?php endif; ?>
          </tfoot>
        </table>
      </section>
      <section class="woocommerce-customer-details panel" aria-labelledby="addr-title">
        <h2 id="addr-title" style="font-size:var(--fs-h3)"><?php esc_html_e('Címek', 'mandala'); ?></h2>
        <div class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
          <div><h3 style="font-size:var(--fs-h5)"><?php esc_html_e('Számlázási adatok', 'mandala'); ?></h3><address><?php echo wp_kses_post($order->get_formatted_billing_address()); ?><br><?php echo esc_html($order->get_billing_phone()); ?><br><?php echo esc_html($order->get_billing_email()); ?></address></div>
          <div><h3 style="font-size:var(--fs-h5)"><?php esc_html_e('Szállítás', 'mandala'); ?></h3><address><?php echo esc_html($ship ? $ship->get_name() : ''); ?><br>
            <?php if ($pickup) {
                $c = mandala_config('contact', []);
                echo esc_html__('Mandala bemutatóterem, Budapest', 'mandala') . '<br>' . esc_html($c['hours'] ?? '');
            } else {
                echo wp_kses_post($order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address());
            } ?></address></div>
        </div>
      </section>
    </div>
    <div class="iu-column iu-column-1-3" style="display:grid;gap:var(--space-6);align-content:start">
      <section class="panel" aria-labelledby="next-title">
        <h2 id="next-title" style="font-size:var(--fs-h4)"><?php esc_html_e('Mi történik most?', 'mandala'); ?></h2>
        <ol class="timeline">
          <li class="is-done"><span class="dot"><?php echo $icon('check', 'ico ico-s'); // phpcs:ignore ?></span><span><strong><?php esc_html_e('Visszaigazolás elküldve', 'mandala'); ?></strong><span><?php echo esc_html($order->get_billing_email()); ?></span></span></li>
          <li><span class="dot">2</span><span><strong><?php echo esc_html($method === 'bacs' ? __('Várjuk az utalást', 'mandala') : __('Csomagoljuk', 'mandala')); ?></strong><span><?php echo esc_html($method === 'bacs' ? __('A jóváírás után azonnal csomagolunk', 'mandala') : __('Általában 1 munkanapon belül', 'mandala')); ?></span></span></li>
          <li><span class="dot">3</span><span><strong><?php echo esc_html($pickup ? __('Átvehető a bemutatóteremben', 'mandala') : ($point ? __('A GLS pontra kerül', 'mandala') : __('Futárnak átadjuk', 'mandala'))); ?></strong><span><?php echo esc_html($pickup ? __('E-mailben értesítünk, amikor készen áll', 'mandala') : ($point ? __('Az átvételi értesítőt SMS-ben és e-mailben kapod', 'mandala') : __('A csomagkövető linket e-mailben küldjük', 'mandala'))); ?></span></span></li>
          <li><span class="dot">4</span><span><strong><?php echo esc_html($pickup ? __('Átveszed', 'mandala') : __('Megérkezik', 'mandala')); ?></strong><span><?php esc_html_e('Jó elcsendesedést!', 'mandala'); ?></span></span></li>
        </ol>
      </section>
      <?php if ($order->get_customer_id()) : ?>
      <div class="woocommerce-message"><?php echo $icon('user'); // phpcs:ignore ?><span><?php echo wp_kses_post(sprintf(__('A rendelésed a <a href="%s">Fiókom</a> oldalon követheted.', 'mandala'), esc_url(wc_get_page_permalink('myaccount')))); ?></span></div>
      <?php endif; ?>
      <div class="iu-button-group"><a class="iu-button iu-button-block" href="<?php echo esc_url(mandala_shop_url()); ?>"><?php esc_html_e('Vásárlás folytatása', 'mandala'); ?></a>
        <?php if ($blog = get_option('page_for_posts')) : ?><a class="iu-button iu-button-link" href="<?php echo esc_url(get_permalink($blog)); ?>"><?php esc_html_e('Olvass a magazinban', 'mandala'); ?></a><?php endif; ?></div>
    </div>
  </div>
    <?php endif;
endif; ?>
</div>
