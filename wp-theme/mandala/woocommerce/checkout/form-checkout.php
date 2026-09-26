<?php
/**
 * Mandala pénztár – 5 lépés egy oldalon (klasszikus WooCommerce pénztár).
 *
 * 1. Elérhetőség  2. Szállítási mód  3. Cím (cég + adószám)  4. Fizetés  5. Megrendelés
 * A jobb oldali összesítő (#order_review) és a fizetési blokk (#payment) a WooCommerce
 * saját AJAX fragmentjeivel frissül; a szállítási blokkot a mandala fragment cseréli.
 *
 * @see https://woocommerce.com/document/template-structure/ (eredeti: checkout/form-checkout.php, 9.4)
 * @var WC_Checkout $checkout
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_checkout_form', $checkout);

if (!$checkout->is_registration_enabled() && $checkout->is_registration_required() && !is_user_logged_in()) {
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('You must be logged in to checkout.', 'woocommerce')));
    return;
}

$billing = $checkout->get_checkout_fields('billing');
$contact_keys = ['billing_email', 'billing_phone'];
$is_company = (bool) $checkout->get_value('billing_company') || !empty($_POST['is_company']); // phpcs:ignore
$account_url = wc_get_page_permalink('myaccount');
$contact = mandala_config('contact', []);
$icon = 'mandala_icon';
?>
<h1 class="sr-only"><?php esc_html_e('Pénztár', 'mandala'); ?></h1>
<form name="checkout" method="post" class="checkout woocommerce-checkout mandala-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" novalidate aria-label="<?php esc_attr_e('Pénztár', 'mandala'); ?>">
  <div class="checkout-main" id="customer_details">
    <?php do_action('woocommerce_checkout_before_customer_details'); ?>

    <section class="checkout-section" id="section-contact" aria-labelledby="h-contact">
      <h3 id="h-contact"><span class="step-dot">1</span><?php esc_html_e('Elérhetőség', 'mandala'); ?>
        <?php if (!is_user_logged_in()) : ?><a class="section-aside" href="<?php echo esc_url(add_query_arg('redirect_to', rawurlencode(wc_get_checkout_url()), $account_url)); ?>"><?php esc_html_e('Van fiókod? Belépés', 'mandala'); ?></a><?php endif; ?></h3>
      <div class="field-wrapper">
        <?php foreach ($contact_keys as $key) {
            if (isset($billing[$key])) {
                woocommerce_form_field($key, $billing[$key], $checkout->get_value($key));
            }
        } ?>
      </div>
    </section>

    <?php if (WC()->cart->needs_shipping()) : ?>
    <section class="checkout-section" id="section-shipping" aria-labelledby="h-shipping">
      <h3 id="h-shipping"><span class="step-dot">2</span><?php esc_html_e('Szállítási mód', 'mandala'); ?></h3>
      <fieldset style="border:0;margin:0;padding:0"><legend class="sr-only"><?php esc_html_e('Szállítási mód', 'mandala'); ?></legend>
        <?php echo mandala_checkout_shipping_html(); // phpcs:ignore ?>
      </fieldset>
    </section>
    <?php endif; ?>

    <section class="checkout-section woocommerce-billing-fields" id="section-billing" aria-labelledby="h-billing">
      <h3 id="h-billing"><span class="step-dot"><?php echo WC()->cart->needs_shipping() ? 3 : 2; ?></span><span data-billing-title><?php esc_html_e('Szállítási és számlázási cím', 'mandala'); ?></span></h3>
      <?php do_action('woocommerce_before_checkout_billing_form', $checkout); ?>
      <div class="woocommerce-billing-fields__field-wrapper">
        <?php
        foreach ($billing as $key => $field) {
            if (in_array($key, $contact_keys, true)) {
                continue;
            }
            if ($key === 'billing_company') {
                echo '<div class="toggle-row"><div class="check-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="is_company"><input type="checkbox" class="woocommerce-form__input input-checkbox" name="is_company" id="is_company" value="1"' . checked($is_company, true, false) . '> <span>'
                    . esc_html__('Cégként vásárolok (ÁFÁ-s számla cégnévre)', 'mandala') . '</span></label></div></div><div class="reveal-fields" data-company' . ($is_company ? '' : ' hidden') . '>';
            }
            woocommerce_form_field($key, $field, $checkout->get_value($key));
            if ($key === 'billing_tax_number') {
                echo '</div>';
            }
        }
        ?>
      </div>
      <?php do_action('woocommerce_after_checkout_billing_form', $checkout); ?>

      <?php if (WC()->cart->needs_shipping_address()) : ?>
      <div class="woocommerce-shipping-fields">
        <div class="toggle-row" id="ship-to-different-address" data-ship-diff-row><div class="check-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="ship-to-different-address-checkbox">
          <input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked(apply_filters('woocommerce_ship_to_different_address_checked', 'shipping' === get_option('woocommerce_ship_to_destination') ? 1 : 0), 1); ?> type="checkbox" name="ship_to_different_address" value="1"> <span><?php esc_html_e('Másik címre kérem a szállítást', 'mandala'); ?></span></label></div></div>
        <div class="shipping_address reveal-fields">
          <p class="field-hint"><?php esc_html_e('A futár erre a címre viszi a csomagot; a számla a fenti címre szól.', 'mandala'); ?></p>
          <?php do_action('woocommerce_before_checkout_shipping_form', $checkout); ?>
          <div class="woocommerce-shipping-fields__field-wrapper">
            <?php foreach ($checkout->get_checkout_fields('shipping') as $key => $field) {
                woocommerce_form_field($key, $field, $checkout->get_value($key));
            } ?>
          </div>
          <?php do_action('woocommerce_after_checkout_shipping_form', $checkout); ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

    <section class="checkout-section" id="section-payment" aria-labelledby="h-payment">
      <h3 id="h-payment"><span class="step-dot"><?php echo WC()->cart->needs_shipping() ? 4 : 3; ?></span><?php esc_html_e('Fizetés', 'mandala'); ?><span class="section-aside text-muted"><?php echo $icon('lock', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Titkosított', 'mandala'); ?></span></h3>
      <?php woocommerce_checkout_payment(); ?>
    </section>

    <section class="checkout-section" id="section-review" aria-labelledby="h-review">
      <h3 id="h-review"><span class="step-dot"><?php echo WC()->cart->needs_shipping() ? 5 : 4; ?></span><?php esc_html_e('Megrendelés', 'mandala'); ?></h3>
      <div class="woocommerce-additional-fields" style="margin-bottom:var(--space-5)">
        <?php do_action('woocommerce_before_order_notes', $checkout); ?>
        <?php $order_fields = $checkout->get_checkout_fields('order'); if ($order_fields) : ?>
        <details class="review-coupon" style="border:0;padding:0"<?php echo $checkout->get_value('order_comments') ? ' open' : ''; ?>><summary><?php esc_html_e('Megjegyzés vagy ajándékkártya szövege', 'mandala'); ?> <?php echo $icon('chevron', 'ico ico-s'); // phpcs:ignore ?></summary>
          <div class="woocommerce-additional-fields__field-wrapper" style="margin-top:var(--space-3)">
            <?php foreach ($order_fields as $key => $field) {
                woocommerce_form_field($key, $field, $checkout->get_value($key));
            } ?>
          </div>
        </details>
        <?php endif; ?>
        <?php do_action('woocommerce_after_order_notes', $checkout); ?>
      </div>

      <div class="woocommerce-terms-and-conditions-wrapper mandala-consents">
        <?php if (!is_user_logged_in() && $checkout->is_registration_enabled()) : ?>
        <div class="woocommerce-account-fields">
          <?php if (!$checkout->is_registration_required()) : ?>
          <p class="form-row form-row-wide create-account check-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="createaccount"><input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createaccount" <?php checked(true === $checkout->get_value('createaccount') || true === apply_filters('woocommerce_create_account_default_checked', false), true); ?> type="checkbox" name="createaccount" value="1"> <span><?php esc_html_e('Fiókot hozok létre a rendelés követéséhez', 'mandala'); ?></span></label></p>
          <?php endif; ?>
          <?php do_action('woocommerce_before_checkout_registration_form', $checkout); ?>
          <?php if ($checkout->get_checkout_fields('account')) : ?>
          <div class="create-account reveal-fields" style="margin:0">
            <?php foreach ($checkout->get_checkout_fields('account') as $key => $field) {
                woocommerce_form_field($key, $field, $checkout->get_value($key));
            } ?>
          </div>
          <?php endif; ?>
          <?php do_action('woocommerce_after_checkout_registration_form', $checkout); ?>
        </div>
        <?php endif; ?>
        <p class="form-row form-row-wide check-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="mandala_newsletter"><input type="checkbox" class="woocommerce-form__input input-checkbox" name="mandala_newsletter" id="mandala_newsletter" value="1"<?php checked(!empty($_POST['mandala_newsletter'])); // phpcs:ignore ?>> <span><?php esc_html_e('Feliratkozom a hírlevélre (havonta kétszer, bármikor leiratkozhatok)', 'mandala'); ?></span></label></p>
        <?php wc_get_template('checkout/terms.php'); ?>
      </div>

      <div class="form-row place-order" style="margin-top:var(--space-5)">
        <noscript><?php esc_html_e('A böngésződben ki van kapcsolva a JavaScript: a végösszeg frissítéséhez kattints a „Végösszeg frissítése” gombra.', 'mandala'); ?><br><button type="submit" class="iu-button iu-button-outline" name="woocommerce_checkout_update_totals" value="1"><?php esc_html_e('Végösszeg frissítése', 'mandala'); ?></button></noscript>
        <?php do_action('woocommerce_review_order_before_submit'); ?>
        <?php $label = apply_filters('woocommerce_order_button_text', __('Fizetési kötelezettséggel járó megrendelés', 'mandala')); ?>
        <?php echo apply_filters('woocommerce_order_button_html', '<button type="submit" class="iu-button iu-button-large alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr($label) . '" data-value="' . esc_attr($label) . '">' . esc_html($label) . ' · <span class="num mandala-place-total">' . wp_strip_all_tags(WC()->cart->get_total()) . '</span></button>'); // phpcs:ignore ?>
        <?php do_action('woocommerce_review_order_after_submit'); ?>
        <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
        <p class="place-order-note" data-place-note></p>
      </div>
    </section>

    <?php do_action('woocommerce_checkout_after_customer_details'); ?>
  </div>

  <aside class="checkout-aside" aria-labelledby="order_review_heading" data-aside>
    <button type="button" class="mobile-summary-toggle" aria-expanded="false" aria-controls="order_review" data-summary-toggle><span><?php echo $icon('bag', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Rendelés összesítő', 'mandala'); ?> <span class="text-muted">(<?php echo (int) WC()->cart->get_cart_contents_count(); ?> <?php esc_html_e('db', 'mandala'); ?>)</span></span><strong class="num mandala-summary-total"><?php echo wp_kses_post(WC()->cart->get_total()); ?></strong></button>
    <div class="summary-body">
      <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
      <h2 id="order_review_heading"><?php esc_html_e('Rendelésed', 'mandala'); ?></h2>
      <?php do_action('woocommerce_checkout_before_order_review'); ?>
      <div id="order_review" class="woocommerce-checkout-review-order">
        <?php do_action('woocommerce_checkout_order_review'); ?>
      </div>
      <?php do_action('woocommerce_checkout_after_order_review'); ?>
      <ul class="trust-mini">
        <li><?php echo $icon('lock', 'ico ico-s'); // phpcs:ignore ?><?php esc_html_e('Biztonságos, titkosított fizetés', 'mandala'); ?></li>
        <li><?php echo $icon('return', 'ico ico-s'); // phpcs:ignore ?><?php esc_html_e('14 napos visszaküldés', 'mandala'); ?></li>
        <?php if (!empty($contact['phone'])) : ?><li><?php echo $icon('phone', 'ico ico-s'); // phpcs:ignore ?><?php esc_html_e('Segítség:', 'mandala'); ?> <a href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $contact['phone'])); ?>"><?php echo esc_html($contact['phone']); ?></a></li><?php endif; ?>
      </ul>
    </div>
  </aside>
</form>
<?php do_action('woocommerce_after_checkout_form', $checkout); ?>
