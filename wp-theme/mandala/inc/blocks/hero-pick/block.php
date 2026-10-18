<?php
/** mandala/hero-pick – „A hét hangtála”: kiemelt termék a hős szekció sarkában. */

defined('ABSPATH') || exit;

/** Valódi hangtál-e: a hangtál-kategóriába tévedt ütők, párnák, alátétek nem azok. */
function mandala_hero_pick_is_bowl($id): bool
{
    $id = (int) $id;
    $slugs = wp_get_post_terms($id, 'product_cat', ['fields' => 'slugs']);
    if (is_wp_error($slugs) || !in_array('hangtalak', $slugs, true)) {
        return false;
    }
    foreach ($slugs as $slug) {
        if (in_array($slug, ['hangtal-gong-utok', 'hangtal-alatetek'], true) || strpos($slug, 'kiegeszitok') === 0) {
            return false;
        }
    }
    return !preg_match('/ütő|párna|alátét/iu', (string) get_the_title($id));
}

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
            $ids = array_filter(wc_get_featured_product_ids(), 'mandala_hero_pick_is_bowl');
            $id = $ids ? (int) max($ids) : 0;
        }
        if (!$id) {
            // Nincs ilyen cikkszám és kiemelt hangtál sem: a legkelendőbb, fotós, raktáron lévő valódi hangtál,
            // a meghallgatható (hangmintás) darabok előre.
            $fallback = 0;
            foreach (wc_get_products(['status' => 'publish', 'category' => ['hangtalak'], 'stock_status' => 'instock', 'limit' => 40, 'return' => 'ids',
                'meta_key' => 'total_sales', 'orderby' => ['meta_value_num' => 'DESC', 'date' => 'DESC']]) as $cand) {
                if (!get_post_thumbnail_id($cand) || !mandala_hero_pick_is_bowl($cand)) {
                    continue;
                }
                if (get_post_meta($cand, '_mandala_audio', true)) {
                    $id = $cand;
                    break;
                }
                $fallback = $fallback ?: $cand;
            }
            $id = $id ?: $fallback;
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
