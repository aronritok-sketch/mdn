<?php
/**
 * Átköltöztetés a régi boltból: a WooCommerce CSV-importtal érkezett termékek közül a régi boltban
 * közzétettek élesítése egy lépésben (Termékek → Új termékek → Beállítások).
 *
 * Miért kell: ha az import idején a jóváhagyási sor az importot még „új, JUTA-s termékként” kezelte,
 * minden termék piszkozat lett és a sorba került (és a sor automatikus Claude-javaslatai fogyasztották
 * a kreditet). A régi bolt exportja (CSV) megmondja, melyik termék volt közzétéve: a cikkszámuk alapján
 * ezek élesek lesznek, a többi (a régi bolt piszkozatai) a sorban marad – kérésre Claude-hívás nélkül.
 */

defined('ABSPATH') || exit;

const MANDALA_OLDSHOP_OPTION = 'mandala_oldshop';
const MANDALA_OLDSHOP_STEP = 150; // termék / kérés (időkorlát)

function mandala_oldshop_url(array $args = []): string
{
    return add_query_arg($args, admin_url('edit.php?post_type=product&page=mandala-onboarding&tab=settings'));
}

/**
 * A CSV-ből a közzétett termékek cikkszámai. Angol (SKU, Published) és magyar (Cikkszám, Közzétéve)
 * fejléc is jó; ha nincs „közzétéve” oszlop, minden sor számít.
 * @return string[]|WP_Error
 */
function mandala_oldshop_parse_csv(string $file)
{
    $fh = fopen($file, 'r');
    if (!$fh) {
        return new WP_Error('mandala_oldshop', 'A fájl nem olvasható.');
    }
    $head = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$head) {
        fclose($fh);
        return new WP_Error('mandala_oldshop', 'Üres a fájl.');
    }
    $head = array_map(fn($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $head);
    $sku = array_search('sku', $head, true);
    $sku = $sku === false ? array_search('cikkszám', $head, true) : $sku;
    $pub = array_search('published', $head, true);
    $pub = $pub === false ? array_search('közzétéve', $head, true) : $pub;
    if ($sku === false) {
        fclose($fh);
        return new WP_Error('mandala_oldshop', 'Nincs „SKU” (cikkszám) oszlop a fájlban – a régi bolt termékexportját töltsd fel.');
    }
    $out = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $code = trim((string) ($row[$sku] ?? ''));
        if ($code !== '' && ($pub === false || trim((string) ($row[$pub] ?? '')) === '1')) {
            $out[] = $code;
        }
    }
    fclose($fh);
    return array_values(array_unique($out));
}

add_action('admin_post_mandala_oldshop_start', function () {
    if (!current_user_can('edit_products') || !check_admin_referer('mandala_oldshop')) {
        wp_die('', '', ['response' => 403]);
    }
    $file = $_FILES['mandala_oldshop_csv']['tmp_name'] ?? ''; // phpcs:ignore
    $skus = $file && is_uploaded_file($file) ? mandala_oldshop_parse_csv($file) : new WP_Error('mandala_oldshop', 'Válassz ki egy CSV-fájlt.');
    if (is_wp_error($skus)) {
        set_transient('mandala_oldshop_msg', ['error', $skus->get_error_message()], 300);
        wp_safe_redirect(mandala_oldshop_url());
        exit;
    }
    update_option(MANDALA_OLDSHOP_OPTION, [
        'skus' => $skus, 'total' => count($skus), 'done' => 0, 'published' => 0, 'already' => 0, 'missing' => 0,
        'skip_ai' => !empty($_POST['mandala_oldshop_skip_ai']), 'started' => time(), // phpcs:ignore
    ], false);
    wp_safe_redirect(mandala_oldshop_url(['oldshop' => 'run']));
    exit;
});

