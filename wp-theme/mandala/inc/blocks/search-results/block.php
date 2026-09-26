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
        // Szerveroldali keresés (ragozás, szinonimák, cikkszám); a böngészőben a teljes motor
        // (elírás-tűrés, „500 g alatt” értelmezés) finomítja – assets/js/search-page.js.
        $product_ids = function_exists('mandala_search_products') ? array_column(mandala_search_products($q), 'id') : [];
        wp_enqueue_script_module('mandala-search-page', MANDALA_URL . '/assets/js/search-page.js', [], MANDALA_VERSION);
        $posts = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 9, 'no_found_rows' => true]);
        $shown = array_slice($product_ids, 0, 24);
        if (!$product_ids && !$posts->have_posts()) {
            $tips = '';
            foreach ([__('hangtál', 'mandala'), __('füstölő', 'mandala'), 'mala', 'Buddha'] as $t) {
                $tips .= '<a class="chip" href="' . esc_url(add_query_arg('s', rawurlencode($t), home_url('/'))) . '">' . esc_html($t) . '</a>';
            }
            $contact = get_permalink((int) get_option('mandala_page_kapcsolat'));
            return '<div class="search-page" data-search-page data-q="' . esc_attr($q) . '"><div data-search-products><div class="empty-state">' . mandala_icon('search', 'ico ico-xl') . '<h2 style="font-size:var(--fs-h3)">' . esc_html(sprintf(__('Nincs találat erre: „%s”', 'mandala'), $q)) . '</h2>'
                . '<p>' . esc_html__('Ellenőrizd a helyesírást, próbálj rövidebb vagy általánosabb kifejezést (pl. „tál” a „hangtálak” helyett).', 'mandala') . '</p>'
                . '<div class="chip-row" style="justify-content:center">' . $tips . '</div>'
                . '<div class="iu-button-group iu-button-group-center"><a class="iu-button" href="' . esc_url($shop) . '">' . esc_html__('Teljes kínálat', 'mandala') . '</a>'
                . ($contact ? '<a class="iu-button iu-button-outline" href="' . esc_url($contact) . '">' . esc_html__('Kérdezz tőlünk', 'mandala') . '</a>' : '') . '</div></div></div></div>';
        }
        $out = '<div class="search-page" data-search-page data-q="' . esc_attr($q) . '"><div data-search-products>';
        if ($product_ids) {
            $out .= '<h2 style="font-size:var(--fs-h3)">' . esc_html__('Termékek', 'mandala') . ' <span class="text-muted">(' . count($product_ids) . ')</span></h2><ul class="products columns-4">'
                . implode('', array_map(fn($id) => mandala_card(wc_get_product($id)), $shown)) . '</ul>'
                . (count($product_ids) > 24 ? '<p class="load-more"><a class="iu-button iu-button-outline" href="' . esc_url(add_query_arg('q', rawurlencode($q), mandala_shop_url())) . '">' . esc_html(sprintf(__('Mind a %d termék a kínálatban', 'mandala'), count($product_ids))) . '</a></p>' : '');
        }
        $out .= '</div>';
        if ($posts->have_posts()) {
            $out .= '<h2 style="font-size:var(--fs-h3);margin-top:var(--space-8)">' . esc_html__('Magazin', 'mandala') . ' <span class="text-muted">(' . (int) $posts->post_count . ')</span></h2><div class="iu-query iu-query-col-3">';
            while ($posts->have_posts()) {
                $posts->the_post();
                $out .= mandala_post_card(get_post());
            }
            wp_reset_postdata();
            $out .= '</div>';
        }
        return $out . '</div>';
    },
]);
