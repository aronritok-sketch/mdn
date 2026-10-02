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