/** Egy köteg feldolgozása. Visszaad: az állapot. */
function mandala_oldshop_step(): array
{
    $st = (array) get_option(MANDALA_OLDSHOP_OPTION, []);
    if (empty($st['skus'])) {
        return $st;
    }
    $batch = array_splice($st['skus'], 0, MANDALA_OLDSHOP_STEP);
    wp_defer_term_counting(true);
    foreach ($batch as $sku) {
        $id = (int) wc_get_product_id_by_sku($sku);
        if (!$id) {
            $st['missing']++;
        } elseif (mandala_onboarding_state($id) === 'new' || get_post_status($id) !== 'publish') {
            $GLOBALS['mandala_onboarding_approving'] = $id;
            wp_update_post(['ID' => $id, 'post_status' => 'publish']);
            unset($GLOBALS['mandala_onboarding_approving']);
            mandala_onboarding_complete($id);
            $st['published']++;
        } else {
            $st['already']++;
        }
        $st['done']++;
    }
    wp_defer_term_counting(false);
    if (!$st['skus'] && !empty($st['skip_ai'])) {
        // A sorban maradt (a régi boltban sem közzétett) termékeknél ne induljon automatikus Claude-hívás.
        global $wpdb;
        $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
            SELECT pm.post_id, '_mandala_ai_skip', '1' FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = pm.post_id AND sk.meta_key = '_mandala_ai_skip'
            WHERE pm.meta_key = '_mandala_onboarding' AND pm.meta_value = 'new' AND sk.post_id IS NULL"); // phpcs:ignore
        $st['ai_skipped'] = (int) $wpdb->rows_affected;
    }
    if (!$st['skus']) {
        $st['merged'] = mandala_oldshop_merge_categories();
        $st['finished'] = time();
        wp_cache_delete('counts', 'mandala_onboarding');
        if (function_exists('mandala_flush_index')) {
            mandala_flush_index();
        }
    }
    update_option(MANDALA_OLDSHOP_OPTION, $st, false);
    return $st;
}

add_action('admin_post_mandala_oldshop_step', function () {
    if (!current_user_can('edit_products') || !check_admin_referer('mandala_oldshop_step')) {
        wp_die('', '', ['response' => 403]);
    }
    $st = mandala_oldshop_step();
    wp_safe_redirect(mandala_oldshop_url(['oldshop' => empty($st['skus']) ? 'done' : 'run']));
    exit;
});

/**
 * Az import név szerint keresi a kategóriát: ahol a régi bolt neve eltér a téma kategóriájának nevétől
 * (pl. „Ruházat és kiegészítők” ↔ „Ruházat és ékszer”), új kategória jön létre „-2” végű címmel, a téma
 * kategóriája pedig üres marad. Ezeket összevonjuk: a termékek a téma kategóriájába kerülnek (a régi
 * URL ugyanaz marad, hiszen a régi bolt címe volt az alap), a másodpéldány törlődik. AI nélkül, ingyen.
 * Visszaad: az összevont kategóriák száma.
 */
