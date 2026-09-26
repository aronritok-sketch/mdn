<?php
/**
 * mandala/products – termékválogatás rácsban vagy karusszelben.
 * Az iu-woocommerce/product-carousel csak a legújabb 12 terméket tudja; ez a blokk
 * válogat is: új, kiemelt, akciós, kategória, cikkszámok, kapcsolódó, keresztértékesítés.
 */

defined('ABSPATH') || exit;

/** Termékazonosítók a válogatás módja szerint. */
function mandala_product_selection(string $mode, int $limit, string $category = '', string $skus = ''): array
{
    // Funkciómodulok saját módjai (pl. „workshop”, „incoming”).
    $custom = apply_filters('mandala_product_selection', null, $mode, $limit);
    if (is_array($custom)) {
        return $custom;
    }
    $base = ['status' => 'publish', 'limit' => $limit, 'return' => 'ids', 'visibility' => 'catalog'];
    switch ($mode) {
        case 'featured':
            return wc_get_products($base + ['featured' => true, 'stock_status' => 'instock', 'orderby' => 'menu_order', 'order' => 'ASC']);
        case 'sale':
            $ids = wc_get_product_ids_on_sale();
            return $ids ? wc_get_products($base + ['include' => $ids, 'stock_status' => 'instock']) : [];
        case 'category':
            return wc_get_products($base + ['category' => array_filter(array_map('trim', explode(',', $category))), 'orderby' => 'menu_order', 'order' => 'ASC']);
        case 'skus':
            $ids = array_filter(array_map(fn($s) => wc_get_product_id_by_sku(trim($s)), explode(',', $skus)));
            return array_slice($ids, 0, $limit);
        case 'related':
        case 'crosssell':
            $product = wc_get_product(get_queried_object_id());
            if (!$product) {
                return [];
            }
            $ids = $mode === 'crosssell' ? $product->get_cross_sell_ids() : [];
            if (count($ids) < $limit) {
                $ids = array_unique(array_merge($ids, wc_get_related_products($product->get_id(), $limit * 2)));
            }
            $ids = array_filter($ids, fn($id) => ($p = wc_get_product($id)) && $p->is_in_stock());
            return array_slice(array_values($ids), 0, $limit);
        case 'cart':
            if (!WC()->cart) {
                return [];
            }
            $in_cart = array_map(fn($i) => $i['product_id'], WC()->cart->get_cart());
            $ids = [];
            foreach ($in_cart as $id) {
                $ids = array_merge($ids, wc_get_product($id)?->get_cross_sell_ids() ?: [], wc_get_related_products($id, 4, $in_cart));
            }
            $ids = array_diff(array_unique($ids), $in_cart);
            return array_slice(array_values(array_filter($ids, fn($id) => wc_get_product($id)?->is_in_stock())), 0, $limit);
        case 'new':
        default:
            return wc_get_products($base + ['orderby' => 'date', 'order' => 'DESC']);
    }
}

