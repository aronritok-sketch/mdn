<?php
/**
 * mandala/product-summary – a termékoldal jobb oszlopa: eredet, cikkszám, H1, ár + ÁFA,
 * rövid leírás, készlet, kosárba (mennyiség készletkorláttal) vagy készletértesítő,
 * eredetkártya, adatlap, garanciák, ragadós kosárba sáv. Product JSON-LD.
 */

defined('ABSPATH') || exit;

function mandala_stock_html(WC_Product $product): string
{
    [$stock, $qty] = mandala_stock($product);
    if ($stock === 'out') {
        return '<p class="stock out-of-stock">' . esc_html__('Elfogyott – értesítést kérhetsz', 'mandala') . '</p>';
    }
    if ($stock === 'low') {
        return '<p class="stock low-stock">' . esc_html(sprintf(__('Utolsó %d db raktáron', 'mandala'), $qty)) . '</p>';
    }
    return '<p class="stock in-stock">' . esc_html__('Raktáron, 1–2 munkanapon belül feladjuk', 'mandala') . '</p>';
}

/** Bruttó árból az ÁFA-tartalom (27%). */
function mandala_vat_of(float $gross): float
{
    $rate = (float) mandala_config('vatRate', 27);
    return round($gross - $gross / (1 + $rate / 100));
}