function mandala_oldshop_merge_categories(): int
{
    $merged = 0;
    $map = []; // régi term_id => cél term_id
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (is_wp_error($terms)) {
        return 0;
    }
    // Szülők előbb, hogy az alkategóriák szülőjét már az összevont kategóriához mérjük.
    usort($terms, fn($a, $b) => count(get_ancestors($a->term_id, 'product_cat')) <=> count(get_ancestors($b->term_id, 'product_cat')));
    foreach ($terms as $term) {
        if (!preg_match('/^(.+)-(\d+)$/', $term->slug, $m)) {
            continue;
        }
        $base = get_term_by('slug', $m[1], 'product_cat');
        $parent = $map[$term->parent] ?? $term->parent;
        if (!$base || $base->term_id === $term->term_id || (int) $base->parent !== (int) $parent) {
            continue;
        }
        $ids = get_objects_in_term($term->term_id, 'product_cat');
        foreach (is_wp_error($ids) ? [] : $ids as $pid) {
            wp_add_object_terms((int) $pid, (int) $base->term_id, 'product_cat');
            wp_remove_object_terms((int) $pid, (int) $term->term_id, 'product_cat');
        }
        foreach (get_terms(['taxonomy' => 'product_cat', 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids']) ?: [] as $child) {
            wp_update_term((int) $child, 'product_cat', ['parent' => (int) $base->term_id]);
        }
        $map[$term->term_id] = $base->term_id;
        wp_delete_term($term->term_id, 'product_cat');
        $merged++;
    }
    if ($merged) {
        delete_transient(function_exists('mandala_lang_key') ? mandala_lang_key('mandala_cat_tree') : 'mandala_cat_tree');
    }
    return $merged;
}

/* ---------- Felület: Új termékek → Beállítások ---------- */

add_action('mandala_onboarding_settings_top', function () {
    $st = (array) get_option(MANDALA_OLDSHOP_OPTION, []);
    $msg = get_transient('mandala_oldshop_msg');
    if ($msg) {
        delete_transient('mandala_oldshop_msg');
        echo '<div class="notice notice-' . esc_attr($msg[0]) . ' inline"><p>' . esc_html($msg[1]) . '</p></div>';
    }
    echo '<div class="card" style="max-width:900px;margin:16px 0 24px"><h2 id="regi-bolt">Átköltöztetés a régi boltból</h2>';
    if (!empty($st['skus'])) {
        $pct = $st['total'] ? (int) round($st['done'] / $st['total'] * 100) : 0;
        echo '<p><strong>Folyamatban: ' . (int) $st['done'] . ' / ' . (int) $st['total'] . ' (' . $pct . '%)</strong> – ne zárd be az oldalt, magától folytatja.</p>'
            . '<progress max="100" value="' . $pct . '" style="width:100%"></progress>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mandala-oldshop-step"><input type="hidden" name="action" value="mandala_oldshop_step">';
        wp_nonce_field('mandala_oldshop_step');
        echo '<p><button class="button">Folytatás</button></p></form>'
            . '<script>setTimeout(function(){document.getElementById("mandala-oldshop-step").submit();},400);</script></div>';
        return;
    }
    if (!empty($st['finished'])) {
        echo '<div class="notice notice-success inline"><p><strong>Kész:</strong> ' . (int) $st['published'] . ' termék élesítve'
            . ($st['already'] ? ', ' . (int) $st['already'] . ' már élő volt' : '')
            . ($st['missing'] ? ', ' . (int) $st['missing'] . ' cikkszám nem található a boltban' : '')
            . (!empty($st['merged']) ? '; ' . (int) $st['merged'] . ' dupla kategória összevonva (pl. a régi „Ruházat és kiegészítők” a téma „Ruházat és ékszer” kategóriájába)' : '')
            . (isset($st['ai_skipped']) ? '; a sorban maradt ' . (int) $st['ai_skipped'] . ' terméknél nem indul automatikus Claude-hívás' : '') . '.</p></div>';
    }
    echo '<p>Ha a régi bolt termékei a WooCommerce CSV-importtal érkeztek, és mind ide, a jóváhagyási sorba kerültek: töltsd fel ugyanazt a CSV-t, amelyet importáltál (a régi bolt aktív termékei). '
        . 'A benne közzétettként (Published = 1) szereplő termékek a cikkszámuk alapján azonnal élesek lesznek, úgy, ahogy a régi boltban voltak – kategória- és szűrő-átsorolás nélkül (az a „Claude migráció” fülön, külön indítható).</p>'
        . '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mandala_oldshop_start">';
    wp_nonce_field('mandala_oldshop');
    echo '<p><input type="file" name="mandala_oldshop_csv" accept=".csv,text/csv" required></p>'
        . '<p><label><input type="checkbox" name="mandala_oldshop_skip_ai" value="1" checked> A sorban maradó termékeknél (a régi bolt piszkozatai) ne kérjen automatikusan Claude-javaslatot és leírást – így nem fogy a kredit rájuk. Egyenként a termékszerkesztőben továbbra is kérhető.</label></p>';
    submit_button('Közzétett termékek élesítése', 'primary', 'submit', false);
    echo '</form></div>';
});

// Az „Új, élesítésre vár” fülön: ha nagyon sok termék vár, figyelmeztetés az átköltöztetésre.
add_action('mandala_onboarding_new_top', function ($total) {
    $st = (array) get_option(MANDALA_OLDSHOP_OPTION, []);
    if ((int) $total < 200 || !empty($st['finished'])) {
        return;
    }
    echo '<div class="notice notice-warning inline"><p><strong>' . (int) $total . ' termék vár élesítésre.</strong> Ha ezek a régi boltból, CSV-importtal érkeztek, '
        . '<a href="' . esc_url(mandala_oldshop_url() . '#regi-bolt') . '">a régi bolt közzétett termékei egy lépésben élesíthetők</a>.</p></div>';
});
