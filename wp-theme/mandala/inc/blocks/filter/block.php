<?php
/**
 * mandala/filter – saját termékszűrő (docs/SZURO.md). A panel a böngészőben épül fel
 * a termékindexből (REST, gyorsítótárazva); JS nélkül a kategórialista működik.
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/filter', [
    'title' => 'Termékszűrő',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        wp_enqueue_script_module('mandala-filter', MANDALA_URL . '/assets/js/filter.js', [], MANDALA_VERSION);
        $ctx = mandala_shop_context();
        // JS nélküli tartalék: kategóriafa linkekkel.
        $list = '<ul class="filter-list">';
        foreach (mandala_category_tree() as $cat) {
            $on = $cat['slug'] === $ctx['cat'];
            $list .= '<li><a href="' . esc_url($cat['url']) . '"' . ($on ? ' aria-current="page"' : '') . '><span>' . esc_html($cat['label']) . '</span><span class="count">' . (int) $cat['count'] . '</span></a></li>';
        }
        $list .= '</ul>';
        return '<aside class="' . mandala_classes($attributes, 'iu-woocommerce-filter', 'mandala-filter') . '" id="filters" data-filters aria-label="' . esc_attr__('Szűrők', 'mandala') . '"'
            . ' data-context="' . esc_attr(wp_json_encode($ctx)) . '">'
            . '<details class="filter-group" open><summary>' . esc_html__('Kategória', 'mandala') . mandala_icon('chevron', 'ico ico-s') . '</summary><div class="filter-group-body">' . $list . '</div></details></aside>';
    },
]);
