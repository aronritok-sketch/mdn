<?php
/**
 * Élesítési állapot – egy olvasható jelentés a bolt készültségéről (REST, csak adminnak / boltkezelőnek):
 *
 *   GET /wp-json/mandala/v1/readiness          gyors (ellenőrzések külső kérések nélkül)
 *   GET /wp-json/mandala/v1/readiness?deep=1   teljes önellenőrzés (oldalbetöltések, gyorsítótár, feed)
 *
 * Arra való, hogy a fejlesztő / a Claude egy alkalmazásjelszóval (Felhasználók → Profil → Alkalmazásjelszavak)
 * belépés nélkül, csak olvasva átnézhesse, mi hiányzik még az élesítéshez. Titkot (jelszót, fizetési vagy
 * API-kulcsot) NEM ad ki – csak azt, hogy be van-e állítva.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/readiness', [
        'methods' => 'GET',
        'permission_callback' => fn() => current_user_can('manage_woocommerce'),
        'callback' => fn(WP_REST_Request $r) => rest_ensure_response(mandala_readiness(!empty($r['deep']))),
    ]);
});

function mandala_readiness(bool $deep = false): array
{
    global $wpdb;
    $set = fn($v) => $v !== '' && $v !== null && $v !== false && $v !== [];
    $out = ['generated' => wp_date('c'), 'site' => home_url('/'), 'theme' => wp_get_theme()->get('Version'),
        'versions' => ['wp' => get_bloginfo('version'), 'wc' => defined('WC_VERSION') ? WC_VERSION : null, 'php' => PHP_VERSION],
        'locale' => get_locale(), 'search_engines_blocked' => !get_option('blog_public'), 'permalinks' => (string) get_option('permalink_structure')];

    // A varázsló lépései (ha a telepítő bővítmény be van kapcsolva) és az önellenőrzés / őrszem
    if (function_exists('mandala_wiz_steps') && function_exists('mandala_wiz_status')) {
        foreach (mandala_wiz_steps() as $key => $step) {
            [$status, $msg] = mandala_wiz_status($key) + [1 => ''];
            $out['wizard'][$key] = ['label' => $step[1], 'required' => (bool) $step[2], 'status' => $status, 'msg' => wp_strip_all_tags((string) $msg)];
        }
    }
    if (function_exists('mandala_health_checks')) {
        $checks = $deep && function_exists('mandala_selfcheck') ? mandala_selfcheck() : mandala_health_checks(false);
        foreach ($checks as $k => $c) {
            $out['checks'][$k] = ['label' => $c['label'] ?? $k, 'status' => $c['status'] ?? '', 'msg' => wp_strip_all_tags((string) ($c['msg'] ?? ''))];
        }
    }

    // Bővítmények
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active = (array) get_option('active_plugins', []);
    foreach (get_plugins() as $file => $p) {
        $out['plugins'][] = ['name' => $p['Name'], 'version' => $p['Version'], 'active' => in_array($file, $active, true)];
    }
    if (function_exists('mandala_plugin_status')) {
        foreach (mandala_plugin_status() as $k => $p) {
            $out['required_plugins'][$k] = ['name' => $p['name'], 'active' => (bool) $p['active']];
        }
    }

    // Fizetés, szállítás, adó (kulcsok nélkül)
    if (function_exists('WC')) {
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $g) {
            $out['payments'][$id] = ['title' => $g->get_title(), 'enabled' => $g->enabled === 'yes', 'test_mode' => in_array((string) ($g->settings['testmode'] ?? $g->settings['sandbox'] ?? $g->settings['test_mode'] ?? 'no'), ['yes', '1', 'true'], true)];
        }
        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = ['id' => 0];
        foreach ($zones as $zd) {
            $zone = new WC_Shipping_Zone((int) ($zd['id'] ?? 0));
            $out['shipping'][] = ['zone' => $zone->get_id() ? $zone->get_zone_name() : '(minden más terület)', 'locations' => count($zone->get_zone_locations()),
                'methods' => array_map(fn($m) => $m->get_title() . ' [' . $m->id . ']' . ($m->is_enabled() ? '' : ' – kikapcsolva')
                    . (($c = $m->get_option('cost')) !== '' && $c !== null ? ' · ' . $c . ' Ft' : '') . (($min = $m->get_option('min_amount')) ? ' · ' . $min . ' Ft felett' : ''), array_values($zone->get_shipping_methods()))];
        }
        $out['shipping_warnings'] = mandala_shipping_zone_warnings();
        $out['tax'] = ['enabled' => get_option('woocommerce_calc_taxes') === 'yes', 'prices_include_tax' => get_option('woocommerce_prices_include_tax') === 'yes',
            'rates' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates")];
        $out['store'] = ['address' => trim(get_option('woocommerce_store_address') . ' ' . get_option('woocommerce_store_postcode') . ' ' . get_option('woocommerce_store_city')),
            'country' => get_option('woocommerce_default_country'), 'currency' => get_woocommerce_currency(), 'free_shipping_from' => function_exists('mandala_config') ? mandala_config('freeShippingFrom') : null,
            'bank_account_set' => $set(get_option('woocommerce_bacs_accounts')), 'terms_page' => (int) get_option('woocommerce_terms_page_id') > 0];
    }

    // Termékek
    $count = fn(string $status) => (int) (wp_count_posts('product')->$status ?? 0);
    $out['products'] = ['published' => $count('publish'), 'draft' => $count('draft'), 'private' => $count('private'),
        'without_image' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value = '')"),
        'without_price' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_price' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND (m.meta_value IS NULL OR m.meta_value = '')"),
        'categories' => (int) wp_count_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]),
        'queue_new' => function_exists('mandala_onboarding_count') ? mandala_onboarding_count('new') : null,
        'queue_review' => function_exists('mandala_onboarding_count') ? mandala_onboarding_count('review') : null];

    // Jogi oldalak: kitöltendő részek
    foreach (['woocommerce_terms_page_id' => 'ÁSZF', 'wp_page_for_privacy_policy' => 'Adatkezelési tájékoztató', 'mandala_page_impresszum' => 'Impresszum', 'mandala_page_akadalymentesseg' => 'Akadálymentességi nyilatkozat'] as $opt => $label) {
        $post = get_post((int) get_option($opt));
        $out['legal'][$label] = !$post || $post->post_status !== 'publish' ? 'nincs közzétett oldal'
            : (preg_match('/\[[^\]]*(kitöltendő|pontosítandó)[^\]]*\]|\[(e-mail|telefon)\]/u', $post->post_content) ? 'kitöltendő részek maradtak' : 'rendben');
    }

    // Levélküldés, kulcsok, mérés, bemutató mód
    $e = (array) get_option('mandala_mail_last_error', []);
    $out['mail'] = ['transport' => function_exists('mandala_mail_transport') ? (mandala_mail_transport() ?: 'PHP mail() – nincs SMTP bővítmény') : null,
        'from' => get_option('woocommerce_email_from_name') . ' <' . get_option('woocommerce_email_from_address') . '>',
        'last_error' => !empty($e['time']) ? wp_date('Y-m-d H:i', (int) $e['time']) . ' – ' . ($e['message'] ?? '') : null,
        'failed_24h' => function_exists('mandala_mail_table') ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . mandala_mail_table() . " WHERE status = 'failed' AND created > %s", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS))) : null]; // phpcs:ignore
    $ai = function_exists('mandala_ai_key') ? mandala_ai_key() : (defined('MANDALA_ANTHROPIC_API_KEY') ? MANDALA_ANTHROPIC_API_KEY : '');
    $ts = (array) get_option('mandala_trustedshop', []);
    $an = function_exists('mandala_analytics_settings') ? mandala_analytics_settings() : [];
    $gtm = (array) get_option('gtm4wp-options', []);
    $out['keys'] = ['claude_api' => $set($ai), 'arukereso_trustedshop' => $set($ts['key'] ?? ''), 'gtm_container' => $set($gtm['gtm-code'] ?? ''),
        'meta_pixel' => $set($an['pixel_id'] ?? ''), 'meta_capi' => ($an['capi'] ?? 'no') === 'yes' && $set($an['capi_token'] ?? '')];
    // JUTA (a web gyökerében: /juta/raw_sync.php = termékimport, /juta/elad.php = rendelések beküldése, cronból).
    // A rendeléseket olvasó külső szkript csak akkor látja az új rendeléseket, ha azok a régi (posts) táblákba is
    // bekerülnek: HPOS mellett a kompatibilitási szinkronnak be kell lennie kapcsolva.
    $hpos = function_exists('mandala_owner_hpos') ? mandala_owner_hpos() : false;
    $juta_dir = ABSPATH . 'juta';
    $out['juta'] = ['folder' => is_dir($juta_dir), 'raw_sync' => is_file($juta_dir . '/raw_sync.php'), 'elad' => is_file($juta_dir . '/elad.php'),
        'raw_sync_modified' => is_file($juta_dir . '/raw_sync.php') ? wp_date('Y-m-d H:i', (int) filemtime($juta_dir . '/raw_sync.php')) : null,
        'orders_storage' => $hpos ? 'HPOS (wc_orders)' : 'posts (wp_posts)', 'hpos_sync_to_posts' => get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'yes',
        'orders_visible_to_juta' => !$hpos || get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'yes',
        'last_product_from_import' => ($lp = $wpdb->get_var("SELECT MAX(p.post_modified) FROM {$wpdb->posts} p WHERE p.post_type IN ('product','product_variation')")) ? (string) $lp : null];
    $last = get_option('mandala_olddata_last');
    $out['migration'] = $last ? ['time' => wp_date('Y-m-d H:i', (int) $last['time']), 'counts' => $last['counts']] : null;
    $out['showcase_on'] = function_exists('mandala_showcase_on') && mandala_showcase_on();

    // Háttérfeladatok, átirányítások
    $out['cron'] = ['wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'past_due' => function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['status' => ActionScheduler_Store::STATUS_PENDING, 'date' => gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS), 'date_compare' => '<=', 'per_page' => 200], 'ids')) : null,
        'failed_24h' => function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['status' => ActionScheduler_Store::STATUS_FAILED, 'date' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), 'date_compare' => '>=', 'per_page' => 200], 'ids')) : null];
    if (defined('MANDALA_REDIRECTS_OPTION')) {
        $out['redirects'] = ['manual' => count((array) get_option(MANDALA_REDIRECTS_OPTION, []))];
        if (function_exists('mandala_404_table')) {
            $out['redirects']['top_404'] = $wpdb->get_results('SELECT path, hits FROM ' . mandala_404_table() . ' ORDER BY hits DESC LIMIT 10', ARRAY_A) ?: []; // phpcs:ignore
        }
    }
    return $out;
}

/**
 * Gyakori zónahibák: a hely nélküli zóna a WooCommerce-ben MINDEN címre illeszkedik (zóna-sorrend, majd azonosító
 * szerint az első nyer), így ha nem az utolsó, a mögötte lévő (pl. külföldi) zónák sosem érvényesülnek; egy ország
 * két zónában; a zóna nevében szereplő ország hiányzik a helyek közül.
 */
