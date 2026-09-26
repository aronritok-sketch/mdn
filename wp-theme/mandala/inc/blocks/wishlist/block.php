<?php
/** mandala/wishlist – a kedvencek oldal listája (sütiben tárolt azonosítók, lásd inc/wishlist.php). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/wishlist', [
    'title' => 'Kedvencek listája',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        $products = array_filter(array_map('wc_get_product', mandala_wishlist_ids()), fn($p) => $p && $p->get_status() === 'publish');
        if (!$products) {
            return '<div class="empty-state" data-wishlist-empty>' . mandala_icon('heart', 'ico ico-xl') . '<h2 style="font-size:var(--fs-h3)">' . esc_html__('Még nincs kedvenced', 'mandala') . '</h2><p>'
                . esc_html__('A termékkártyák szív ikonjával gyűjtheted ide azokat a darabokat, amelyekre később visszatérnél.', 'mandala') . '</p><a class="iu-button" href="' . esc_url(mandala_shop_url()) . '">' . esc_html__('Kínálat böngészése', 'mandala') . '</a></div>';
        }
        return '<ul class="products columns-4" data-wishlist>' . implode('', array_map('mandala_card', $products)) . '</ul>';
    },
]);
