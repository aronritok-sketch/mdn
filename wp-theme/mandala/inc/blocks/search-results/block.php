<?php
/**
 * mandala/search-results – keresési találatok: termékek (név, cikkszám, leírás) és
 * magazincikkek külön blokkban; üres állapot javaslatokkal.
 * (Az iu_theme a keresést bejegyzésekre szűkítheti, ezért saját lekérdezés fut.)
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/search-results', [
    'title' => 'Keresési találatok',
    'template' => function ($attributes) {
        $q = trim(get_search_query(false));
        $shop = mandala_shop_url();
        if ($q === '') {
            $chips = '';
            foreach (mandala_category_tree() as $c) {
                $chips .= '<a class="chip" href="' . esc_url($c['url']) . '">' . esc_html($c['label']) . '</a>';
            }
            return '<p class="lead">' . esc_html__('Írd be, mit keresel – terméknevet, cikkszámot vagy témát.', 'mandala') . '</p><div class="chip-row">' . $chips . '</div>';
        }
        $product_ids = [];
        if (function_exists('wc_get_products')) {
            $by_sku = wc_get_product_id_by_sku($q);
            $found = new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 24, 'fields' => 'ids', 'no_found_rows' => true]);
            $product_ids = array_values(array_unique(array_filter(array_merge([$by_sku], $found->posts))));
        }
        $posts = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 9, 'no_found_rows' => true]);
        if (!$product_ids && !$posts->have_posts()) {
            $tips = '';
            foreach ([__('hangtál', 'mandala'), __('füstölő', 'mandala'), 'mala', 'Buddha'] as $t) {
                $tips .= '<a class="chip" href="' . esc_url(add_query_arg('s', rawurlencode($t), home_url('/'))) . '">' . esc_html($t) . '</a>';
            }
            $contact = get_permalink((int) get_option('mandala_page_kapcsolat'));
            return '<div class="empty-state">' . mandala_icon('search', 'ico ico-xl') . '<h2 style="font-size:var(--fs-h3)">' . esc_html(sprintf(__('Nincs találat erre: „%s”', 'mandala'), $q)) . '</h2>'
                . '<p>' . esc_html__('Ellenőrizd a helyesírást, próbálj rövidebb vagy általánosabb kifejezést (pl. „tál” a „hangtálak” helyett).', 'mandala') . '</p>'
                . '<div class="chip-row" style="justify-content:center">' . $tips . '</div>'
                . '<div class="iu-button-group iu-button-group-center"><a class="iu-button" href="' . esc_url($shop) . '">' . esc_html__('Teljes kínálat', 'mandala') . '</a>'
                . ($contact ? '<a class="iu-button iu-button-outline" href="' . esc_url($contact) . '">' . esc_html__('Kérdezz tőlünk', 'mandala') . '</a>' : '') . '</div></div>';
        }
        $out = '';
        if ($product_ids) {
            $out .= '<h2 style="font-size:var(--fs-h3)">' . esc_html__('Termékek', 'mandala') . ' <span class="text-muted">(' . count($product_ids) . ')</span></h2><ul class="products columns-4">'
                . implode('', array_map(fn($id) => mandala_card(wc_get_product($id)), $product_ids)) . '</ul>';
        }
        if ($posts->have_posts()) {
            $out .= '<h2 style="font-size:var(--fs-h3);margin-top:var(--space-8)">' . esc_html__('Magazin', 'mandala') . ' <span class="text-muted">(' . (int) $posts->post_count . ')</span></h2><div class="iu-query iu-query-col-3">';
            while ($posts->have_posts()) {
                $posts->the_post();
                $out .= mandala_post_card(get_post());
            }
            wp_reset_postdata();
            $out .= '</div>';
        }
        return $out;
    },
]);
