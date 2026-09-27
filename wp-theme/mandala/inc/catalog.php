<?php
/**
 * Termékadatok: a szűrő termékindexe (gyorsítótárazva) és a „Mandala adatok” mezők
 * a termék szerkesztőben (frekvencia, súly, eredethely, használat, illusztráció).
 */

defined('ABSPATH') || exit;

const MANDALA_INDEX_KEY = 'mandala_product_index_v1'; // a régi (tranziens) index neve – csak a takarításhoz
const MANDALA_INDEX_OPTION = 'mandala_pindex';

/*
 * A teljes kínálat kompakt indexe (szűrő, kereső, ajánló, gyűjtőoldalak, chat).
 *
 * Nagy forgalomra méretezve (terheléses teszt: 3000 termék, egyszerre vásárlók):
 *  - Opcióban tárolódik, nem tranziensben: nem jár le, és ha az objektum-gyorsítótár (memcached
 *    1 MB-os korlátja) nem fogadja be, az adatbázisból jön – nem épül újra minden kérésnél.
 *  - Termékenként frissül: egy eladás / mentés csak az adott termék sorát jelöli „piszkosnak”
 *    (_mandala_index_dirty), a háttérfeladat néhány másodperc múlva csak azokat számolja újra.
 *    A látogatók addig a korábbi (pár másodperces) indexet kapják – nincs 30 mp-es újraépítés
 *    a vásárló kérésében, és nincs „csorda”, amikor egyszerre több folyamat építené.
 *  - Az admin / WP-CLI / cron mentései azonnal (a kérés végén) frissítik az indexet.
 *  - A böngésző statikus JSON fájlból kapja (uploads/mandala-index/), PHP nélkül.
 *  - Naponta teljes újraépítés (az „új” és az „érkezik” jelölés dátumfüggő).
 */

/** Az index sorai az aktuális nyelven. */
function mandala_product_index(): array
{
    $key = mandala_lang_key(MANDALA_INDEX_OPTION);
    $stored = get_option($key);
    if (!is_array($stored) || !isset($stored['rows'])) {
        $stored = mandala_index_first_build($key);
    }
    return $stored['rows'];
}

/** Az aktuális nyelvű index változat-azonosítója ('' ha még nincs index). */
function mandala_index_hash(): string
{
    return (string) get_option(mandala_lang_key(MANDALA_INDEX_OPTION) . '_hash', '');
}

/** Az index egy sora (a funkciómodulok mezőivel). */
function mandala_index_full_row(WC_Product $product): array
{
    $id = $product->get_id();
    $row = mandala_product_index_row($product);
    $row['url'] = get_permalink($id);
    $row['img'] = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') : '';
    $row['art'] = $row['img'] ? '' : mandala_art_url($product);
    $row['catLabel'] = mandala_term_name($row['sub'] ?: $row['cat']);
    $row['originLabel'] = mandala_attr($product, 'pa_eredet')[0] ?? '';
    $row['buyable'] = $product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock();
    // Nem az add_to_cart_url(): az az aktuális kérés címére mutat, a közös indexbe nem való.
    $row['addUrl'] = $row['buyable'] ? add_query_arg('add-to-cart', $id, $row['url']) : '';
    // Funkciómodulok (hangminta, előrendelés, értékelés…) további mezői.
    return apply_filters('mandala_product_index_row', $row, $product);
}

