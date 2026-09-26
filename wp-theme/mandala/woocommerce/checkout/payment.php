<?php
/**
 * Fizetési módok (a pénztár 4. lépése). A megrendelés gomb, az ÁSZF és a nonce a
 * form-checkout.php 5. lépésében van, ezért itt csak a módok listája szerepel.
 * A WooCommerce a „.woocommerce-checkout-payment” fragmentként cseréli.
 *
 * @see checkout/payment.php (WooCommerce 9.8)
 */

defined('ABSPATH') || exit;

if (!wp_doing_ajax()) {
    do_action('woocommerce_review_order_before_payment');
}
$icons = ['bacs' => 'bank', 'cod' => 'cash', 'cheque' => 'bank'];
?>
<div id="payment" class="woocommerce-checkout-payment">
  <?php if (WC()->cart && WC()->cart->needs_payment()) : ?>
  <fieldset style="border:0;margin:0;padding:0"><legend class="sr-only"><?php esc_html_e('Fizetési mód', 'mandala'); ?></legend>
  <ul class="wc_payment_methods payment_methods methods">
    <?php if (!empty($available_gateways)) :
        foreach ($available_gateways as $gateway) :
            $fee = $gateway->id === 'cod' && !mandala_is_pickup() ? (float) (mandala_config('payment')[2]['fee'] ?? 490) : 0; ?>
    <li class="wc_payment_method payment_method_<?php echo esc_attr($gateway->id); ?> method" data-pay="<?php echo esc_attr($gateway->id); ?>">
      <label for="payment_method_<?php echo esc_attr($gateway->id); ?>">
        <input id="payment_method_<?php echo esc_attr($gateway->id); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr($gateway->id); ?>" <?php checked($gateway->chosen, true); ?> data-order_button_text="<?php echo esc_attr($gateway->order_button_text); ?>">
        <span class="method-icon" aria-hidden="true"><?php echo mandala_icon($icons[$gateway->id] ?? 'card'); // phpcs:ignore ?></span>
        <span class="method-text"><strong><?php echo wp_kses_post($gateway->get_title()); ?></strong><?php if ($gateway->get_icon()) : ?><small class="gateway-icon"><?php echo $gateway->get_icon(); // phpcs:ignore ?></small><?php endif; ?></span>
        <span class="method-price"><?php echo $fee ? '+' . esc_html(mandala_fmt($fee)) : ''; ?></span>
      </label>
      <?php if ($gateway->has_fields() || $gateway->get_description()) : ?>
      <div class="payment_box payment_method_<?php echo esc_attr($gateway->id); ?>"<?php echo $gateway->chosen ? '' : ' style="display:none;"'; ?>><?php $gateway->payment_fields(); ?></div>
      <?php endif; ?>
    </li>
        <?php endforeach;
    else : ?>
    <li><?php wc_print_notice(esc_html__('Jelenleg nincs elérhető fizetési mód. Írj nekünk, és segítünk.', 'mandala'), 'notice'); ?></li>
    <?php endif; ?>
  </ul>
  </fieldset>
  <?php endif; ?>
</div>
<?php
if (!wp_doing_ajax()) {
    do_action('woocommerce_review_order_after_payment');
}
