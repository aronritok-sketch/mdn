<?php
/**
 * Többnyelvűség (WPML + WooCommerce Multilingual) – a bővítmények nélkül semmit nem csinál.
 *
 *  - wpml-config.xml (téma gyökér): mely egyedi mezők fordíthatók, melyek másolódnak;
 *  - a téma saját oldal-hozzárendelései (mandala_page_*) az aktuális nyelv oldalára mutatnak;
 *  - a REST végpontok (termékindex, cikkek) a ?lang= paraméter nyelvén válaszolnak;
 *  - a termékindex és a kategóriafa nyelvenként külön gyorsítótárazott (helpers: mandala_lang_key);
 *  - a szűrő attribútum-értékei minden nyelven az alapnyelvi slugot használják (mandala_term_slug),
 *    így a szűrőbeállítások és a megosztott URL-ek nyelvtől függetlenek;
 *  - értékelés, eseményjegy, műhely-hozzárendelés az eredeti (alapnyelvi) bejegyzéshez kötődik.
 * A téma szövegei a „mandala” szövegtartományban vannak: WPML String Translationnel vagy
 * languages/mandala-en_US.mo fájllal fordíthatók.
 */

defined('ABSPATH') || exit;

add_action('init', function () {
    if (!defined('ICL_SITEPRESS_VERSION')) {
        return;
    }
    global $wpdb;
    $names = wp_cache_get('mandala_page_options', 'mandala');
    if (!is_array($names)) {
        $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'mandala\\_page\\_%'");
        wp_cache_set('mandala_page_options', $names, 'mandala', HOUR_IN_SECONDS);
    }
    foreach ($names as $name) {
        add_filter('option_' . $name, fn($id) => is_admin() ? $id : mandala_translate_id((int) $id));
    }
}, 5);

/** REST: a kért nyelv (a frontend a MANDALA.lang értéket küldi). */
add_filter('rest_pre_dispatch', function ($result, $server, WP_REST_Request $request) {
    if (str_starts_with($request->get_route(), '/mandala/v1/') && ($lang = sanitize_key((string) $request->get_param('lang'))) && defined('ICL_SITEPRESS_VERSION')) {
        do_action('wpml_switch_language', $lang);
    }
    return $result;
}, 10, 3);

/** A saját levelek (automatizmusok) a rendelés nyelvén – a WPML a rendeléshez menti a nyelvet. */
add_action('mandala_before_order_mail', function (WC_Order $order) {
    $lang = (string) $order->get_meta('wpml_language');
    if ($lang) {
        do_action('wpml_switch_language', $lang);
    }
});