/** Sorok a megadott (vagy az összes megjeleníthető) termékhez, 200-as csomagokban előtöltött gyorsítótárral. */
function mandala_index_compute(?array $ids = null): array
{
    if (!function_exists('wc_get_products')) {
        return [];
    }
    $query = ['status' => 'publish', 'visibility' => 'catalog', 'limit' => -1, 'return' => 'ids'];
    if ($ids !== null) {
        if (!$ids) {
            return [];
        }
        $query['include'] = $ids;
    }
    $rows = [];
    foreach (array_chunk(wc_get_products($query), 200) as $chunk) {
        // Bejegyzés, meta és kifejezés-kapcsolatok egy-egy lekérdezéssel a teljes csomagra.
        _prime_post_caches($chunk, true, true);
        foreach ($chunk as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $rows[] = mandala_index_full_row($product);
            }
        }
        // Memória: a feldolgozott csomag gyorsítótára felszabadul (10 000 terméknél is elfér 256 MB-ban).
        if (count($chunk) === 200) {
            $taxes = get_object_taxonomies('product');
            foreach ($chunk as $id) {
                wp_cache_delete($id, 'posts');
                wp_cache_delete($id, 'post_meta');
                foreach ($taxes as $tax) {
                    wp_cache_delete($id, "{$tax}_relationships");
                }
            }
        }
    }
    return $rows;
}

/** Egységes sorrend (menüsorrend, majd név magyar ábécé szerint) – teljes és részleges frissítésnél is. */
function mandala_index_sort(array $rows): array
{
    static $collator;
    $collator ??= class_exists('Collator') ? new Collator(get_locale() ?: 'hu_HU') : false;
    usort($rows, fn($a, $b) => ($a['order'] <=> $b['order']) ?: ($collator ? $collator->compare($a['name'], $b['name']) : strcasecmp($a['name'], $b['name'])));
    return $rows;
}

/** Mentés opcióba + statikus JSON fájl a böngészőnek. */
function mandala_index_store(string $key, array $rows): array
{
    $rows = mandala_index_sort($rows);
    $json = wp_json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $data = ['built' => time(), 'hash' => substr(md5((string) $json), 0, 10), 'rows' => $rows];
    update_option($key, $data, false);
    // A változat azonosítója külön, kicsi (automatikusan betöltött) opcióban: a kereső, az ajánló és
    // a böngésző fájl-címe ebből tudja, friss-e a saját származtatott adata – a nagy index betöltése nélkül.
    update_option($key . '_hash', $data['hash'], true);
    mandala_index_write_file($key, (string) $json);
    delete_transient(mandala_lang_key(MANDALA_INDEX_KEY));
    do_action('mandala_index_updated', $key, $data);
    return $data;
}

function mandala_index_dir(): array
{
    $up = wp_upload_dir(null, false);
    return [trailingslashit($up['basedir']) . 'mandala-index', trailingslashit($up['baseurl']) . 'mandala-index'];
}

function mandala_index_write_file(string $key, string $json): void
{
    [$dir] = mandala_index_dir();
    if (!wp_mkdir_p($dir)) {
        return;
    }
    // Atomi csere: a böngésző soha nem kap félig írt fájlt.
    $tmp = "$dir/$key.json." . wp_generate_password(6, false);
    if (file_put_contents($tmp, $json) !== false) {
        @rename($tmp, "$dir/$key.json"); // phpcs:ignore
    }
}

/**
 * Honnan töltse a böngésző az indexet: statikus fájl (verzióval), viszonteladónak és ha a
 * fájl nem írható, a REST végpont (az nagyker árakkal számol).
 */
function mandala_products_url(): string
{
    $rest = rest_url('mandala/v1/products');
    if (mandala_is_wholesale_user()) {
        return $rest;
    }
    $key = mandala_lang_key(MANDALA_INDEX_OPTION);
    $hash = mandala_index_hash();
    [$dir, $url] = mandala_index_dir();
    if ($hash === '' || !is_file("$dir/$key.json")) {
        return $rest;
    }
    return set_url_scheme("$url/$key.json?v=$hash");
}

/** Rövid, adatbázis-szintű zár (INSERT IGNORE – atomi, több PHP folyamat között is). */
function mandala_lock(string $name, int $ttl): bool
{
    global $wpdb;
    $opt = "_mandala_lock_$name";
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $opt, (string) time()))) {
        return true;
    }
    // Elárvult zár (pl. megszakadt folyamat) átvétele.
    return (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value < %s", (string) time(), $opt, (string) (time() - $ttl)));
}
function mandala_unlock(string $name): void
{
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => "_mandala_lock_$name"]);
}

