<?php
/** mandala/product-gallery – termékgaléria (fő kép + bélyegképek), jelvények. */

defined('ABSPATH') || exit;

/** Az aktuális termék (a sablon nem futtat loopot, lásd inc/shop.php „wp” hook). */
function mandala_current_product(): ?WC_Product
{
    global $product;
    if ($product instanceof WC_Product) {
        return $product;
    }
    $p = function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;
    return $p ?: null;
}

function mandala_badges(WC_Product $product): string
{
    [$stock] = mandala_stock($product);
    $out = '';
    if ($stock === 'out') {
        $out .= '<span class="badge badge-dark">' . esc_html__('Elfogyott', 'mandala') . '</span>';
    } elseif ($product->is_on_sale() && (float) $product->get_regular_price() > 0) {
        $out .= '<span class="badge badge-sale">−' . (int) round((1 - (float) $product->get_price() / (float) $product->get_regular_price()) * 100) . '%</span>';
    }
    if ($stock !== 'out' && mandala_is_new($product)) {
        $out .= '<span class="badge">' . esc_html__('Új', 'mandala') . '</span>';
    }
    return '<div class="product-badges">' . $out . '</div>';
}

mandala_add_block('mandala/product-gallery', [
    'title' => 'Termékgaléria',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        $product = mandala_current_product();
        if (!$product) {
            return '';
        }
        $ids = array_values(array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids())));
        $main = $ids
            ? wp_get_attachment_image($ids[0], 'woocommerce_single', false, ['fetchpriority' => 'high', 'alt' => $product->get_name(), 'sizes' => '(max-width: 991px) 100vw, 50vw'])
            : mandala_art_img((string) get_post_meta($product->get_id(), '_mandala_art', true) ?: 'bowl', (string) get_post_meta($product->get_id(), '_mandala_tone', true) ?: 'sand', $product->get_name());
        $views = [];
        // Illusztráció + fotók: ha van fotó és illusztráció is (demó), mindkettő választható.
        foreach ($ids as $i => $id) {
            $views[] = ['full' => wp_get_attachment_image_url($id, 'woocommerce_single'), 'thumb' => wp_get_attachment_image($id, 'thumbnail', false, ['alt' => '', 'loading' => 'lazy'])];
        }
        $thumbs = '';
        if (count($views) > 1) {
            $thumbs = '<ul class="product-gallery-thumbs" aria-label="' . esc_attr__('Termékképek', 'mandala') . '">';
            foreach ($views as $i => $v) {
                $thumbs .= '<li><button type="button" data-view="' . esc_url($v['full']) . '" aria-current="' . ($i ? 'false' : 'true') . '" aria-label="' . esc_attr(sprintf(__('%d. kép', 'mandala'), $i + 1)) . '">' . $v['thumb'] . '</button></li>';
            }
            $thumbs .= '</ul>';
        }
        return '<div class="product-gallery"><div class="product-gallery-main" data-gallery-main>' . $main . mandala_badges($product) . '</div>' . $thumbs . '</div>';
    },
]);
