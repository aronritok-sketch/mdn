<?php
/**
 * mandala/product-breadcrumbs – morzsamenü a termékoldalon: Kezdőlap / Kínálat / kategórialánc / termék.
 *
 * Az iu_theme iu/breadcrumbs blokkja súlyos hibával leáll, ha a terméknek több kategóriája van (pl. fő- és
 * alkategória egyszerre, vagy a Claude migráció után az új és a régi kategória). Ez a blokk a legmélyebb
 * kategóriát választja (a Yoast elsődleges kategóriáját, ha van), és abból rakja össze a láncot.
 */

defined('ABSPATH') || exit;

/** A termék „fő” kategóriája a morzsamenühöz: Yoast elsődleges, különben a legmélyebb (nem „Egyéb”). */
function mandala_primary_product_term(int $product_id): ?WP_Term
{
    $terms = get_the_terms($product_id, 'product_cat');
    if (!$terms || is_wp_error($terms)) {
        return null;
    }
    $primary = (int) get_post_meta($product_id, '_yoast_wpseo_primary_product_cat', true);
    foreach ($primary ? $terms : [] as $term) {
        if ($term->term_id === $primary) {
            return $term;
        }
    }
    $default = (int) get_option('default_product_cat');
    $best = null;
    $best_depth = -1;
    foreach ($terms as $term) {
        if ($term->term_id === $default || str_starts_with($term->slug, 'uncategorized')) {
            continue;
        }
        $depth = count(get_ancestors($term->term_id, 'product_cat'));
        if ($depth > $best_depth) {
            $best = $term;
            $best_depth = $depth;
        }
    }
    return $best ?: $terms[0];
}

mandala_add_block('mandala/product-breadcrumbs', [
    'title' => 'Termék morzsamenü',
    'template' => function () {
        $id = (int) get_queried_object_id();
        if (!$id) {
            return '';
        }
        $items = [[__('Kezdőlap', 'mandala'), home_url('/')], [__('Kínálat', 'mandala'), mandala_shop_url()]];
        $term = mandala_primary_product_term($id);
        if ($term) {
            foreach (array_reverse(get_ancestors($term->term_id, 'product_cat')) as $ancestor) {
                $a = get_term((int) $ancestor, 'product_cat');
                if ($a instanceof WP_Term) {
                    $items[] = [$a->name, get_term_link($a)];
                }
            }
            $items[] = [$term->name, get_term_link($term)];
        }
        $items[] = [get_the_title($id), ''];
        $out = '<nav class="iu-breadcrumbs" aria-label="' . esc_attr__('Morzsamenü', 'mandala') . '"><ol itemscope itemtype="https://schema.org/BreadcrumbList">';
        foreach ($items as $i => [$name, $url]) {
            $link = is_string($url) && $url !== ''
                ? '<a itemprop="item" href="' . esc_url($url) . '"><span itemprop="name">' . esc_html($name) . '</span></a>'
                : '<span itemprop="name" aria-current="page">' . esc_html($name) . '</span>';
            $out .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">' . $link . '<meta itemprop="position" content="' . ($i + 1) . '"></li>';
        }
        return $out . '</ol></nav>';
    },
]);