/** Első építés (új telepítés): egyszerre csak egy folyamat építi, a többi addig üres indexet kap. */
function mandala_index_first_build(string $key): array
{
    if (!mandala_lock('index', 300)) {
        return ['rows' => []];
    }
    try {
        return mandala_index_store($key, mandala_index_compute());
    } finally {
        mandala_unlock('index');
    }
}

/**
 * Azonnali (a kérés végén) vagy háttérben történő frissítés. Vásárlói kérésben és AJAX-ban (pl. CSV
 * import kötegei) mindig háttér; adminban a teljes újraépítés csak kis kínálatnál azonnali (3000
 * terméknél ~6 mp lenne egy vélemény jóváhagyása után).
 */
function mandala_index_sync_context(bool $full = false): bool
{
    $cli = (defined('WP_CLI') && WP_CLI) || wp_doing_cron();
    $editor = !wp_doing_ajax() && (is_admin() || current_user_can('edit_products'));
    $small = !$full || (int) (wp_count_posts('product')->publish ?? 0) <= 500;
    return (bool) apply_filters('mandala_index_sync', $cli || ($editor && $small), $full);
}

function mandala_index_schedule(bool $full = false): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (mandala_index_sync_context($full)) {
        add_action('shutdown', 'mandala_index_refresh', 5);
        return;
    }
    $queue = function () {
        if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_index_refresh', [], MANDALA_AS_GROUP)) {
            as_schedule_single_action(time() + 5, 'mandala_index_refresh', [], MANDALA_AS_GROUP);
        }
    };
    did_action('action_scheduler_init') ? $queue() : add_action('action_scheduler_init', $queue);
}

/** Egy termék (vagy változat szülője) sora újraszámolandó. */
function mandala_index_touch($product): void
{
    $id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
    if (!$id) {
        return;
    }
    $parent = (int) wp_get_post_parent_id($id);
    if ($parent && get_post_type($id) === 'product_variation') {
        $id = $parent;
    }
    update_post_meta($id, '_mandala_index_dirty', (string) round(microtime(true) * 1000));
    mandala_index_schedule();
}

/** Teljes újraépítés kérése (kategória, beállítás, tömeges változás) – addig a régi index szolgál ki. */
function mandala_flush_index(): void
{
    update_option('mandala_index_full', (string) round(microtime(true) * 1000), false);
    // A kategóriafa olcsó, azonnal újraépül.
    $langs = array_keys((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0])) ?: [''];
    foreach ($langs as $lang) {
        delete_transient(mandala_lang_key('mandala_cat_tree', (string) $lang));
    }
    delete_transient('mandala_cat_tree');
    mandala_index_schedule(true);
}

