<?php
/**
 * JUTA-kapcsolat – a tárhely saját szkriptjei (web gyökér /juta/raw_sync.php: termékimport, /juta/elad.php:
 * rendelések beküldése, cronból) és a téma találkozási pontjai. Leírás: docs/JUTA.md.
 *
 * A szkriptek a WordPress betöltése (wp-load.php) után így jelezhetnek a témának:
 *
 *   define('MANDALA_JUTA_SYNC', true);           // raw_sync.php: a wp-load.php ELŐTT – a mentések JUTA-importnak számítanak
 *   require __DIR__ . '/../wp-load.php';
 *   …
 *   do_action('mandala_juta_sync_done', $stats); // raw_sync.php végén: kereső / szűrő frissítése, új termékek a sorba
 *
 *   $ids = mandala_juta_orders_to_send(['processing']);   // elad.php: beküldendő rendelések (régi, átköltöztetettek nélkül)
 *   do_action('mandala_juta_orders_sent', $sent_ids);     // elad.php végén: az elküldöttek jelölése
 *
 * Mindegyik elhagyható: nélkülük is működik minden, csak a JUTA „Akciós ár” → nagyker ár átírás (b2b.php) a
 * REST-en érkező mentésekre szűkül, a kereső indexe később frissül, és az őrszem nem lát rá a szinkronra.
 */

defined('ABSPATH') || exit;

const MANDALA_JUTA_SENT = '_mandala_juta_sent';

if (defined('MANDALA_JUTA_SYNC') && MANDALA_JUTA_SYNC) {
    add_filter('mandala_is_import_save', '__return_true');
    add_filter('mandala_is_editor_save', '__return_false');
}

/** A termékszinkron vége: kereső- és szűrőindex, az új termékek azonnal a jóváhagyási sorba, napló az őrszemnek. */
add_action('mandala_juta_sync_done', function ($stats = []) {
    if (function_exists('mandala_flush_index')) {
        mandala_flush_index();
    }
    do_action('mandala_onboarding_sweep'); // a közvetlenül adatbázisba írt új termékek is (piszkozat + sor)
    update_option('mandala_juta_sync', ['time' => time(), 'stats' => array_map('intval', array_filter((array) $stats, 'is_scalar'))], false);
});

/**
 * Beküldendő rendelések azonosítói (HPOS-tól függetlenül, a WooCommerce saját lekérdezésével): a megadott
 * állapotúak, amelyeket még nem jelöltek elküldöttnek, és nem a régi boltból átköltöztetettek (azok a régi
 * rendszerből már a JUTA-ban vannak).
 */
function mandala_juta_orders_to_send(array $statuses = ['processing'], int $limit = 200): array
{
    if (!function_exists('wc_get_orders')) {
        return [];
    }
    return array_map('intval', wc_get_orders([
        'type' => 'shop_order', 'status' => $statuses, 'limit' => $limit, 'orderby' => 'date', 'order' => 'ASC', 'return' => 'ids',
        'meta_query' => [
            ['key' => MANDALA_JUTA_SENT, 'compare' => 'NOT EXISTS'],
            ['key' => '_mandala_imported', 'compare' => 'NOT EXISTS'],
        ],
    ]));
}

add_action('mandala_juta_orders_sent', function ($ids = []) {
    $n = 0;
    foreach ((array) $ids as $id) {
        $order = wc_get_order((int) $id);
        if ($order) {
            $order->update_meta_data(MANDALA_JUTA_SENT, time());
            $order->save_meta_data();
            $n++;
        }
    }
    update_option('mandala_juta_orders', ['time' => time(), 'count' => $n], false);
});

/*
 * Figyelő a WordPress nélkül futó JUTA-szkriptekhez. A raw_sync.php a sebesség miatt nem tölti be a WordPresst,
 * közvetlenül az adatbázisba ír – így a fenti jelzések nem futnak, és a Redis objektum-gyorsítótár a régi árat /
 * készletet adhatja vissza. 10 percenként összevetjük a termékek ár-, készlet- és állapotadatainak lenyomatát az
 * előzővel: a megváltozott termékek gyorsítótárát töröljük, a keresőindexben újraszámoltatjuk, az új termékek a
 * jóváhagyási sorba kerülnek, és az őrszem látja, mikor volt utoljára szinkron. A szkripteken nem kell változtatni.
 */
