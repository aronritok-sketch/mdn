<?php
/**
 * Pénztár összesítő: termékek bélyegképpel, kuponmező, végösszeg ÁFA-tartalommal.
 * A WooCommerce ezt a teljes elemet (.woocommerce-checkout-review-order-table) cseréli frissítéskor.
 *
 * @see checkout/review-order.php (WooCommerce 5.2)
 */

defined('ABSPATH') || exit;

$cart = WC()->cart;
$icon = 'mandala_icon';
$coupons = $cart->get_coupons();
$chosen = mandala_chosen_shipping_id();
$ship_label = '';
foreach (WC()->shipping()->get_packages() as $i => $package) {
    foreach ($package['rates'] ?? [] as $rate) {
        if ($rate->get_id() === $chosen) {
            $ship_label = $rate->get_label();
            $ship_cost = (float) $rate->get_cost() + array_sum($rate->get_taxes());
        }
    }
}
?>
<div class="woocommerce-checkout-review-order-table">
  <ul class="review-items" aria-label="<?php esc_attr_e('Termékek', 'mandala'); ?>">
    <?php do_action('woocommerce_review_order_before_cart_contents'); ?>
    <?php foreach ($cart->get_cart() as $key => $item) :
        $product = apply_filters('woocommerce_cart_item_product', $item['data'], $item, $key);
        if (!$product instanceof WC_Product || !$product->exists() || $item['quantity'] <= 0 || !apply_filters('woocommerce_checkout_cart_item_visible', true, $item, $key)) {
            continue;
        }
        $name = apply_filters('woocommerce_cart_item_name', $product->get_name(), $item, $key); ?>
    <li class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $item, $key)); ?>">
      <span class="thumb"><?php echo mandala_product_image($product, 'thumbnail'); // phpcs:ignore ?><span class="qty-badge" aria-label="<?php echo esc_attr(sprintf(__('%d darab', 'mandala'), $item['quantity'])); ?>"><?php echo (int) $item['quantity']; ?></span></span>
      <span><span class="name"><?php echo wp_kses_post($name); ?></span><small><?php echo (int) $item['quantity']; ?> × <?php echo esc_html(mandala_fmt(wc_get_price_to_display($product))); ?></small><?php echo wc_get_formatted_cart_item_data($item); // phpcs:ignore ?></span>
      <strong class="num"><?php echo apply_filters('woocommerce_cart_item_subtotal', $cart->get_product_subtotal($product, $item['quantity']), $item, $key); // phpcs:ignore ?></strong>
    </li>
    <?php endforeach; ?>
    <?php do_action('woocommerce_review_order_after_cart_contents'); ?>
  </ul>
  <p style="margin:calc(-1 * var(--space-2)) 0 var(--space-3);text-align:right"><a class="text-small" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Kosár módosítása', 'mandala'); ?></a></p>

  <?php if (wc_coupons_enabled()) : ?>
  <details class="review-coupon" data-coupon-details><summary><?php $applied = array_merge(array_map('strtoupper', array_keys($coupons)), function_exists('mandala_voucher_rows') ? array_keys(mandala_voucher_rows()) : []); echo $applied ? esc_html(sprintf(__('Beváltva: %s', 'mandala'), implode(', ', $applied))) : esc_html__('Kuponkód vagy ajándékutalvány', 'mandala'); ?> <?php echo $icon('chevron', 'ico ico-s'); // phpcs:ignore ?></summary>
    <div class="coupon" data-coupon>
      <?php foreach ($coupons as $code => $coupon) : ?>
      <span class="coupon-applied"><?php echo $icon('check', 'ico ico-s'); // phpcs:ignore ?> <?php echo esc_html(strtoupper($code)); ?>
        <a href="<?php echo esc_url(add_query_arg('remove_coupon', rawurlencode($code), wc_get_checkout_url())); ?>" class="woocommerce-remove-coupon" data-coupon="<?php echo esc_attr($code); ?>" aria-label="<?php esc_attr_e('Kupon eltávolítása', 'mandala'); ?>"><?php echo $icon('close', 'ico ico-s'); // phpcs:ignore ?></a></span>
      <?php endforeach; ?>
      <label class="sr-only" for="checkout_coupon"><?php esc_html_e('Kuponkód', 'mandala'); ?></label><input type="text" class="input-text" id="checkout_coupon" placeholder="<?php esc_attr_e('Kupon- vagy utalványkód', 'mandala'); ?>" autocomplete="off"><button type="button" class="iu-button iu-button-outline" data-coupon-apply><?php esc_html_e('Beváltás', 'mandala'); ?></button><p class="field-error" id="checkout_coupon-error" role="status"></p>
    </div>
  </details>
  <?php endif; ?>
  <?php do_action('mandala_review_after_coupon'); ?>

  <table class="totals-table shop_table"><tbody>
    <tr class="cart-subtotal"><th><?php esc_html_e('Részösszeg', 'mandala'); ?></th><td><?php wc_cart_totals_subtotal_html(); ?></td></tr>
    <?php foreach ($coupons as $code => $coupon) : ?>
    <tr class="discount cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>"><th><?php esc_html_e('Kedvezmény', 'mandala'); ?></th><td>−<?php echo wp_kses_post(wc_price($cart->get_coupon_discount_amount($code, false))); ?></td></tr>
    <?php endforeach; ?>
    <?php if ($cart->needs_shipping() && $cart->show_shipping()) : ?>
      <?php do_action('woocommerce_review_order_before_shipping'); ?>
    <tr class="woocommerce-shipping-totals shipping"><th><?php esc_html_e('Szállítás', 'mandala'); ?><span class="includes_tax"><?php echo esc_html($ship_label ?: __('Válassz szállítási módot', 'mandala')); ?></span></th>
      <td><?php echo $ship_label ? (!empty($ship_cost) ? esc_html(mandala_fmt($ship_cost)) : esc_html__('Ingyenes', 'mandala')) : '–'; ?></td></tr>
      <?php do_action('woocommerce_review_order_after_shipping'); ?>
    <?php endif; ?>
    <?php foreach ($cart->get_fees() as $fee) : ?>
    <tr class="fee"><th><?php echo esc_html($fee->name); ?></th><td><?php echo esc_html(mandala_fmt((float) $fee->total + (float) $fee->tax)); ?></td></tr>
    <?php endforeach; ?>
    <?php do_action('woocommerce_review_order_before_order_total'); ?>
    <tr class="order-total"><th><?php esc_html_e('Fizetendő', 'mandala'); ?></th><td><?php echo wp_kses_post(wc_price($cart->get_total('edit'))); ?>
      <?php if (wc_tax_enabled()) : ?><small class="includes_tax"><?php echo esc_html(sprintf(__('Tartalmaz %1$s ÁFA-t (%2$d%%)', 'mandala'), mandala_fmt($cart->get_total_tax()), (int) mandala_config('vatRate', 27))); ?></small><?php endif; ?></td></tr>
    <?php do_action('woocommerce_review_order_after_order_total'); ?>
  </tbody></table>
</div>