/** A háttérfeladat: teljes vagy részleges frissítés minden nyelvre. */
function mandala_index_refresh(): void
{
    global $wpdb;
    if (!mandala_lock('index', 300)) {
        // Épp épül: a jelölések megmaradnak, egy perc múlva újra.
        if (function_exists('as_schedule_single_action') && !as_has_scheduled_action('mandala_index_refresh', [], MANDALA_AS_GROUP)) {
            as_schedule_single_action(time() + 60, 'mandala_index_refresh', [], MANDALA_AS_GROUP);
        }
        return;
    }
    $start = (string) round(microtime(true) * 1000);
    $more = false;
    try {
        wp_cache_delete('mandala_index_full', 'options');
        $full = get_option('mandala_index_full') !== false;
        $dirty = $full ? [] : array_map('intval', $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_index_dirty' LIMIT 500"));
        $more = count($dirty) === 500;
        $langs = array_keys((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0])) ?: [''];
        $current = mandala_lang();
        foreach ($langs as $lang) {
            if ($lang !== '') {
                do_action('wpml_switch_language', $lang);
            }
            $key = mandala_lang_key(MANDALA_INDEX_OPTION, (string) $lang);
            $stored = get_option($key);
            if ($full || !is_array($stored) || !isset($stored['rows'])) {
                mandala_index_store($key, mandala_index_compute());
                continue;
            }
            if (!$dirty) {
                continue;
            }
            $fresh = [];
            foreach (mandala_index_compute($dirty) as $row) {
                $fresh[$row['id']] = $row;
            }
            $rows = [];
            foreach ($stored['rows'] as $row) {
                if (!in_array($row['id'], $dirty, true)) {
                    $rows[] = $row;
                }
            }
            mandala_index_store($key, array_merge($rows, array_values($fresh)));
        }
        if ($current !== '') {
            do_action('wpml_switch_language', $current);
        }
        // Csak a feldolgozás előtti jelölések törlődnek – a közben érkezők a következő körre maradnak.
        if ($full) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = 'mandala_index_full' AND CAST(option_value AS UNSIGNED) <= %d", $start));
            wp_cache_delete('mandala_index_full', 'options');
            wp_cache_delete('notoptions', 'options');
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_index_dirty' AND CAST(meta_value AS UNSIGNED) <= %d", $start));
        } elseif ($dirty) {
            $in = implode(',', $dirty);
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_index_dirty' AND post_id IN ($in) AND CAST(meta_value AS UNSIGNED) <= %d", $start)); // phpcs:ignore
            foreach ($dirty as $id) {
                wp_cache_delete($id, 'post_meta');
            }
        }
    } finally {
        mandala_unlock('index');
    }
    $pending = $more || get_option('mandala_index_full') !== false || $wpdb->get_var("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_index_dirty' LIMIT 1");
    if ($pending && function_exists('as_schedule_single_action') && !as_has_scheduled_action('mandala_index_refresh', [], MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + 5, 'mandala_index_refresh', [], MANDALA_AS_GROUP);
    }
}
add_action('mandala_index_refresh', 'mandala_index_refresh');

// Naponta teljes újraépítés (új / érkezik jelölés, akciók kezdete-vége).
mandala_recurring('mandala_index_daily', DAY_IN_SECONDS, fn() => (new DateTimeImmutable('tomorrow 02:10', wp_timezone()))->getTimestamp());
add_action('mandala_index_daily', 'mandala_flush_index');

// Termékmentés, készletváltozás (a JUTA-szinkron is ezen fut át): csak az adott termék sora.
foreach (['woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product',
          'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_update_product_variation'] as $hook) {
    add_action($hook, 'mandala_index_touch');
}
add_action('woocommerce_product_set_stock_status', fn($id) => mandala_index_touch((int) $id));
add_action('woocommerce_variation_set_stock_status', fn($id) => mandala_index_touch((int) $id));
// Kategória / kifejezés átnevezése, időzített akciók: teljes újraépítés.
foreach (['edited_product_cat', 'edited_term', 'woocommerce_scheduled_sales'] as $hook) {
    add_action($hook, 'mandala_flush_index');
}

/* ---------- Admin: „Mandala adatok” fül a termék adatai között ---------- */

/** A „Mandala adatok” mezői: kulcs => [címke, típus, súgó]. Típus: text, number, textarea, checkbox, date, audio. */
function mandala_product_meta_fields(): array
{
    return apply_filters('mandala_product_meta_fields', [
        '_mandala_hz' => ['Frekvencia (Hz)', 'number', 'Hangtál mért alapfrekvenciája – a szűrő csúszkája ebből dolgozik.'],
        '_mandala_suly' => ['Súly (g)', 'number', 'Hangtál súlya grammban (szűrő).'],
        '_mandala_place' => ['Eredethely', 'text', 'Pl. „Patan, Katmandu-völgy” – a termékoldal eredetkártyáján jelenik meg.'],
        '_mandala_ritual' => ['Használat és gondozás', 'textarea', 'A termékoldal „Használat és gondozás” fülének szövege.'],
        '_mandala_art' => ['Illusztráció (fotó helyett)', 'text', 'bowl, incense, mala, buddha, chime, scarf, copper … – csak ha nincs termékkép.'],
        '_mandala_tone' => ['Illusztráció tónusa', 'text', 'sand, saffron, sage, maroon, sky'],
    ]);
}