const MANDALA_JUTA_WATCH_KEYS = ['_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_sku', '_manage_stock', '_backorders'];

/** Termékenkénti lenyomat (azonosító => crc32) – egy lekérdezés, a gyorsítótárat megkerülve. */
function mandala_juta_fingerprints(): array
{
    global $wpdb;
    $keys = "'" . implode("','", array_map('esc_sql', MANDALA_JUTA_WATCH_KEYS)) . "'";
    $wpdb->query('SET SESSION group_concat_max_len = 65535');
    $rows = $wpdb->get_results(
        "SELECT p.ID AS id, CRC32(CONCAT_WS('|', p.post_status, p.post_title, p.post_modified_gmt,
            (SELECT GROUP_CONCAT(m.meta_key, '=', m.meta_value ORDER BY m.meta_key SEPARATOR ';') FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ($keys)))) AS h
         FROM {$wpdb->posts} p WHERE p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('auto-draft', 'trash')",
        ARRAY_A
    ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a kulcsok konstansok
    $out = [];
    foreach ((array) $rows as $r) {
        $out[(int) $r['id']] = (int) $r['h'];
    }
    return $out;
}

/** Egy kör: a változások feldolgozása. Visszaadja a megváltozott / új / eltűnt termékek számát. */
function mandala_juta_watch(): array
{
    global $wpdb;
    $now = mandala_juta_fingerprints();
    if (!$now && $wpdb->last_error) {
        return ['error' => 1]; // hibás lekérdezésnél nem írjuk felül az előző állapotot
    }
    $was = get_option('mandala_juta_fingerprints', null);
    update_option('mandala_juta_fingerprints', $now, false);
    if (!is_array($was)) {
        return ['changed' => 0, 'new' => 0, 'gone' => 0, 'first' => 1]; // első futás: csak megjegyezzük
    }
    $changed = array_keys(array_diff_assoc(array_intersect_key($now, $was), $was));
    $new = array_keys(array_diff_key($now, $was));
    $gone = array_keys(array_diff_key($was, $now));
    $ids = array_merge($changed, $new, $gone);
    if (!$ids) {
        return ['changed' => 0, 'new' => 0, 'gone' => 0];
    }
    foreach ($ids as $id) {
        clean_post_cache($id);
        wp_cache_delete($id, 'post_meta');
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($id);
        }
        $parent = (int) wp_get_post_parent_id($id);
        if ($parent) {
            clean_post_cache($parent);
            wp_cache_delete($parent, 'post_meta');
        }
    }
    if (count($ids) > 200 || $new || $gone) {
        mandala_flush_index();
    } else {
        array_map('mandala_index_touch', $ids);
    }
    if ($new) {
        do_action('mandala_onboarding_sweep');
    }
    // Ha a szkript nem jelez (wp-load nélkül fut), a változásból tudjuk, hogy volt szinkron.
    $stats = ['changed' => count($changed), 'new' => count($new), 'gone' => count($gone)];
    update_option('mandala_juta_sync', ['time' => time(), 'stats' => $stats, 'source' => 'watch'], false);
    do_action('mandala_juta_changes', $ids);
    return $stats;
}

add_filter('cron_schedules', function ($s) {
    $s['mandala_10min'] = ['interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'Mandala: 10 percenként'];
    return $s;
});
add_action('init', function () {
    if (!wp_next_scheduled('mandala_juta_watch')) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'mandala_10min', 'mandala_juta_watch');
    }
});
add_action('mandala_juta_watch', function () {
    if (function_exists('mandala_lock') && !mandala_lock('juta_watch', 300)) {
        return;
    }
    mandala_juta_watch();
});

/** Őrszem: ha a szinkron egyszer már jelzett, és több mint 26 órája nem. */
add_filter('mandala_health_checks', function ($out) {
    $sync = (array) get_option('mandala_juta_sync', []);
    if (!empty($sync['time'])) {
        $hours = (int) floor((time() - (int) $sync['time']) / HOUR_IN_SECONDS);
        $out['juta'] = ['label' => 'JUTA termékszinkron', 'status' => $hours > 26 ? 'warn' : 'ok',
            'msg' => $hours > 26 ? sprintf('%d órája nem futott a /juta/raw_sync.php (a tárhely cronja?). Az árak és a készlet nem frissülnek.', $hours) : sprintf('Utoljára: %s.', wp_date('Y-m-d H:i', (int) $sync['time']))];
    }
    return $out;
});