function mandala_shipping_zone_warnings(): array
{
    if (!class_exists('WC_Shipping_Zones')) {
        return [];
    }
    $warn = [];
    $zones = array_map(fn($z) => new WC_Shipping_Zone((int) $z['id']), WC_Shipping_Zones::get_zones());
    usort($zones, fn($a, $b) => [$a->get_zone_order(), $a->get_id()] <=> [$b->get_zone_order(), $b->get_id()]);
    $names = ['HU' => 'Magyarország', 'AT' => 'Ausztria', 'SK' => 'Szlovákia', 'RO' => 'Románia', 'HR' => 'Horvátország', 'SI' => 'Szlovénia', 'CZ' => 'Csehország', 'RS' => 'Szerbia', 'UA' => 'Ukrajna', 'DE' => 'Németország', 'PL' => 'Lengyelország'];
    $seen = [];
    foreach ($zones as $i => $zone) {
        $codes = array_map(fn($l) => $l->code, array_filter($zone->get_zone_locations(), fn($l) => $l->type === 'country'));
        if (!$zone->get_zone_locations() && $i < count($zones) - 1) {
            $after = implode(', ', array_map(fn($z) => '„' . $z->get_zone_name() . '”', array_slice($zones, $i + 1)));
            $warn[] = '„' . $zone->get_zone_name() . '” zónához nincs ország rendelve, ezért MINDEN címre ez érvényes – a(z) ' . $after
                . ' zóna díjai sosem jutnak érvényre (külföldi vevő is a hazai díjat fizeti). Javítás: WooCommerce → Beállítások → Szállítás → a zónához add hozzá az országot (pl. Magyarország).';
        }
        foreach ($codes as $c) {
            if (isset($seen[$c])) {
                $warn[] = ($names[$c] ?? $c) . ' két zónában is szerepel („' . $seen[$c] . '” és „' . $zone->get_zone_name() . '”) – csak az első érvényes.';
            }
            $seen[$c] ??= $zone->get_zone_name();
        }
        foreach ($names as $c => $n) {
            if (mb_stripos($zone->get_zone_name(), $n) !== false && $codes && !in_array($c, $codes, true)) {
                $warn[] = 'A „' . $zone->get_zone_name() . '” zóna nevében szerepel ' . $n . ', de a zóna országai között nincs.';
            }
        }
    }
    return $warn;
}