add_filter('woocommerce_product_data_tabs', function ($tabs) {
    $tabs['mandala'] = ['label' => 'Mandala adatok', 'target' => 'mandala_product_data', 'class' => [], 'priority' => 65];
    return $tabs;
});

add_action('woocommerce_product_data_panels', function () {
    echo '<div id="mandala_product_data" class="panel woocommerce_options_panel"><div class="options_group">';
    foreach (mandala_product_meta_fields() as $key => $def) {
        [$label, $type, $desc] = $def;
        $args = ['id' => $key, 'label' => $label, 'description' => $desc, 'desc_tip' => true];
        if ($type === 'select') {
            woocommerce_wp_select($args + ['options' => is_callable($def[3] ?? null) ? ($def[3])() : (array) ($def[3] ?? [])]);
        } elseif ($type === 'textarea') {
            woocommerce_wp_textarea_input($args);
        } elseif ($type === 'checkbox') {
            woocommerce_wp_checkbox($args);
        } elseif ($type === 'audio') {
            woocommerce_wp_text_input($args + ['type' => 'url', 'placeholder' => 'https://…/hang.mp3']);
            echo '<p class="form-field"><label></label><button type="button" class="button mandala-media" data-target="' . esc_attr($key) . '">Hangfájl a médiatárból</button></p>';
        } elseif ($type === 'date') {
            woocommerce_wp_text_input($args + ['type' => 'date']);
        } else {
            woocommerce_wp_text_input($args + ['type' => $type, 'custom_attributes' => $type === 'number' ? ['step' => 'any', 'min' => '0'] : []]);
        }
    }
    echo '</div></div>';
});

add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    foreach (mandala_product_meta_fields() as $key => $def) {
        $type = $def[1];
        if ($type === 'checkbox') {
            $product->update_meta_data($key, empty($_POST[$key]) ? 'no' : 'yes'); // phpcs:ignore
            continue;
        }
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification -- a WooCommerce ellenőrzi
            continue;
        }
        $raw = wp_unslash($_POST[$key]); // phpcs:ignore
        $value = match ($type) {
            'number' => $raw === '' ? '' : (string) (float) str_replace(',', '.', $raw),
            'textarea' => sanitize_textarea_field($raw),
            'audio' => esc_url_raw($raw),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '',
            'select' => sanitize_key($raw),
            default => sanitize_text_field($raw),
        };
        $product->update_meta_data($key, $value);
    }
});

/** Médiatár gomb a hangfájl mezőhöz. */
add_action('admin_footer-post.php', 'mandala_admin_media_js');
add_action('admin_footer-post-new.php', 'mandala_admin_media_js');
function mandala_admin_media_js(): void
{
    if (get_post_type() !== 'product') {
        return;
    }
    wp_enqueue_media();
    ?>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.mandala-media');
  if (!b || !window.wp || !wp.media) return;
  e.preventDefault();
  var frame = wp.media({ title: 'Hangfájl', library: { type: 'audio' }, multiple: false });
  frame.on('select', function () { document.getElementById(b.dataset.target).value = frame.state().get('selection').first().get('url'); });
  frame.open();
});
</script>
    <?php
}

/** A pa_szin kifejezések színkódja (a szűrő színmintái) – kifejezés meta: mandala_color. */
add_action('pa_szin_edit_form_fields', function ($term) {
    $color = get_term_meta($term->term_id, 'mandala_color', true);
    echo '<tr class="form-field"><th scope="row"><label for="mandala_color">Színkód</label></th><td><input name="mandala_color" id="mandala_color" type="text" value="' . esc_attr($color) . '" placeholder="#C9A24A"><p class="description">A szűrő színmintája (hex vagy CSS gradient).</p></td></tr>';
});
add_action('edited_pa_szin', function ($term_id) {
    if (isset($_POST['mandala_color'])) { // phpcs:ignore
        update_term_meta($term_id, 'mandala_color', sanitize_text_field(wp_unslash($_POST['mandala_color']))); // phpcs:ignore
        mandala_flush_index();
    }
});
