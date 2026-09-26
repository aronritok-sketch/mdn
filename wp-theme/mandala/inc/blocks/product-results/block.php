<?php
/**
 * mandala/product-results – eszköztár (szűrő gomb, darabszám, rendezés), gyors szűrések,
 * aktív szűrők és a terméklista. Az első oldal szerveroldalon renderelődik (SEO, JS nélkül is).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/product-results', [
    'title' => 'Terméklista (szűrővel)',
    'category' => 'iu-woocommerce',
    'attributes' => ['perPage' => mandala_attr_def('9')],
    'fields' => [['panel' => 'Beállítások', 'fields' => [
        'perPage' => ['type' => 'text', 'label' => 'Termék oldalanként'],
    ]]],
    'template' => function ($attributes) {
        $per = max(3, (int) ($attributes['perPage'] ?? 9));
        $ctx = mandala_shop_context();
        $orderby = sanitize_key($_GET['orderby'] ?? 'menu_order');
        $args = ['status' => 'publish', 'visibility' => 'catalog', 'limit' => $per, 'paginate' => true, 'page' => max(1, (int) get_query_var('paged'))];
        $cat = $ctx['sub'] ?: $ctx['cat'];
        if ($cat) {
            $args['category'] = [$cat];
        }
        if ($ctx['q']) {
            $args['s'] = $ctx['q'];
        }
        $args += match ($orderby) {
            'date' => ['orderby' => 'date', 'order' => 'DESC'],
            'price' => ['orderby' => 'price', 'order' => 'ASC'],
            'price-desc' => ['orderby' => 'price', 'order' => 'DESC'],
            'popularity' => ['orderby' => 'popularity', 'order' => 'DESC'],
            default => ['orderby' => ['menu_order' => 'ASC', 'title' => 'ASC']],
        };
        $result = wc_get_products($args);
        $cards = implode('', array_map('mandala_card', $result->products));
        $total = (int) $result->total;
        $options = [
            'menu_order' => __('Ajánlott sorrend', 'mandala'), 'date' => __('Legújabb elöl', 'mandala'), 'popularity' => __('Legnépszerűbb', 'mandala'),
            'price' => __('Ár szerint növekvő', 'mandala'), 'price-desc' => __('Ár szerint csökkenő', 'mandala'),
        ];
        $select = '';
        foreach ($options as $value => $label) {
            $select .= '<option value="' . esc_attr($value) . '"' . selected($orderby, $value, false) . '>' . esc_html($label) . '</option>';
        }
        // JS nélkül: klasszikus lapozás.
        $pages = paginate_links(['total' => $result->max_num_pages, 'current' => $args['page'], 'type' => 'list', 'prev_text' => '‹', 'next_text' => '›']);
        return '<div class="shop-toolbar">'
            . '<button class="iu-button iu-button-outline filter-toggle" type="button" data-filters-open aria-controls="filters">' . esc_html__('Szűrők', 'mandala') . ' <span data-filter-count></span></button>'
            . '<p class="woocommerce-result-count" data-count aria-live="polite">' . esc_html(sprintf(_n('%d termék', '%d termék', $total, 'mandala'), $total)) . '</p>'
            . '<form class="woocommerce-ordering" method="get"><label class="sr-only" for="orderby">' . esc_html__('Rendezés', 'mandala') . '</label>'
            . '<select name="orderby" id="orderby" class="orderby" data-sort onchange="this.form.submit()">' . $select . '</select>'
            . ($ctx['q'] ? '<input type="hidden" name="q" value="' . esc_attr($ctx['q']) . '">' : '') . '</form></div>'
            . '<div class="quick-filters" data-quick role="group" aria-label="' . esc_attr__('Gyors szűrések', 'mandala') . '"></div>'
            . '<div class="active-filters" data-chips></div>'
            . '<ul class="products columns-3" data-results>' . $cards . '</ul>'
            . '<div data-more>' . ($pages ? '<nav class="woocommerce-pagination" aria-label="' . esc_attr__('Lapozás', 'mandala') . '">' . $pages . '</nav>' : '') . '</div>';
    },
]);
