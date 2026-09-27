<?php
/** mandala/hero-pick – „A hét hangtála”: kiemelt termék a hős szekció sarkában. */

defined('ABSPATH') || exit;

mandala_add_block('mandala/hero-pick', [
    'title' => 'Kiemelt termék (hős)',
    'attributes' => [
        'sku' => mandala_attr_def('MND-HT-0560'),
        'label' => mandala_attr_def('A hét hangtála'),
    ],
    'fields' => [['panel' => 'Termék', 'fields' => [
        'sku' => ['type' => 'text', 'label' => 'Cikkszám (üresen a legújabb kiemelt)'],
        'label' => ['type' => 'text', 'label' => 'Felirat'],
    ]]],
    'template' => function ($attributes) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        $id = !empty($attributes['sku']) ? wc_get_product_id_by_sku($attributes['sku']) : 0;
        if (!$id) {
            $ids = wc_get_featured_product_ids();
            $id = $ids ? max($ids) : 0;
        }
        if (!$id) {
            // Nincs ilyen cikkszám és kiemelt termék sem: a legkelendőbb, fotós, raktáron lévő hangtál.
            foreach (wc_get_products(['status' => 'publish', 'category' => ['hangtalak'], 'stock_status' => 'instock', 'limit' => 12, 'return' => 'ids',
                'meta_key' => 'total_sales', 'orderby' => ['meta_value_num' => 'DESC', 'date' => 'DESC']]) as $cand) {
                if (get_post_thumbnail_id($cand)) {
                    $id = $cand;
                    break;
                }
            }
        }
        $product = $id ? wc_get_product($id) : null;
        if (!$product || $product->get_status() !== 'publish') {
            return '';
        }
        $hz = get_post_meta($id, '_mandala_hz', true);
        return '<a class="hero-pick" href="' . esc_url(get_permalink($id)) . '"><span class="thumb">' . mandala_product_image($product) . '</span><span><small>'
            . esc_html($attributes['label']) . '</small><strong>' . esc_html($product->get_name()) . '</strong><span class="num">'
            . esc_html(mandala_fmt($product->get_price()) . ($hz ? ' · ' . $hz . ' Hz' : '')) . '</span></span></a>';
    },
]);