mandala_add_block('mandala/product-summary', [
    'title' => 'Termék összegzés (ár, készlet, kosárba)',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        $product = mandala_current_product();
        if (!$product) {
            return '';
        }
        $id = $product->get_id();
        [$stock, $qty] = mandala_stock($product);
        $out = $stock === 'out';
        [$cat, $sub] = mandala_product_cats($id);
        $origin = mandala_attr($product, 'pa_eredet', 'slug')[0] ?? '';
        $origin_label = mandala_attr($product, 'pa_eredet')[0] ?? '';
        $place = get_post_meta($id, '_mandala_place', true) ?: $origin_label;
        $free = (int) mandala_config('freeShippingFrom', 25000);
        $max = $product->get_max_purchase_quantity();
        $max = $max > 0 ? min(99, $max) : 99;
        $icon = 'mandala_icon';

        ob_start();
        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }
        ?>
<div class="product-summary">
  <div class="product-meta-row"><span class="origin origin-<?php echo esc_attr($origin); ?>"><?php echo esc_html($origin_label); ?></span><span><?php echo esc_html(mandala_term_name($sub ?: $cat)); ?></span><?php if ($product->get_sku()) : ?><span class="sku_wrapper"><?php esc_html_e('Cikkszám:', 'mandala'); ?> <span class="sku"><?php echo esc_html($product->get_sku()); ?></span></span><?php endif; ?></div>
  <h1 class="product_title iu-title"><?php echo esc_html($product->get_name()); ?></h1>
  <div class="price-row"><span class="price"><?php echo $product->get_price_html(); // phpcs:ignore ?></span><span class="tax-note"><?php echo esc_html(sprintf(__('Tartalmazza a %1$d%% ÁFÁ-t (%2$s)', 'mandala'), (int) mandala_config('vatRate', 27), mandala_fmt(mandala_vat_of((float) wc_get_price_to_display($product))))); ?></span></div>
  <?php if ($product->get_short_description()) : ?><div class="woocommerce-product-details__short-description"><?php echo wp_kses_post(wpautop($product->get_short_description())); ?></div><?php endif; ?>
  <?php echo mandala_stock_html($product); // phpcs:ignore ?>
  <?php if ($out) : ?>
  <div class="product-notify"><strong><?php esc_html_e('Értesítünk, ha újra raktáron lesz', 'mandala'); ?></strong>
    <form class="mandala-form" data-mandala-form="stock-notify" novalidate data-success="<?php esc_attr_e('Rendben! Írunk, amint újra elérhető.', 'mandala'); ?>">
      <input type="hidden" name="product" value="<?php echo (int) $id; ?>">
      <div class="nl-row"><div class="iu-form-field"><label class="sr-only" for="notify-email"><?php esc_html_e('E-mail-cím', 'mandala'); ?></label><input type="email" id="notify-email" name="email" autocomplete="email" placeholder="nev@pelda.hu" required data-validate="<?php echo esc_attr("required|" . __('Add meg az e-mail-címed.', 'mandala') . "\nemail|" . __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala')); ?>"></div><button class="iu-button" type="submit"><?php esc_html_e('Értesítést kérek', 'mandala'); ?></button></div>
      <div class="check-row"><label class="iu-form-accept" for="notify-accept"><input type="checkbox" id="notify-accept" name="accept" data-validate="required|<?php esc_attr_e('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'mandala'); ?>"> <span><?php echo wp_kses_post(sprintf(__('Elfogadom az <a href="%s">adatkezelési tájékoztatót</a>.', 'mandala'), esc_url(get_privacy_policy_url()))); ?></span></label></div>
      <p class="form-message" role="status"></p>
    </form>
  </div>
  <?php elseif ($product->is_type('simple') && $product->is_purchasable()) : ?>
  <form class="cart" action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); ?>" method="post" enctype="multipart/form-data" data-cart-form data-product-id="<?php echo (int) $id; ?>">
    <div class="quantity" data-qty><button type="button" data-step="-1" aria-label="<?php esc_attr_e('Eggyel kevesebb', 'mandala'); ?>"><?php echo $icon('minus'); // phpcs:ignore ?></button>
      <input type="number" inputmode="numeric" name="quantity" min="1" max="<?php echo (int) $max; ?>" value="1" aria-label="<?php esc_attr_e('Mennyiség', 'mandala'); ?>"><button type="button" data-step="1" aria-label="<?php esc_attr_e('Eggyel több', 'mandala'); ?>"><?php echo $icon('plus'); // phpcs:ignore ?></button></div>
    <button type="submit" name="add-to-cart" value="<?php echo (int) $id; ?>" class="iu-button iu-button-large single_add_to_cart_button"><?php echo $icon('bag'); // phpcs:ignore ?> <?php esc_html_e('Kosárba teszem', 'mandala'); ?></button>
    <button type="button" class="iu-button iu-button-outline iu-button-large product-wish" data-wish="<?php echo (int) $id; ?>" aria-pressed="<?php echo mandala_wishlist_has($id) ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr(sprintf(__('Kedvencekhez: %s', 'mandala'), $product->get_name())); ?>"><?php echo $icon('heart'); // phpcs:ignore ?></button>
  </form>
  <?php else : ?>
  <div class="mandala-variable-cart"><?php woocommerce_template_single_add_to_cart(); ?></div>
  <?php endif; ?>
  <?php if ($place) : ?>
  <div class="origin-card"><?php echo $icon('pin'); // phpcs:ignore ?><p><strong><?php echo esc_html(sprintf(__('Eredet: %s', 'mandala'), $place)); ?></strong><?php esc_html_e('Közvetlenül a készítő műhelytől hozzuk be – minden darabot átnézünk, mielőtt a polcra kerül.', 'mandala'); ?></p></div>
  <?php endif; ?>
  <?php
        $rows = [];
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->get_visible() && !in_array($attribute->get_name(), ['pa_szandek', 'pa_eredet', 'pa_regio'], true)) {
                $rows[wc_attribute_label($attribute->get_name())] = $product->get_attribute($attribute->get_name());
            }
        }
        if ($hz = get_post_meta($id, '_mandala_hz', true)) {
            $rows = [__('Frekvencia', 'mandala') => $hz . ' Hz'] + $rows;
        }
        if ($suly = get_post_meta($id, '_mandala_suly', true)) {
            $rows = [__('Súly', 'mandala') => $suly . ' g'] + $rows;
        }
        if ($rows) : ?>
  <table class="woocommerce-product-attributes shop_attributes"><caption class="sr-only"><?php esc_html_e('Termékadatok', 'mandala'); ?></caption><tbody>
    <?php foreach ($rows as $k => $v) : ?><tr><th scope="row"><?php echo esc_html($k); ?></th><td><?php echo esc_html(wp_strip_all_tags($v)); ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <ul class="product-assurance">
    <li><?php echo $icon('truck'); // phpcs:ignore ?><span><?php echo esc_html((float) $product->get_price() >= $free ? __('Ingyenes szállítás erre a termékre', 'mandala') : sprintf(__('Ingyenes szállítás %s felett', 'mandala'), mandala_fmt($free))); ?></span></li>
    <li><?php echo $icon('store'); // phpcs:ignore ?><span><?php esc_html_e('Személyesen is átveheted budapesti bemutatótermünkben', 'mandala'); ?></span></li>
    <li><?php echo $icon('return'); // phpcs:ignore ?><span><?php esc_html_e('14 napon belül indoklás nélkül visszaküldheted', 'mandala'); ?></span></li>
  </ul>
</div>
<?php if (!$out && $product->is_type('simple') && $product->is_purchasable()) : ?>
<div class="sticky-atc" data-sticky-atc aria-hidden="true"><div class="iu-row">
  <span class="thumb"><?php echo mandala_product_image($product, 'thumbnail'); // phpcs:ignore ?></span><span class="title"><?php echo esc_html($product->get_name()); ?></span><strong class="num hide-mobile"><?php echo esc_html(mandala_fmt(wc_get_price_to_display($product))); ?></strong>
  <button type="button" class="iu-button" data-add-sticky tabindex="-1"><?php echo $icon('bag', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Kosárba', 'mandala'); ?> · <?php echo esc_html(mandala_fmt(wc_get_price_to_display($product))); ?></button></div></div>
<?php endif;
        // Strukturált adat: a WooCommerce saját Product JSON-LD-je (a loop nélküli sablon miatt kézzel hívjuk).
        if (isset(WC()->structured_data)) {
            WC()->structured_data->generate_product_data($product);
        }
        return (string) ob_get_clean();
    },
]);
