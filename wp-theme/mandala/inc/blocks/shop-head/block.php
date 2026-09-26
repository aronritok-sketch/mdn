<?php
/**
 * mandala/shop-head – a kínálat fejléce: morzsamenü, H1, bevezető, alkategória chipek.
 * Szerveroldalon a kategória / keresés szerint töltődik ki (SEO), a szűrő JS frissíti.
 */

defined('ABSPATH') || exit;

/** A kínálat aktuális kontextusa: kategória és alkategória slug (archívumból vagy ?cat=&sub=). */
function mandala_shop_context(): array
{
    $cat = sanitize_title(wp_unslash($_GET['cat'] ?? ''));
    $sub = sanitize_title(wp_unslash($_GET['sub'] ?? ''));
    $term = get_queried_object();
    if ($term instanceof WP_Term && $term->taxonomy === 'product_cat') {
        if ($term->parent) {
            $ancestors = get_ancestors($term->term_id, 'product_cat');
            $top = get_term((int) end($ancestors), 'product_cat');
            [$cat, $sub] = [$top->slug, $term->slug];
        } else {
            [$cat, $sub] = [$term->slug, ''];
        }
    }
    return ['cat' => $cat, 'sub' => $sub, 'q' => sanitize_text_field(wp_unslash($_GET['q'] ?? ''))];
}

mandala_add_block('mandala/shop-head', [
    'title' => 'Kínálat fejléc',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        $ctx = mandala_shop_context();
        $tree = mandala_category_tree();
        $current = null;
        foreach ($tree as $c) {
            if ($c['slug'] === $ctx['cat']) {
                $current = $c;
            }
        }
        $title = __('Teljes kínálat', 'mandala');
        $lead = __('Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.', 'mandala');
        if ($current) {
            $title = $current['label'];
            $lead = $current['text'];
            foreach ($current['subs'] as [$slug, $label]) {
                if ($slug === $ctx['sub']) {
                    $title = $label;
                }
            }
            $term = get_term_by('slug', $ctx['sub'] ?: $ctx['cat'], 'product_cat');
            if ($term && $term->description && $ctx['sub']) {
                $lead = wp_strip_all_tags($term->description);
            }
        } elseif (($_GET['orderby'] ?? '') === 'date') {
            $title = __('Újdonságok', 'mandala');
            $lead = __('Frissen érkezett darabok Nepálból és Indiából.', 'mandala');
        }
        $shop = mandala_shop_url();
        $crumbs = '<nav class="iu-breadcrumbs" aria-label="' . esc_attr__('Morzsamenü', 'mandala') . '"><ol itemscope itemtype="https://schema.org/BreadcrumbList">'
            . '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="' . esc_url(home_url('/')) . '"><span itemprop="name">' . esc_html__('Kezdőlap', 'mandala') . '</span></a><meta itemprop="position" content="1"></li>'
            . '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="' . esc_url($shop) . '"><span itemprop="name">' . esc_html__('Kínálat', 'mandala') . '</span></a><meta itemprop="position" content="2"></li>'
            . '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><span itemprop="name" aria-current="page" data-crumb>' . esc_html($title) . '</span><meta itemprop="position" content="3"></li></ol></nav>';
        $chips = '';
        if ($current) {
            $chips .= '<a class="chip" href="' . esc_url($current['url']) . '" data-cat="' . esc_attr($current['slug']) . '" aria-pressed="' . ($ctx['sub'] ? 'false' : 'true') . '">' . esc_html(sprintf(__('Minden %s', 'mandala'), mb_strtolower($current['label']))) . '</a>';
            foreach ($current['subs'] as [$slug, $label, $url]) {
                $chips .= '<a class="chip" href="' . esc_url($url) . '" data-cat="' . esc_attr($current['slug']) . '" data-sub="' . esc_attr($slug) . '" aria-pressed="' . ($ctx['sub'] === $slug ? 'true' : 'false') . '">' . esc_html($label) . '</a>';
            }
        }
        return $crumbs . '<h1 class="iu-title" data-title>' . esc_html($title) . '</h1><p data-lead' . ($lead ? '' : ' hidden') . '>' . esc_html($lead) . '</p>'
            . '<div class="chip-row" data-subnav style="margin-top:var(--space-5)">' . $chips . '</div>';
    },
]);