mandala_add_block('mandala/products', [
    'title' => 'Termékválogatás',
    'category' => 'iu-woocommerce',
    'attributes' => [
        'mode' => mandala_attr_def('new'),
        'layout' => mandala_attr_def('grid'),
        'limit' => mandala_attr_def('4'),
        'columns' => mandala_attr_def('4'),
        'category' => mandala_attr_def(''),
        'skus' => mandala_attr_def(''),
        'eyebrow' => mandala_attr_def(''),
        'heading' => mandala_attr_def(''),
        'linkText' => mandala_attr_def(''),
        'linkUrl' => mandala_attr_def(''),
    ],
    'fields' => [['panel' => 'Válogatás', 'fields' => [
        'mode' => ['type' => 'select', 'label' => 'Mód', 'options' => [
            ['label' => 'Újdonságok', 'value' => 'new'], ['label' => 'Kiemeltek', 'value' => 'featured'],
            ['label' => 'Akciósak', 'value' => 'sale'], ['label' => 'Kategória', 'value' => 'category'],
            ['label' => 'Cikkszámok', 'value' => 'skus'], ['label' => 'Kapcsolódó (termékoldal)', 'value' => 'related'],
            ['label' => 'Ehhez illik (kosár)', 'value' => 'cart'],
            ['label' => 'Az aktuális műhely termékei', 'value' => 'workshop'],
            ['label' => 'Érkező szállítmány (előrendelhető)', 'value' => 'incoming'],
        ]],
        'layout' => ['type' => 'select', 'label' => 'Elrendezés', 'options' => [
            ['label' => 'Rács', 'value' => 'grid'], ['label' => 'Karusszel', 'value' => 'carousel'],
        ]],
        'limit' => ['type' => 'text', 'label' => 'Darabszám'],
        'columns' => ['type' => 'select', 'label' => 'Oszlopok', 'options' => [
            ['label' => '3', 'value' => '3'], ['label' => '4', 'value' => '4'],
        ]],
        'category' => ['type' => 'text', 'label' => 'Kategória slug(ok), vesszővel'],
        'skus' => ['type' => 'text', 'label' => 'Cikkszámok, vesszővel'],
        'eyebrow' => ['type' => 'text', 'label' => 'Karusszel: felirat'],
        'heading' => ['type' => 'text', 'label' => 'Karusszel: cím'],
        'linkText' => ['type' => 'text', 'label' => 'Karusszel: link szövege'],
        'linkUrl' => ['type' => 'text', 'label' => 'Karusszel: link címe'],
    ]]],
    'template' => function ($attributes) {
        if (!function_exists('wc_get_products')) {
            return '';
        }
        $limit = max(1, min(24, (int) ($attributes['limit'] ?? 4)));
        $ids = mandala_product_selection($attributes['mode'] ?? 'new', $limit, (string) ($attributes['category'] ?? ''), (string) ($attributes['skus'] ?? ''));
        if (!$ids) {
            return '';
        }
        $cards = implode('', array_map(fn($id) => mandala_card(wc_get_product($id)), $ids));
        $cols = (int) ($attributes['columns'] ?? 4) === 3 ? 3 : 4;
        if (($attributes['layout'] ?? 'grid') !== 'carousel') {
            return '<ul class="' . mandala_classes($attributes, 'products', 'columns-' . $cols) . '">' . $cards . '</ul>';
        }
        $heading = $attributes['heading'] ?: __('Újdonságok', 'mandala');
        $hid = 'carousel-' . substr(md5(serialize($attributes)), 0, 6);
        $link = '';
        if (!empty($attributes['linkText'])) {
            $url = do_shortcode(str_replace('[iu_site_url]', trailingslashit(home_url()), (string) $attributes['linkUrl']));
            $link = '<a class="iu-button iu-button-link" href="' . esc_url($url ?: mandala_shop_url(['orderby' => 'date'])) . '">' . esc_html($attributes['linkText']) . '</a>';
        }
        return '<div class="' . mandala_classes($attributes, 'carousel') . '" data-carousel>'
            . '<div class="section-head"><div>' . ($attributes['eyebrow'] ? '<p class="eyebrow">' . esc_html($attributes['eyebrow']) . '</p>' : '')
            . '<h2 id="' . esc_attr($hid) . '">' . esc_html($heading) . '</h2></div>'
            . '<div class="iu-group iu-group-vertical-center">' . $link
            . '<div class="carousel-nav" role="group" aria-label="' . esc_attr(sprintf(__('%s lapozása', 'mandala'), $heading)) . '">'
            . '<button type="button" data-dir="-1" aria-label="' . esc_attr__('Előző', 'mandala') . '">' . mandala_icon('chevron-left') . '</button>'
            . '<button type="button" data-dir="1" aria-label="' . esc_attr__('Következő', 'mandala') . '">' . mandala_icon('chevron-right') . '</button></div></div></div>'
            . '<ul class="products carousel-track" aria-labelledby="' . esc_attr($hid) . '">' . $cards . '</ul>'
            . '<div class="carousel-progress" aria-hidden="true"><span></span></div></div>';
    },
]);
